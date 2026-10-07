<?php

namespace App\Services\Genieacs;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * GenieACS API Client Service
 *
 * Berkomunikasi dengan GenieACS NBI (North Bound Interface) via HTTP port 7557.
 * Diport dari dashboard GACS berbasis Laravel pada 22 Sep 2026 tanpa perubahan
 * logika, selain dukungan skema https pada baseUrl.
 */
class GenieACSService
{
    private const CONNECTION_REQUEST_TIMEOUT_MS = 3000;

    private string $host;

    private int $port;

    private ?string $username;

    private ?string $password;

    private string $scheme;

    private string $baseUrl;

    private int $requestTimeoutSeconds;

    private int $connectTimeoutSeconds;

    public function __construct(
        string $host,
        int $port = 7557,
        ?string $username = null,
        ?string $password = null,
        int $requestTimeoutSeconds = 300,
        int $connectTimeoutSeconds = 30
    ) {
        // Host boleh ditulis dengan skema ("https://acs.internal"). Lewat terowongan
        // WireGuard http polos sudah memadai karena jalurnya terenkripsi; https
        // dipakai bila NBI berada di balik reverse proxy TLS.
        $normalizedHost = (string) preg_replace('#^https?://#i', '', trim($host));
        $normalizedHost = rtrim($normalizedHost, '/');

        $this->scheme = preg_match('#^https://#i', trim($host)) === 1 ? 'https' : 'http';
        $this->host = $normalizedHost;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->baseUrl = "{$this->scheme}://{$normalizedHost}:{$port}";
        $this->requestTimeoutSeconds = max(1, $requestTimeoutSeconds);
        $this->connectTimeoutSeconds = max(1, $connectTimeoutSeconds);
    }

    /**
     * HTTP client dengan auth dan timeout dikonfigurasi.
     */
    private function client(): PendingRequest
    {
        $client = Http::timeout($this->requestTimeoutSeconds)
            ->connectTimeout($this->connectTimeoutSeconds);

        if ($this->username) {
            $client = $client->withBasicAuth($this->username, $this->password ?? '');
        }

        return $client;
    }

    // =========================================================================
    // CONNECTION TEST
    // =========================================================================

    /**
     * Test koneksi ke GenieACS.
     * GET /devices?limit=1
     */
    public function testConnection(): bool
    {
        try {
            return $this->client()
                ->get("{$this->baseUrl}/devices", ['limit' => 1])
                ->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Ambil role user dari GenieACS /users endpoint.
     */
    public function getUserRole(): ?string
    {
        try {
            $response = $this->client()->get("{$this->baseUrl}/users");

            if ($response->successful() && $this->username) {
                foreach ($response->json() as $user) {
                    if (($user['_id'] ?? null) === $this->username) {
                        $roles = $user['roles'] ?? $user['role'] ?? null;
                        if (is_array($roles)) {
                            return $roles[0] ?? 'user';
                        }

                        return $roles ?? 'user';
                    }
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /**
     * Ambil script provision GenieACS.
     *
     * GET /provisions/{name}
     *
     * @return array{success:bool,http_code?:int,script?:string,error?:string}
     */
    public function getProvisionScript(string $provisionName): array
    {
        try {
            $response = $this->client()
                ->get("{$this->baseUrl}/provisions/".rawurlencode($provisionName));

            if (! $response->successful()) {
                return $this->findProvisionScriptFromList($provisionName, $response->status());
            }

            $payload = $response->json();
            $script = is_array($payload)
                ? (string) ($payload['script'] ?? '')
                : (string) $response->body();

            if ($script === '') {
                return $this->findProvisionScriptFromList($provisionName, $response->status());
            }

            return [
                'success' => true,
                'http_code' => $response->status(),
                'script' => $script,
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Simpan ulang script provision GenieACS.
     *
     * PUT /provisions/{name}
     *
     * @return array{success:bool,http_code?:int,error?:string}
     */
    public function updateProvisionScript(string $provisionName, string $script): array
    {
        try {
            $response = $this->client()
                ->withBody($script, 'text/plain')
                ->put("{$this->baseUrl}/provisions/".rawurlencode($provisionName));

            return [
                'success' => $response->successful(),
                'http_code' => $response->status(),
                'error' => $response->successful() ? null : __('acs.provision_update_failed'),
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public static function extractInformIntervalFromProvisionScript(string $script): ?int
    {
        if (preg_match('/\bconst\s+informInterval\s*=\s*(\d+)\b/', $script, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    public static function replaceInformIntervalInProvisionScript(string $script, int $informInterval): ?string
    {
        $nextInterval = max(60, min(86400, $informInterval));
        $count = 0;

        $replaced = preg_replace(
            '/(\bconst\s+informInterval\s*=\s*)\d+(\s*;[^\r\n]*)?/',
            '${1}'.$nextInterval.'$2',
            $script,
            1,
            $count
        );

        if ($count !== 1 || ! is_string($replaced)) {
            return null;
        }

        return $replaced;
    }

    /**
     * Sinkronkan interval periodic inform pada provision yang aktif.
     *
     * @return array{success:bool,updated:bool,provision_name:string,before:?int,after:int,error?:string,http_code?:int}
     */
    public function syncInformProvisionInterval(string $provisionName, int $informInterval): array
    {
        $provisionName = trim($provisionName);
        $informInterval = max(60, min(86400, $informInterval));

        if ($provisionName === '') {
            return [
                'success' => false,
                'updated' => false,
                'provision_name' => '',
                'before' => null,
                'after' => $informInterval,
                'error' => __('acs.provision_name_empty'),
            ];
        }

        $current = $this->getProvisionScript($provisionName);
        if (! ($current['success'] ?? false)) {
            return [
                'success' => false,
                'updated' => false,
                'provision_name' => $provisionName,
                'before' => null,
                'after' => $informInterval,
                'error' => (string) ($current['error'] ?? __('acs.provision_unreadable')),
                'http_code' => $current['http_code'] ?? null,
            ];
        }

        $script = (string) ($current['script'] ?? '');
        $currentInterval = self::extractInformIntervalFromProvisionScript($script);

        if ($currentInterval === null) {
            return [
                'success' => false,
                'updated' => false,
                'provision_name' => $provisionName,
                'before' => null,
                'after' => $informInterval,
                'error' => __('acs.provision_interval_line_missing'),
            ];
        }

        if ($currentInterval === $informInterval) {
            return [
                'success' => true,
                'updated' => false,
                'provision_name' => $provisionName,
                'before' => $currentInterval,
                'after' => $informInterval,
            ];
        }

        $updatedScript = self::replaceInformIntervalInProvisionScript($script, $informInterval);
        if ($updatedScript === null) {
            return [
                'success' => false,
                'updated' => false,
                'provision_name' => $provisionName,
                'before' => $currentInterval,
                'after' => $informInterval,
                'error' => __('acs.provision_interval_replace_failed'),
            ];
        }

        $result = $this->updateProvisionScript($provisionName, $updatedScript);

        return [
            'success' => (bool) ($result['success'] ?? false),
            'updated' => (bool) ($result['success'] ?? false),
            'provision_name' => $provisionName,
            'before' => $currentInterval,
            'after' => $informInterval,
            'error' => $result['error'] ?? null,
            'http_code' => $result['http_code'] ?? null,
        ];
    }

    /**
     * @return array{success:bool,http_code?:int,script?:string,error?:string}
     */
    private function findProvisionScriptFromList(string $provisionName, ?int $initialStatus = null): array
    {
        try {
            $response = $this->client()->get("{$this->baseUrl}/provisions/");

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'http_code' => $response->status(),
                    'error' => __('acs.provision_not_found'),
                ];
            }

            $payload = $response->json();
            if (! is_array($payload)) {
                return [
                    'success' => false,
                    'http_code' => $response->status(),
                    'error' => __('acs.provision_list_invalid'),
                ];
            }

            foreach ($payload as $item) {
                if (! is_array($item) || (string) ($item['_id'] ?? '') !== $provisionName) {
                    continue;
                }

                return [
                    'success' => true,
                    'http_code' => $initialStatus ?? $response->status(),
                    'script' => (string) ($item['script'] ?? ''),
                ];
            }

            return [
                'success' => false,
                'http_code' => $initialStatus ?? $response->status(),
                'error' => __('acs.provision_not_found'),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'http_code' => $initialStatus,
                'error' => $e->getMessage(),
            ];
        }
    }

    // =========================================================================
    // DEVICE CRUD
    // =========================================================================

    /**
     * Ambil semua device.
     * GET /devices/?query=...&limit=...&skip=...
     *
     * @param  array  $query  MongoDB query filter (contoh: ['_tags' => 'onu'])
     * @param  int  $limit  0 = no limit
     * @param  int  $skip  Offset untuk pagination
     */
    public function getDevices(array $query = [], int $limit = 0, int $skip = 0, string $projection = ''): array
    {
        $params = [];
        if (! empty($query)) {
            $params['query'] = json_encode($query);
        }
        if ($limit > 0) {
            $params['limit'] = $limit;
        }
        if ($skip > 0) {
            $params['skip'] = $skip;
        }
        if (! empty($projection)) {
            $params['projection'] = $projection;
        }

        try {
            $response = $this->client()->get("{$this->baseUrl}/devices/", $params);

            return ['success' => $response->successful(), 'data' => $response->json() ?? []];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'data' => []];
        }
    }

    /**
     * Ambil 1 device by ID.
     * GET /devices/?query={"_id":"..."}
     */
    public function getDevice(string $deviceId): array
    {
        try {
            $response = $this->client()->get("{$this->baseUrl}/devices/", [
                'query' => json_encode(['_id' => $deviceId]),
            ]);

            $data = $response->json();

            if ($response->successful() && ! empty($data)) {
                return ['success' => true, 'data' => $data[0]];
            }

            return ['success' => false, 'error' => 'Device not found'];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Hapus device dari GenieACS.
     * DELETE /devices/{id}
     */
    public function deleteDevice(string $deviceId): bool
    {
        try {
            return $this->client()
                ->delete("{$this->baseUrl}/devices/".rawurlencode($deviceId))
                ->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    // =========================================================================
    // TASK EXECUTION
    // =========================================================================

    /**
     * Summon device + refresh commonly used trees so detail data updates immediately.
     *
     * The final task carries the connection request to ensure every refresh task is
     * queued before the device is asked to reconnect to ACS.
     */
    public function summonDevice(string $deviceId): array
    {
        return $this->postTasks($deviceId, [
            [
                'name' => 'refreshObject',
                'objectName' => 'VirtualParameters',
            ],
            [
                'name' => 'refreshObject',
                'objectName' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration',
            ],
            [
                'name' => 'refreshObject',
                'objectName' => 'InternetGatewayDevice.LANDevice.1.Hosts',
            ],
            [
                'name' => 'refreshObject',
                'objectName' => 'InternetGatewayDevice.LANDevice.1.LANHostConfigManagement',
            ],
            [
                'name' => 'refreshObject',
                'objectName' => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice',
            ],
        ], timeout: self::CONNECTION_REQUEST_TIMEOUT_MS, connectionRequest: true);
    }

    /**
     * Reboot device.
     * POST /devices/{id}/tasks?timeout={timeout}&connection_request
     * Body: {"name":"reboot"}
     */
    public function rebootDevice(string $deviceId): array
    {
        return $this->postTask($deviceId, ['name' => 'reboot'], timeout: self::CONNECTION_REQUEST_TIMEOUT_MS, connectionRequest: true);
    }

    /**
     * Set parameter values pada device.
     * POST /devices/{id}/tasks?timeout={t}&connection_request
     * Body: {"name":"setParameterValues","parameterValues":[...]}
     *
     * @param  array  $parameters  Format: [["TR-069.Path.Name", "value", "xsd:string"], ...]
     */
    public function setParameterValues(string $deviceId, array $parameters, int $timeout = 3000): array
    {
        return $this->postTask($deviceId, [
            'name' => 'setParameterValues',
            'parameterValues' => $parameters,
        ], timeout: $timeout, connectionRequest: true);
    }

    /**
     * Refresh 1 object/parameter tree dari device.
     * POST /devices/{id}/tasks?timeout={timeout}&connection_request
     * Body: {"name":"refreshObject","objectName":"..."}
     */
    public function refreshObject(string $deviceId, string $objectName): array
    {
        return $this->postTask($deviceId, [
            'name' => 'refreshObject',
            'objectName' => $objectName,
        ], timeout: self::CONNECTION_REQUEST_TIMEOUT_MS, connectionRequest: true);
    }

    /**
     * Set WiFi SSID + Password + Security Mode.
     *
     * @param  string  $securityMode  WPA2PSK | WPAPSK | WPA2PSKWPAPSK | None
     * @param  int  $wlanIndex  1, 2, 3, atau 4
     */
    public function setWiFiConfig(
        string $deviceId,
        string $ssid,
        string $password = '',
        int $wlanIndex = 1,
        string $securityMode = 'WPA2PSK'
    ): array {
        $securityMode = trim($securityMode) !== '' ? trim($securityMode) : 'WPA2PSK';
        $base = "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$wlanIndex}";

        $params = [
            ["{$base}.SSID", $ssid, 'xsd:string'],
        ];

        $params = array_merge($params, $this->buildWiFiSecurityParameterValues(
            $base,
            $securityMode,
            $password
        ));

        return $this->setParameterValues($deviceId, $params);
    }

    public function setWiFiConfigForDevice(
        string $deviceId,
        array $device,
        string $ssid,
        string $password = '',
        int $wlanIndex = 1,
        string $securityMode = 'WPA2PSK'
    ): array {
        $wlanIndex = max(1, $wlanIndex);
        $securityMode = trim($securityMode) !== '' ? trim($securityMode) : 'WPA2PSK';

        $tr098 = $this->resolveTr098WifiNode($device, $wlanIndex);
        if ($tr098 !== null) {
            $base = $tr098['base'];
            $node = $tr098['node'];
            $params = [
                ["{$base}.SSID", $ssid, 'xsd:string'],
            ];
            $params = array_merge(
                $params,
                $this->buildTr098WiFiSecurityParameterValues($base, $node, $securityMode, $password)
            );

            return $this->setParameterValues($deviceId, $params);
        }

        $tr181 = $this->resolveTr181WifiNode($device, $wlanIndex);
        if ($tr181 !== null) {
            $params = [
                ["{$tr181['ssid_base']}.SSID", $ssid, 'xsd:string'],
            ];
            $params = array_merge(
                $params,
                $this->buildTr181WiFiSecurityParameterValues(
                    $tr181['ap_base'],
                    $tr181['security_node'],
                    $securityMode,
                    $password
                )
            );

            return $this->setParameterValues($deviceId, $params);
        }

        return $this->setWiFiConfig($deviceId, $ssid, $password, $wlanIndex, $securityMode);
    }

    /**
     * Enable atau disable WiFi interface.
     *
     * @param  int  $wlanIndex  1, 2, 3, atau 4
     * @param  bool  $enabled  true = enable, false = disable
     */
    public function setWiFiEnabled(string $deviceId, int $wlanIndex, bool $enabled): array
    {
        $base = "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$wlanIndex}";

        return $this->setParameterValues($deviceId, [
            ["{$base}.Enable", $enabled ? 'true' : 'false', 'xsd:boolean'],
        ]);
    }

    public function setWiFiEnabledForDevice(string $deviceId, array $device, int $wlanIndex, bool $enabled): array
    {
        $wlanIndex = max(1, $wlanIndex);

        $tr098 = $this->resolveTr098WifiNode($device, $wlanIndex);
        if ($tr098 !== null) {
            return $this->setParameterValues($deviceId, [
                ["{$tr098['base']}.Enable", $enabled ? 'true' : 'false', 'xsd:boolean'],
            ]);
        }

        $tr181 = $this->resolveTr181WifiNode($device, $wlanIndex);
        if ($tr181 !== null) {
            $params = [];
            if ($this->nodePathExists($tr181['ssid_node'], 'Enable')) {
                $params[] = ["{$tr181['ssid_base']}.Enable", $enabled ? 'true' : 'false', 'xsd:boolean'];
            }
            if ($this->nodePathExists($tr181['ap_node'], 'Enable')) {
                $params[] = ["{$tr181['ap_base']}.Enable", $enabled ? 'true' : 'false', 'xsd:boolean'];
            }
            if ($params !== []) {
                return $this->setParameterValues($deviceId, $params);
            }
        }

        return $this->setWiFiEnabled($deviceId, $wlanIndex, $enabled);
    }

    // =========================================================================
    // TAGS
    // =========================================================================

    /**
     * Tambah tag ke device.
     * POST /devices/{id}/tags/{tag}
     */
    public function addTag(string $deviceId, string $tag): bool
    {
        try {
            return $this->client()
                ->post("{$this->baseUrl}/devices/".rawurlencode($deviceId)."/tags/{$tag}")
                ->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Hapus tag dari device.
     * DELETE /devices/{id}/tags/{tag}
     */
    public function removeTag(string $deviceId, string $tag): bool
    {
        try {
            return $this->client()
                ->delete("{$this->baseUrl}/devices/".rawurlencode($deviceId)."/tags/{$tag}")
                ->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Internal: POST task ke GenieACS.
     *
     * @param  string  $deviceId  Device ID (akan di-rawurlencode)
     * @param  array  $body  Task body JSON
     * @param  int  $timeout  Timeout dalam ms (hanya dipakai jika connectionRequest=true)
     * @param  bool  $connectionRequest  Tambah ?timeout=...&connection_request ke URL
     */
    private function postTask(string $deviceId, array $body, int $timeout = 0, bool $connectionRequest = false): array
    {
        $encodedId = rawurlencode($deviceId);
        $url = "{$this->baseUrl}/devices/{$encodedId}/tasks";

        if ($connectionRequest) {
            $url .= "?timeout={$timeout}&connection_request";
        }

        try {
            $response = $this->client()->post($url, $body);

            return ['success' => $response->successful(), 'http_code' => $response->status()];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Internal: POST multiple tasks in order. Only the last task triggers the
     * connection request so all refreshes are already queued when the device calls in.
     *
     * @param  array<int, array<string, mixed>>  $bodies
     */
    private function postTasks(string $deviceId, array $bodies, int $timeout = 0, bool $connectionRequest = false): array
    {
        if ($bodies === []) {
            return ['success' => false, 'error' => 'No tasks to queue'];
        }

        $lastIndex = array_key_last($bodies);
        $lastResult = ['success' => true, 'http_code' => 0];

        foreach ($bodies as $index => $body) {
            $lastResult = $this->postTask(
                $deviceId,
                $body,
                $connectionRequest && $index === $lastIndex ? $timeout : 0,
                $connectionRequest && $index === $lastIndex
            );

            if (! ($lastResult['success'] ?? false)) {
                return $lastResult;
            }
        }

        return $lastResult + ['tasks_queued' => count($bodies)];
    }

    /**
     * Build TR-098 WiFi security fields with vendor-tolerant parameter coverage.
     *
     * Some ONUs expect `IEEE11i*` fields for WPA2, some still read legacy
     * `WPA*` fields, and others only apply passphrases through the nested
     * `PreSharedKey.1.KeyPassphrase` path.
     *
     * @return array<int, array{0:string,1:string,2:string}>
     */
    private function buildWiFiSecurityParameterValues(string $base, string $securityMode, string $password): array
    {
        $normalizedMode = strtoupper($securityMode);

        return match ($normalizedMode) {
            'NONE' => [
                ["{$base}.BeaconType", 'Basic', 'xsd:string'],
                ["{$base}.BasicAuthenticationMode", 'None', 'xsd:string'],
                ["{$base}.BasicEncryptionModes", 'None', 'xsd:string'],
                ["{$base}.KeyPassphrase", '', 'xsd:string'],
                ["{$base}.PreSharedKey.1.KeyPassphrase", '', 'xsd:string'],
            ],
            'WPAPSK' => [
                ["{$base}.BeaconType", 'WPA', 'xsd:string'],
                ["{$base}.KeyPassphrase", $password, 'xsd:string'],
                ["{$base}.PreSharedKey.1.KeyPassphrase", $password, 'xsd:string'],
                ["{$base}.WPAAuthenticationMode", 'PSKAuthentication', 'xsd:string'],
                ["{$base}.WPAEncryptionModes", 'TKIPEncryption', 'xsd:string'],
            ],
            'WPA2PSKWPAPSK' => [
                ["{$base}.BeaconType", 'WPAand11i', 'xsd:string'],
                ["{$base}.KeyPassphrase", $password, 'xsd:string'],
                ["{$base}.PreSharedKey.1.KeyPassphrase", $password, 'xsd:string'],
                ["{$base}.WPAAuthenticationMode", 'PSKAuthentication', 'xsd:string'],
                ["{$base}.WPAEncryptionModes", 'TKIPEncryption', 'xsd:string'],
                ["{$base}.IEEE11iAuthenticationMode", 'PSKAuthentication', 'xsd:string'],
                ["{$base}.IEEE11iEncryptionModes", 'AESEncryption', 'xsd:string'],
            ],
            default => [
                ["{$base}.BeaconType", '11i', 'xsd:string'],
                ["{$base}.KeyPassphrase", $password, 'xsd:string'],
                ["{$base}.PreSharedKey.1.KeyPassphrase", $password, 'xsd:string'],
                ["{$base}.IEEE11iAuthenticationMode", 'PSKAuthentication', 'xsd:string'],
                ["{$base}.IEEE11iEncryptionModes", 'AESEncryption', 'xsd:string'],
            ],
        };
    }

    /**
     * @return array{base:string,node:array<string,mixed>}|null
     */
    private function resolveTr098WifiNode(array $device, int $wlanIndex): ?array
    {
        $lanDevices = $this->getNode($device, 'InternetGatewayDevice.LANDevice');
        if (! is_array($lanDevices)) {
            return null;
        }

        foreach ($this->listInstances($lanDevices) as $lanIndex => $lanNode) {
            $configs = $this->getNode($lanNode, 'WLANConfiguration');
            if (! is_array($configs)) {
                continue;
            }

            foreach ($this->listInstances($configs) as $configIndex => $configNode) {
                if ((int) $configIndex !== $wlanIndex) {
                    continue;
                }

                return [
                    'base' => "InternetGatewayDevice.LANDevice.{$lanIndex}.WLANConfiguration.{$configIndex}",
                    'node' => $configNode,
                ];
            }
        }

        return null;
    }

    /**
     * @return array{ssid_base:string,ssid_node:array<string,mixed>,ap_base:string,ap_node:array<string,mixed>,security_node:array<string,mixed>}|null
     */
    private function resolveTr181WifiNode(array $device, int $wlanIndex): ?array
    {
        $ssids = $this->getNode($device, 'Device.WiFi.SSID');
        if (! is_array($ssids)) {
            return null;
        }

        $ssidNode = null;
        $ssidIndex = null;
        foreach ($this->listInstances($ssids) as $index => $node) {
            if ((int) $index === $wlanIndex) {
                $ssidNode = $node;
                $ssidIndex = (string) $index;
                break;
            }
        }

        if ($ssidNode === null || $ssidIndex === null) {
            return null;
        }

        $aps = $this->getNode($device, 'Device.WiFi.AccessPoint');
        $apNode = [];
        $apIndex = $ssidIndex;
        $ssidReference = "Device.WiFi.SSID.{$ssidIndex}.";

        if (is_array($aps)) {
            foreach ($this->listInstances($aps) as $index => $node) {
                $reference = (string) ($this->getPathValue($node, 'SSIDReference') ?? '');
                if ((string) $index === $ssidIndex || rtrim($reference, '.').'.' === $ssidReference) {
                    $apNode = $node;
                    $apIndex = (string) $index;
                    break;
                }
            }
        }

        $securityNode = $this->getNode($apNode, 'Security');
        if (! is_array($securityNode)) {
            $securityNode = [];
        }

        return [
            'ssid_base' => "Device.WiFi.SSID.{$ssidIndex}",
            'ssid_node' => $ssidNode,
            'ap_base' => "Device.WiFi.AccessPoint.{$apIndex}",
            'ap_node' => $apNode,
            'security_node' => $securityNode,
        ];
    }

    /**
     * @return array<int, array{0:string,1:string,2:string}>
     */
    private function buildTr098WiFiSecurityParameterValues(
        string $base,
        array $node,
        string $securityMode,
        string $password
    ): array {
        $normalizedMode = strtoupper($securityMode);
        $params = [];

        if ($normalizedMode === 'NONE') {
            $this->addNodeParameter($params, $base, $node, 'BeaconType', 'None');
            $this->addNodeParameter($params, $base, $node, 'BasicAuthenticationMode', 'None');
            $this->addNodeParameter($params, $base, $node, 'BasicEncryptionModes', 'None');
            $this->addTr098PassphraseParameters($params, $base, $node, '');

            return $params;
        }

        if ($normalizedMode === 'WPAPSK') {
            $this->addNodeParameter($params, $base, $node, 'BeaconType', 'WPA');
            $this->addTr098PassphraseParameters($params, $base, $node, $password);
            $this->addNodeParameter($params, $base, $node, 'WPAAuthenticationMode', 'PSKAuthentication');
            $this->addNodeParameter($params, $base, $node, 'WPAEncryptionModes', 'TKIPEncryption');

            return $params;
        }

        if ($normalizedMode === 'WPA2PSKWPAPSK') {
            $this->addNodeParameter($params, $base, $node, 'BeaconType', $this->tr098MixedBeaconValue($node));
            $this->addTr098PassphraseParameters($params, $base, $node, $password);
            $this->addNodeParameter($params, $base, $node, 'WPAAuthenticationMode', 'PSKAuthentication');
            $this->addNodeParameter($params, $base, $node, 'WPAEncryptionModes', 'TKIPandAESEncryption');
            $this->addNodeParameter($params, $base, $node, 'IEEE11iAuthenticationMode', 'PSKAuthentication');
            $this->addNodeParameter($params, $base, $node, 'IEEE11iEncryptionModes', 'AESEncryption');

            return $params;
        }

        $this->addNodeParameter($params, $base, $node, 'BeaconType', '11i');
        $this->addTr098PassphraseParameters($params, $base, $node, $password);
        $this->addNodeParameter($params, $base, $node, 'IEEE11iAuthenticationMode', 'PSKAuthentication');
        $this->addNodeParameter($params, $base, $node, 'IEEE11iEncryptionModes', 'AESEncryption');

        return $params;
    }

    /**
     * @return array<int, array{0:string,1:string,2:string}>
     */
    private function buildTr181WiFiSecurityParameterValues(
        string $apBase,
        array $securityNode,
        string $securityMode,
        string $password
    ): array {
        if ($securityNode === []) {
            return [];
        }

        $normalizedMode = strtoupper($securityMode);
        $modeEnabled = match ($normalizedMode) {
            'NONE' => 'None',
            'WPAPSK' => 'WPA-Personal',
            'WPA2PSKWPAPSK' => 'WPA-WPA2-Personal',
            default => 'WPA2-Personal',
        };

        $params = [];
        $this->addNodeParameter($params, "{$apBase}.Security", $securityNode, 'ModeEnabled', $modeEnabled);

        if ($normalizedMode === 'NONE') {
            if ($this->nodePathExists($securityNode, 'KeyPassphrase')) {
                $params[] = ["{$apBase}.Security.KeyPassphrase", '', 'xsd:string'];
            }

            return $params;
        }

        if ($this->nodePathExists($securityNode, 'KeyPassphrase') || ! $this->nodePathExists($securityNode, 'PreSharedKey')) {
            $params[] = ["{$apBase}.Security.KeyPassphrase", $password, 'xsd:string'];
        } else {
            $params[] = ["{$apBase}.Security.PreSharedKey", $password, 'xsd:string'];
        }

        return $params;
    }

    /**
     * @param  array<int, array{0:string,1:string,2:string}>  $params
     */
    private function addTr098PassphraseParameters(array &$params, string $base, array $node, string $password): void
    {
        $standardPaths = array_values(array_filter([
            $this->nodePathExists($node, 'KeyPassphrase') ? 'KeyPassphrase' : null,
            $this->nodePathExists($node, 'PreSharedKey.1.KeyPassphrase') ? 'PreSharedKey.1.KeyPassphrase' : null,
        ]));

        // Field passphrase vendor (X_CMS_, X_CT-COM_, dst) menolak nilai kosong
        // dengan CWMP fault 9007. Karena setParameterValues bersifat atomik, satu
        // field yang ditolak menggagalkan seluruh task → nyangkut di queue & WiFi
        // tak pernah berubah. Saat clear password (mode None) lewati field vendor:
        // security tetap mati via BeaconType/Auth mode, cukup kosongkan field standar.
        $vendorPaths = $password === '' ? [] : array_values(array_filter([
            $this->nodePathExists($node, 'X_CMS_KeyPassphrase') ? 'X_CMS_KeyPassphrase' : null,
            $this->nodePathExists($node, 'X_CT-COM_KeyPassphrase') ? 'X_CT-COM_KeyPassphrase' : null,
            $this->nodePathExists($node, 'X_ZTE-COM_KeyPassphrase') ? 'X_ZTE-COM_KeyPassphrase' : null,
            $this->nodePathExists($node, 'X_CMCC_KeyPassphrase') ? 'X_CMCC_KeyPassphrase' : null,
            $this->nodePathExists($node, 'X_CU_KeyPassphrase') ? 'X_CU_KeyPassphrase' : null,
        ]));

        $paths = array_merge($standardPaths, $vendorPaths);

        if ($paths === [] && $this->nodePathExists($node, 'PreSharedKey.1.PreSharedKey')) {
            $paths[] = 'PreSharedKey.1.PreSharedKey';
        }

        foreach ($paths as $path) {
            $params[] = ["{$base}.{$path}", $password, 'xsd:string'];
        }
    }

    /**
     * @param  array<int, array{0:string,1:string,2:string}>  $params
     */
    private function addNodeParameter(array &$params, string $base, array $node, string $path, mixed $value, string $type = 'xsd:string'): void
    {
        if (! $this->nodePathExists($node, $path)) {
            return;
        }

        $params[] = ["{$base}.{$path}", $value, $type];
    }

    private function tr098MixedBeaconValue(array $node): string
    {
        $current = strtoupper((string) ($this->getPathValue($node, 'BeaconType') ?? ''));

        return str_contains($current, '/') ? 'WPA/WPA2' : 'WPAand11i';
    }

    private function getNode(array $source, string $path): mixed
    {
        $value = $source;
        foreach (explode('.', $path) as $key) {
            if (! is_array($value) || ! array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    private function getPathValue(array $source, string $path): mixed
    {
        $value = $this->getNode($source, $path);
        if (is_array($value) && array_key_exists('_value', $value)) {
            return $value['_value'];
        }

        return is_array($value) ? null : $value;
    }

    private function nodePathExists(array $source, string $path): bool
    {
        $value = $this->getNode($source, $path);

        return $value !== null;
    }

    /**
     * @return array<string, array<string,mixed>>
     */
    private function listInstances(array $node): array
    {
        $instances = [];
        foreach ($node as $key => $value) {
            if (str_starts_with((string) $key, '_') || ! is_array($value)) {
                continue;
            }
            $instances[(string) $key] = $value;
        }

        if ($instances !== []) {
            return $instances;
        }

        if (isset($node['_object']) || isset($node['Enable']) || isset($node['SSID']) || isset($node['Name'])) {
            return ['1' => $node];
        }

        return [];
    }
}
