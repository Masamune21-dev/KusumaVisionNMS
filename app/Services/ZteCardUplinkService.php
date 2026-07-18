<?php

namespace App\Services;

use App\Models\SmartOltCardStatus;
use App\Models\SmartOltInterfaceStatus;
use App\Models\SnmpOlt;
use App\Services\Snmp\OltSnmpClient;
use App\Support\SmartOltSupport;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ZteCardUplinkService
{
    // C300/C320 card type codes
    private const XGEI_CARDS = ['HUVQ', 'HUVG', 'HUVX'];

    private const GEI_CARDS = ['SMXA', 'SMXB'];

    // C600 (Titan platform) card type codes. SFUB + GFGL/GFGM/GFGN dibaca dari `show card` C600 asli
    // (SFUB = switch/uplink 4x xgei di slot 10/11; GFG* = kartu GPON 16 port di slot 3/4/5/17).
    // Kode lain di daftar ini belum pernah dilihat langsung — tambahkan hanya setelah terlihat di
    // `show card` perangkat nyata, jangan dari dokumen.
    private const C600_XGEI_CARDS = ['SFUB', 'XGEI', 'SFUL', 'SFUM'];

    private const C600_GEI_CARDS = ['GEI'];

    private const C600_GPON_CARDS = ['GFGL', 'GFGM', 'GFGN', 'GFGH', 'GFXH', 'GFXL'];

    private const INACTIVE_CARD_STATUSES = ['OFFLINE', 'EMPTY', 'PWROFF'];

    public function __construct(
        private ZteCliProvisioningExecutor $executor,
        private OltSnmpClient $snmp,
    ) {}

    /**
     * Read stored hardware status only. CLI refresh is handled by refreshCardStatus().
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCardStatus(SnmpOlt $olt): array
    {
        return SmartOltCardStatus::query()
            ->where('snmp_olt_id', $olt->id)
            ->orderBy('rack')
            ->orderBy('shelf')
            ->orderBy('slot')
            ->get()
            ->map(fn (SmartOltCardStatus $card) => $this->serializeCard($card))
            ->all();
    }

    /**
     * Force-refresh card status from OLT and persist the parsed rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function refreshCardStatus(SnmpOlt $olt): array
    {
        // C600 `show card` CLI output can't be parsed reliably, so read the chassis card inventory
        // from SNMP (zxAnCardTable) instead. C300/C320 keep the CLI parser.
        if (SmartOltSupport::isC600($olt)) {
            $cards = $this->snmp->cardInventory($olt);

            if ($cards === []) {
                throw new RuntimeException('Tabel card SNMP C600 (zxAnCardTable) kosong atau tidak terbaca.');
            }
        } else {
            $result = $this->executor->execute($olt, 'show card');
            $output = $this->cleanCliOutput($result['output']);
            $cards = $this->parseCards($output);

            if ($cards === []) {
                $reason = $result['error'] ? ': '.$result['error'] : '';

                throw new RuntimeException('Output show card tidak berisi data card yang bisa diparse'.$reason);
            }
        }

        $cards = $this->mergeProcessorLoad($olt, $cards);

        $now = now();

        DB::transaction(function () use ($olt, $cards, $now): void {
            SmartOltCardStatus::query()
                ->where('snmp_olt_id', $olt->id)
                ->delete();

            foreach ($cards as $card) {
                SmartOltCardStatus::create([
                    ...$card,
                    'snmp_olt_id' => $olt->id,
                    'refreshed_at' => $now,
                ]);
            }
        });

        return $this->getCardStatus($olt);
    }

    /**
     * Enrich parsed `show card` rows with per-board processor load (CPU%, mem%,
     * physical memory MB) read from SNMP zxAnCardTable, matched by rack/shelf/slot.
     * Non-fatal: card data is already valid, so an SNMP hiccup leaves load null.
     *
     * @param  array<int, array<string, mixed>>  $cards
     * @return array<int, array<string, mixed>>
     */
    private function mergeProcessorLoad(SnmpOlt $olt, array $cards): array
    {
        // cardProcessors() walks the C300/C320 zxAnCardTable (.1015), which doesn't exist on C600
        // (its per-board CPU/mem .9/.11 read 0 anyway) — C600 exposes per-card CPU/mem via CLI
        // `show processor` instead, so route it there.
        if (SmartOltSupport::isC600($olt)) {
            return $this->mergeC600ProcessorLoad($olt, $cards);
        }

        try {
            $processors = $this->snmp->cardProcessors($olt);
        } catch (Throwable) {
            $processors = [];
        }

        foreach ($cards as &$card) {
            $key = ($card['rack'] ?? 1).'.'.($card['shelf'] ?? 1).'.'.($card['slot'] ?? 0);
            $proc = $processors[$key] ?? null;

            // PhyMem 0 = board without a processor (e.g. power cards). Those report
            // cpu/mem 0 too, so gate everything on PhyMem and store null so the UI skips them.
            $hasCpu = $proc && ($proc['phy_mem'] ?? 0) > 0;

            $card['cpu_load'] = $hasCpu ? ($proc['cpu'] ?? null) : null;
            $card['mem_load'] = $hasCpu ? ($proc['mem'] ?? null) : null;
            $card['phy_mem_mb'] = $hasCpu ? $proc['phy_mem'] : null;
        }
        unset($card);

        return $cards;
    }

    /**
     * Enrich C600 card rows with per-board CPU/mem from CLI `show processor` (SNMP .1082 CPU/mem
     * read 0). Matched by slot from the board name PFU-1/{slot}/0 / MPU-1/{slot}/0. Non-fatal: a
     * telnet hiccup leaves load null and the card list stays valid.
     *
     * @param  array<int, array<string, mixed>>  $cards
     * @return array<int, array<string, mixed>>
     */
    private function mergeC600ProcessorLoad(SnmpOlt $olt, array $cards): array
    {
        try {
            $result = $this->executor->execute($olt, 'show processor');
            $bySlot = $this->parseC600Processors($this->cleanCliOutput($result['output']));
        } catch (Throwable) {
            $bySlot = [];
        }

        foreach ($cards as &$card) {
            $proc = $bySlot[(int) ($card['slot'] ?? 0)] ?? null;
            $card['cpu_load'] = $proc['cpu'] ?? null;
            $card['mem_load'] = $proc['mem'] ?? null;
            $card['phy_mem_mb'] = $proc['phy_mem'] ?? null;
        }
        unset($card);

        return $cards;
    }

    /**
     * Parse C600 `show interface`-less `show processor` table into {slot => {cpu, mem, phy_mem}}.
     * Row (verified live): `PFU-1/3/0  N/A  37% 27% 24% 37% 2048 1012 50.586%` →
     * name, character, CPU(5s), CPU(1m), CPU(5m), Peak, PhyMem(MB), FreeMem(MB), Mem%.
     *
     * @return array<int, array{cpu:int, mem:int, phy_mem:int}>
     */
    private function parseC600Processors(string $output): array
    {
        $bySlot = [];

        foreach (explode("\n", $output) as $line) {
            if (! preg_match(
                '#^[A-Z]+-\d+/(\d+)/\d+\s+\S+\s+(\d+)%\s+\d+%\s+\d+%\s+\d+%\s+(\d+)\s+\d+\s+(\d+(?:\.\d+)?)%#',
                trim($line),
                $m
            )) {
                continue;
            }

            $bySlot[(int) $m[1]] = [
                'cpu' => (int) $m[2],
                'phy_mem' => (int) $m[3],
                'mem' => (int) round((float) $m[4]),
            ];
        }

        return $bySlot;
    }

    /**
     * Derive candidate uplink interface names from card list.
     *
     * @param  array<int, array<string, mixed>>  $cards
     * @return array<int, array<string, mixed>>
     */
    public function discoverUplinkInterfaces(array $cards): array
    {
        $interfaces = [];

        foreach ($cards as $card) {
            if ($this->isInactiveCard($card)) {
                continue;
            }

            $cfgType = strtoupper((string) ($card['cfg_type'] ?? ''));
            $slot = (int) ($card['slot'] ?? 0);
            $portCount = max(1, (int) ($card['port_count'] ?? 1));

            if ($slot < 1) {
                continue;
            }

            $isC600Xgei = in_array($cfgType, self::C600_XGEI_CARDS, true);
            $isC600Gei = in_array($cfgType, self::C600_GEI_CARDS, true);
            $isC600Gpon = in_array($cfgType, self::C600_GPON_CARDS, true);

            if (in_array($cfgType, self::XGEI_CARDS, true) || $isC600Xgei) {
                // Sama-sama 3-tier `1/slot/port`; yang beda cuma ejaannya — C600 `xgei-1/10/1`
                // (terbaca di ifName C600 asli), C300/C320 `xgei_1/slot/port`.
                $ifacePrefix = $isC600Xgei ? "xgei-1/{$slot}" : "xgei_1/{$slot}";

                for ($port = 1; $port <= $portCount; $port++) {
                    $interfaces[] = [
                        'interface' => "{$ifacePrefix}/{$port}",
                        'interface_type' => 'uplink',
                        'card_type' => $cfgType,
                        'slot' => $slot,
                        'port' => $port,
                    ];
                }
            }

            if (in_array($cfgType, self::GEI_CARDS, true) || $isC600Gei) {
                $ifacePrefix = $isC600Gei ? "gei-1/{$slot}" : "gei_1/{$slot}";

                for ($port = 1; $port <= $portCount; $port++) {
                    $interfaces[] = [
                        'interface' => "{$ifacePrefix}/{$port}",
                        'interface_type' => 'uplink',
                        'card_type' => $cfgType,
                        'slot' => $slot,
                        'port' => $port,
                    ];
                }
            }
        }

        return $this->uniqueInterfaces($interfaces);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getStoredUplinkInterfaces(SnmpOlt $olt): array
    {
        return collect($this->getStoredInterfaceDetails($olt))
            ->where('interface_type', 'uplink')
            ->map(fn (array $row) => [
                'interface' => $row['interface'],
                'interface_type' => $row['interface_type'],
                'card_type' => $row['card_type'],
                'slot' => $row['slot'],
                'port' => $row['port'],
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getStoredInterfaceDetails(SnmpOlt $olt): array
    {
        $storedRows = SmartOltInterfaceStatus::query()
            ->where('snmp_olt_id', $olt->id)
            ->get()
            ->map(fn (SmartOltInterfaceStatus $row) => $this->serializeInterface($row))
            ->keyBy('interface');

        foreach ($this->snapshotGponInterfaceRows($olt) as $row) {
            $storedRows[$row['interface']] = [
                ...$row,
                ...($storedRows[$row['interface']] ?? []),
            ];
        }

        return $storedRows
            ->values()
            ->sortBy(fn (array $row) => sprintf(
                '%d-%03d-%03d-%s',
                ($row['interface_type'] ?? null) === 'uplink' ? 0 : 1,
                (int) ($row['slot'] ?? 0),
                (int) ($row['port'] ?? 0),
                $row['interface'] ?? '',
            ))
            ->values()
            ->all();
    }

    /**
     * Refresh uplink interface details from CLI and persist successful rows.
     *
     * @param  array<int, array<string, mixed>>|null  $cards
     * @return array<int, array<string, mixed>>
     */
    public function refreshInterfaceDetails(SnmpOlt $olt, ?array $cards = null): array
    {
        $cards ??= $this->getCardStatus($olt);
        $inventory = $this->discoverUplinkInterfaces($cards);

        if ($inventory === []) {
            throw new RuntimeException('Belum ada card uplink aktif untuk discovery interface. Refresh hardware terlebih dahulu.');
        }

        if (SmartOltSupport::isC600($olt)) {
            return $this->refreshC600InterfaceDetails($olt, $inventory);
        }

        $commands = [];
        foreach ($inventory as $item) {
            $interface = $item['interface'];

            $commands[] = "show interface port-status {$interface}";
            $commands[] = "show vlan port {$interface}";
            $commands[] = "show interface optical-module-info {$interface}";
        }

        $commands = array_values(array_unique($commands));
        $result = $this->executor->execute($olt, implode("\n", $commands));
        $output = $this->cleanCliOutput($result['output']);
        $segments = $this->splitCommandOutput($output);
        $now = now();
        $rows = [];

        foreach ($inventory as $item) {
            $interface = $item['interface'];
            $row = [
                'snmp_olt_id' => $olt->id,
                'interface' => $interface,
                'interface_type' => $item['interface_type'],
                'slot' => $item['slot'],
                'port' => $item['port'],
                'card_type' => $item['card_type'],
                'refreshed_at' => $now,
            ];
            $hasData = false;

            $statusCommand = "show interface port-status {$interface}";
            $statusOutput = $segments[$statusCommand] ?? '';
            $status = $this->parsePortStatus($statusOutput);

            if ($status !== null) {
                $row = [
                    ...$row,
                    ...$status,
                    'raw_status' => trim($statusOutput) ?: null,
                    'status_refreshed_at' => $now,
                ];
                $hasData = true;
            }

            $vlanCommand = "show vlan port {$interface}";
            $vlanOutput = $segments[$vlanCommand] ?? '';

            if ($vlanOutput !== '' && ! $this->isInvalidOutput($vlanOutput)) {
                $row = [
                    ...$row,
                    'tagged_vlans' => $this->parseTaggedVlans($vlanOutput),
                    'raw_vlan' => trim($vlanOutput) ?: null,
                    'vlan_refreshed_at' => $now,
                ];
                $hasData = true;
            }

            $opticalCommand = "show interface optical-module-info {$interface}";
            $opticalOutput = $segments[$opticalCommand] ?? '';
            $optical = $this->parseOpticalModuleInfo($opticalOutput);

            if ($optical !== null) {
                $row = [
                    ...$row,
                    ...$optical,
                    'raw_optical' => trim($opticalOutput) ?: null,
                    'optical_refreshed_at' => $now,
                ];
                $hasData = true;
            }

            if ($hasData) {
                $rows[] = $row;
            }
        }

        if ($rows === []) {
            $reason = $result['error'] ? ': '.$result['error'] : '';

            throw new RuntimeException('Tidak ada detail interface yang berhasil diparse'.$reason);
        }

        DB::transaction(function () use ($olt, $rows): void {
            SmartOltInterfaceStatus::query()
                ->where('snmp_olt_id', $olt->id)
                ->where('interface_type', 'uplink')
                ->delete();

            foreach ($rows as $row) {
                SmartOltInterfaceStatus::create($row);
            }
        });

        return $this->getStoredUplinkInterfaces($olt);
    }

    /**
     * C600 uplink discovery: `show interface {iface}` per uplink (port-status/optical unsupported),
     * parsed with parseC600UplinkInterface. Persists rows like refreshInterfaceDetails.
     *
     * @param  array<int, array<string, mixed>>  $inventory
     * @return array<int, array<string, mixed>>
     */
    private function refreshC600InterfaceDetails(SnmpOlt $olt, array $inventory): array
    {
        $commands = [];
        foreach ($inventory as $item) {
            $commands[] = "show interface {$item['interface']}";
        }
        $commands = array_values(array_unique($commands));

        $result = $this->executor->execute($olt, implode("\n", $commands));
        $output = $this->cleanCliOutput($result['output']);
        $segments = $this->splitCommandOutput($output);
        $now = now();
        $rows = [];

        foreach ($inventory as $item) {
            $interface = $item['interface'];
            $statusOutput = $segments["show interface {$interface}"] ?? '';
            $parsed = $this->parseC600UplinkInterface($statusOutput, $interface);

            if ($parsed === null) {
                continue;
            }

            $rows[] = [
                'snmp_olt_id' => $olt->id,
                'interface' => $interface,
                'interface_type' => $item['interface_type'],
                'slot' => $item['slot'],
                'port' => $item['port'],
                'card_type' => $item['card_type'],
                ...$parsed,
                'raw_status' => trim($statusOutput) ?: null,
                'status_refreshed_at' => $now,
                'refreshed_at' => $now,
            ];
        }

        if ($rows === []) {
            $reason = $result['error'] ? ': '.$result['error'] : '';

            throw new RuntimeException('Tidak ada detail interface yang berhasil diparse'.$reason);
        }

        DB::transaction(function () use ($olt, $rows): void {
            SmartOltInterfaceStatus::query()
                ->where('snmp_olt_id', $olt->id)
                ->where('interface_type', 'uplink')
                ->delete();

            foreach ($rows as $row) {
                SmartOltInterfaceStatus::create($row);
            }
        });

        return $this->getStoredUplinkInterfaces($olt);
    }

    /**
     * Refresh one GPON interface detail from `show interface gpon-olt_...`.
     *
     * @return array<string, mixed>
     */
    public function refreshGponInterface(SnmpOlt $olt, string $interface): array
    {
        $isC600 = SmartOltSupport::isC600($olt);

        if ($isC600
            ? ! preg_match('#^gpon_olt-\d+/\d+/\d+$#', $interface)
            : ! preg_match('/^gpon(?:-olt)?_\d+\/\d+\/\d+$/', $interface)) {
            throw new RuntimeException('Interface GPON tidak valid.');
        }

        $statusCommand = "show interface {$interface}";
        // C600 has no `show interface optical-module-info` for GPON ports (Invalid input),
        // so skip the optical read there — the OLT-side SFP metrics aren't exposed via CLI.
        $opticalCommand = $isC600 ? null : "show interface optical-module-info {$interface}";
        $result = $this->executor->execute(
            $olt,
            $opticalCommand ? "{$statusCommand}\n{$opticalCommand}" : $statusCommand
        );
        $output = $this->cleanCliOutput($result['output']);
        $segments = $this->splitCommandOutput($output);
        $statusOutput = $segments[$statusCommand] ?? $output;
        $opticalOutput = $opticalCommand ? ($segments[$opticalCommand] ?? '') : '';
        $parsed = $this->parseGponInterface($statusOutput, $interface);

        if ($parsed === null) {
            $reason = $result['error'] ? ': '.$result['error'] : '';

            throw new RuntimeException("Output {$interface} tidak bisa diparse{$reason}");
        }

        $metadata = $this->interfaceMetadata($interface);
        $optical = $this->parseOpticalModuleInfo($opticalOutput);
        $now = now();
        $values = [
            ...$metadata,
            'card_type' => $this->cardTypeForSlot($olt, (int) ($metadata['slot'] ?? 0)),
            ...$parsed,
            'raw_status' => trim($statusOutput) ?: null,
            'status_refreshed_at' => $now,
            'refreshed_at' => $now,
        ];

        if ($optical !== null) {
            $values = [
                ...$values,
                ...$optical,
                'raw_optical' => trim($opticalOutput) ?: null,
                'optical_refreshed_at' => $now,
            ];
        }

        $row = SmartOltInterfaceStatus::updateOrCreate(
            [
                'snmp_olt_id' => $olt->id,
                'interface' => $interface,
            ],
            $values,
        );

        return $this->serializeInterface($row);
    }

    /**
     * Refresh one uplink interface (xgei/gei) detail from CLI and persist it.
     *
     * @return array<string, mixed>
     */
    public function refreshUplinkInterface(SnmpOlt $olt, string $interface): array
    {
        if (SmartOltSupport::isC600($olt)) {
            return $this->refreshC600UplinkInterface($olt, $interface);
        }

        if (! preg_match('/^(?:xgei|gei)_\d+\/\d+\/\d+$/', $interface)) {
            throw new RuntimeException('Interface uplink tidak valid.');
        }

        $statusCommand = "show interface port-status {$interface}";
        $vlanCommand = "show vlan port {$interface}";
        $opticalCommand = "show interface optical-module-info {$interface}";

        $result = $this->executor->execute($olt, implode("\n", [$statusCommand, $vlanCommand, $opticalCommand]));
        $output = $this->cleanCliOutput($result['output']);
        $segments = $this->splitCommandOutput($output);
        $now = now();

        $metadata = $this->interfaceMetadata($interface);
        $values = [
            ...$metadata,
            'card_type' => $this->cardTypeForSlot($olt, (int) ($metadata['slot'] ?? 0)),
            'refreshed_at' => $now,
        ];
        $hasData = false;

        $statusOutput = $segments[$statusCommand] ?? '';
        $status = $this->parsePortStatus($statusOutput);
        if ($status !== null) {
            $values = [
                ...$values,
                ...$status,
                'raw_status' => trim($statusOutput) ?: null,
                'status_refreshed_at' => $now,
            ];
            $hasData = true;
        }

        $vlanOutput = $segments[$vlanCommand] ?? '';
        if ($vlanOutput !== '' && ! $this->isInvalidOutput($vlanOutput)) {
            $values = [
                ...$values,
                'tagged_vlans' => $this->parseTaggedVlans($vlanOutput),
                'raw_vlan' => trim($vlanOutput) ?: null,
                'vlan_refreshed_at' => $now,
            ];
            $hasData = true;
        }

        $opticalOutput = $segments[$opticalCommand] ?? '';
        $optical = $this->parseOpticalModuleInfo($opticalOutput);
        if ($optical !== null) {
            $values = [
                ...$values,
                ...$optical,
                'raw_optical' => trim($opticalOutput) ?: null,
                'optical_refreshed_at' => $now,
            ];
            $hasData = true;
        }

        if (! $hasData) {
            $reason = $result['error'] ? ': '.$result['error'] : '';

            throw new RuntimeException("Output {$interface} tidak bisa diparse{$reason}");
        }

        $row = SmartOltInterfaceStatus::updateOrCreate(
            [
                'snmp_olt_id' => $olt->id,
                'interface' => $interface,
            ],
            $values,
        );

        return $this->serializeInterface($row);
    }

    /**
     * Refresh one C600 uplink interface (xgei-1/{slot}/{port}) from CLI and persist it.
     * C600 has no `show interface port-status` / `optical-module-info` for uplinks (Invalid input),
     * so `show interface {iface}` is the single source: admin/line status, description, rates.
     *
     * @return array<string, mixed>
     */
    private function refreshC600UplinkInterface(SnmpOlt $olt, string $interface): array
    {
        if (! preg_match('#^(?:xgei|gei)-\d+/\d+/\d+$#', $interface)) {
            throw new RuntimeException('Interface uplink tidak valid.');
        }

        $statusCommand = "show interface {$interface}";
        $result = $this->executor->execute($olt, $statusCommand);
        $statusOutput = $this->cleanCliOutput($result['output']);
        $parsed = $this->parseC600UplinkInterface($statusOutput, $interface);

        if ($parsed === null) {
            $reason = $result['error'] ? ': '.$result['error'] : '';

            throw new RuntimeException("Output {$interface} tidak bisa diparse{$reason}");
        }

        $metadata = $this->interfaceMetadata($interface);
        $now = now();
        $values = [
            ...$metadata,
            'card_type' => $this->cardTypeForSlot($olt, (int) ($metadata['slot'] ?? 0)),
            ...$parsed,
            'raw_status' => trim($statusOutput) ?: null,
            'status_refreshed_at' => $now,
            'refreshed_at' => $now,
        ];

        $row = SmartOltInterfaceStatus::updateOrCreate(
            ['snmp_olt_id' => $olt->id, 'interface' => $interface],
            $values,
        );

        return $this->serializeInterface($row);
    }

    /**
     * Parse a C600 `show interface xgei-1/{slot}/{port}` block. Format (verified live):
     *   xgei-1/10/1 admin status is up, line protocol is up, detect status is OK
     *   Description is GAMER
     *   The port negotiation is force / The port is optical / Duplex full
     *     Interface current rate:  input : N Bps, M pps  /  output : N Bps, M pps
     *     Interface peak rate:     input : N Bps, output: N Bps
     *     Interface utilization:   input : P%,  output : P%
     *
     * @return array<string, mixed>|null
     */
    public function parseC600UplinkInterface(string $output, ?string $expectedInterface = null): ?array
    {
        $output = $this->cleanCliOutput($output);

        if ($this->isInvalidOutput($output)) {
            return null;
        }

        $ifacePattern = $expectedInterface !== null
            ? preg_quote($expectedInterface, '/')
            : '(?:xgei|gei)-\d+\/\d+\/\d+';

        if (! preg_match('/\b'.$ifacePattern.'\b\s+admin status is\s+([^,]+?)\s*,\s*line protocol is\s+([^,\s]+)/i', $output, $m)) {
            return null;
        }

        $description = null;
        if (preg_match('/Description\s+is\s+(.+)/i', $output, $dm)) {
            $description = trim(rtrim($dm[1], '.'));
            $description = ($description === '' || strcasecmp($description, 'null') === 0 || strcasecmp($description, 'none') === 0)
                ? null
                : $description;
        }

        // Current rate: "input : N Bps, M pps" (first match) / "output : N Bps, M pps".
        $inputBps = $outputBps = $inputPps = $outputPps = null;
        if (preg_match('/input\s*:\s*(\d+)\s*Bps,\s*(\d+)\s*pps/i', $output, $rm)) {
            $inputBps = (int) $rm[1];
            $inputPps = (int) $rm[2];
        }
        if (preg_match('/output\s*:\s*(\d+)\s*Bps,\s*(\d+)\s*pps/i', $output, $rm)) {
            $outputBps = (int) $rm[1];
            $outputPps = (int) $rm[2];
        }

        // Peak rate: "input : N Bps, output: N Bps" (bps only, no pps).
        $inputPeakBps = $outputPeakBps = null;
        if (preg_match('/input\s*:\s*(\d+)\s*Bps,\s*output:\s*(\d+)\s*Bps/i', $output, $pm)) {
            $inputPeakBps = (int) $pm[1];
            $outputPeakBps = (int) $pm[2];
        }

        // Utilization: "input : P%,  output : P%".
        $inputPct = $outputPct = null;
        if (preg_match('/input\s*:\s*(-?\d+(?:\.\d+)?)%\s*,?\s*output\s*:\s*(-?\d+(?:\.\d+)?)%/i', $output, $um)) {
            $inputPct = (float) $um[1];
            $outputPct = (float) $um[2];
        }

        $duplex = preg_match('/Duplex\s+(\S+)/i', $output, $dpx) ? strtolower($dpx[1]) : null;
        $negotiation = preg_match('/port negotiation is\s+(\S+)/i', $output, $ng) ? strtolower($ng[1]) : null;

        return [
            'admin_status' => strtolower(trim($m[1])),
            'link_status' => strtolower(trim($m[2])),
            'description' => $description,
            'negotiation' => $negotiation,
            'duplex' => $duplex,
            'input_bps' => $inputBps,
            'output_bps' => $outputBps,
            'input_pps' => $inputPps,
            'output_pps' => $outputPps,
            'input_peak_bps' => $inputPeakBps,
            'output_peak_bps' => $outputPeakBps,
            'input_throughput_percent' => $inputPct,
            'output_throughput_percent' => $outputPct,
        ];
    }

    /**
     * @return array{interface:string, line_status:string, input_bps:int, output_bps:int, input_pps:int, output_pps:int, timestamp:int}
     */
    public function getUplinkInfo(SnmpOlt $olt, string $interface): array
    {
        $result = $this->executor->execute($olt, "show interface {$interface}");
        $output = $this->cleanCliOutput($result['output']);

        // C600 uplink output differs ("… admin status is up, line protocol is up" + "input : N Bps, M pps")
        // — reuse the C600 parser so live traffic works on TITAN uplinks.
        if (SmartOltSupport::isC600($olt)) {
            $parsed = $this->parseC600UplinkInterface($output, $interface);

            return [
                'interface' => $interface,
                'line_status' => $parsed['link_status'] ?? 'unknown',
                'input_bps' => (int) ($parsed['input_bps'] ?? 0),
                'output_bps' => (int) ($parsed['output_bps'] ?? 0),
                'input_pps' => (int) ($parsed['input_pps'] ?? 0),
                'output_pps' => (int) ($parsed['output_pps'] ?? 0),
                'timestamp' => time(),
            ];
        }

        $lineStatus = 'unknown';
        if (preg_match('/\b'.preg_quote($interface, '/').'\b\s+is\s+(administratively\s+down|up|down)/i', $output, $m)) {
            $raw = strtolower(trim($m[1]));
            $lineStatus = $raw === 'up' ? 'up' : ($raw === 'administratively down' ? 'admin-down' : 'down');
        }

        $inputBps = 0;
        $outputBps = 0;
        $inputPps = 0;
        $outputPps = 0;

        if (preg_match('/\d+\s+seconds\s+input\s+rate\s*:\s*(\d+)\s+Bps,\s*(\d+)\s+pps/i', $output, $m)) {
            $inputBps = (int) $m[1];
            $inputPps = (int) $m[2];
        }

        if (preg_match('/\d+\s+seconds\s+output\s+rate\s*:\s*(\d+)\s+Bps,\s*(\d+)\s+pps/i', $output, $m)) {
            $outputBps = (int) $m[1];
            $outputPps = (int) $m[2];
        }

        return [
            'interface' => $interface,
            'line_status' => $lineStatus,
            'input_bps' => $inputBps,
            'output_bps' => $outputBps,
            'input_pps' => $inputPps,
            'output_pps' => $outputPps,
            'timestamp' => time(),
        ];
    }

    /**
     * @return array{interface:string, tagged_vlans:string[], raw:string}
     */
    public function getVlanMapping(SnmpOlt $olt, string $interface): array
    {
        $row = SmartOltInterfaceStatus::query()
            ->where('snmp_olt_id', $olt->id)
            ->where('interface', $interface)
            ->first();

        return [
            'interface' => $interface,
            'tagged_vlans' => $row?->tagged_vlans ?? [],
            'raw' => $row?->raw_vlan ?? '',
        ];
    }

    /**
     * @return array{interface:string, tagged_vlans:string[], raw:string}
     */
    public function refreshVlanMapping(SnmpOlt $olt, string $interface): array
    {
        $result = $this->executor->execute($olt, "show vlan port {$interface}");
        $output = $this->cleanCliOutput($result['output']);

        if ($this->isInvalidOutput($output)) {
            throw new RuntimeException('Refresh VLAN gagal: '.($result['error'] ?? 'invalid output'));
        }

        $metadata = $this->interfaceMetadata($interface);
        $taggedVlans = $this->parseTaggedVlans($output);
        $now = now();

        SmartOltInterfaceStatus::updateOrCreate(
            [
                'snmp_olt_id' => $olt->id,
                'interface' => $interface,
            ],
            [
                ...$metadata,
                'tagged_vlans' => $taggedVlans,
                'raw_vlan' => trim($output) ?: null,
                'vlan_refreshed_at' => $now,
                'refreshed_at' => $now,
            ],
        );

        return [
            'interface' => $interface,
            'tagged_vlans' => $taggedVlans,
            'raw' => $output,
        ];
    }

    /**
     * @return array{ok:bool, output:string, error:string|null}
     */
    public function addAndTagVlan(SnmpOlt $olt, string $interface, int $vlanId): array
    {
        $script = implode("\n", [
            'configure terminal',
            "vlan {$vlanId}",
            'exit',
            "interface {$interface}",
            "switchport vlan {$vlanId} tag",
            'end',
            'write',
        ]);

        $result = $this->executor->execute($olt, $script);

        if ($result['ok']) {
            try {
                $this->refreshVlanMapping($olt, $interface);
            } catch (Throwable) {
                //
            }
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parseCards(string $output): array
    {
        $output = $this->cleanCliOutput($output);
        $cards = [];
        $statusPattern = 'INSERVICE|STANDBY|OFFLINE|EMPTY|PWROFF|PROV';

        foreach (explode("\n", $output) as $line) {
            $line = trim($line);

            if (! preg_match(
                '/^(\d+)\s+(\d+)\s+(\d+)\s+(\S+)(?:\s+(\S+))?\s+(\d+)\s+(?:(\S+)\s+)?(?:(\S+)\s+)?('.$statusPattern.')\s*$/i',
                $line,
                $m
            )) {
                continue;
            }

            $cards[] = [
                'rack' => (int) $m[1],
                'shelf' => (int) $m[2],
                'slot' => (int) $m[3],
                'cfg_type' => strtoupper($m[4]),
                'real_type' => isset($m[5]) ? strtoupper($m[5]) : null,
                'port_count' => (int) $m[6],
                'hard_ver' => $m[7] ?? null,
                'soft_ver' => $m[8] ?? null,
                'status' => strtoupper($m[9]),
                'raw_line' => $line,
            ];
        }

        return $cards;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function parsePortStatus(string $output): ?array
    {
        $output = $this->cleanCliOutput($output);

        if ($this->isInvalidOutput($output)) {
            return null;
        }

        foreach (explode("\n", $output) as $line) {
            $line = trim($line);

            if (! preg_match('/^((?:xgei|gei)_\d+\/\d+\/\d+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)/i', $line, $m)) {
                continue;
            }

            return [
                'hybrid_status' => strtolower($m[2]),
                'native_vlan' => is_numeric($m[3]) ? (int) $m[3] : null,
                'negotiation' => strtolower($m[4]),
                'speed_mbps' => is_numeric($m[5]) ? (int) $m[5] : null,
                'duplex' => strtolower($m[6]),
                'flow_ctrl' => strtolower($m[7]),
                'admin_status' => strtolower($m[8]),
                'link_status' => strtolower($m[9]),
            ];
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function parseGponInterface(string $output, ?string $expectedInterface = null): ?array
    {
        $output = $this->cleanCliOutput($output);

        if ($this->isInvalidOutput($output)) {
            return null;
        }

        $interfacePattern = $expectedInterface !== null
            ? preg_quote($expectedInterface, '/')
            : 'gpon(?:-olt)?_\d+\/\d+\/\d+';

        if (! preg_match('/\b('.$interfacePattern.')\s+is\s+([^,\.]+)\s*,?\s*line protocol is\s+([^\.]+)\./i', $output, $m)) {
            return null;
        }

        $description = null;
        if (preg_match('/Description\s+is\s+(.+?)\./i', $output, $descriptionMatch)) {
            $description = trim($descriptionMatch[1]);
            $description = strcasecmp($description, 'none') === 0 ? null : $description;
        }

        $onuCapacity = null;
        $registeredOnuCount = null;
        if (preg_match('/port\s+has\s+(\d+)\s+onus,\s+the\s+number\s+of\s+registered\s+onus\s+is\s+(\d+)/i', $output, $onuMatch)) {
            $onuCapacity = (int) $onuMatch[1];
            $registeredOnuCount = (int) $onuMatch[2];
        }

        $inputRate = $this->parseRateLine($output, 'Input rate');
        $outputRate = $this->parseRateLine($output, 'Output rate');
        $inputPeakRate = $this->parseRateLine($output, 'Input peak rate');
        $outputPeakRate = $this->parseRateLine($output, 'Output peak rate');

        return [
            'admin_status' => strtolower(trim($m[2])),
            'link_status' => strtolower(trim($m[3])),
            'description' => $description,
            'onu_capacity' => $onuCapacity,
            'registered_onu_count' => $registeredOnuCount,
            'input_bps' => $inputRate['bps'],
            'output_bps' => $outputRate['bps'],
            'input_pps' => $inputRate['pps'],
            'output_pps' => $outputRate['pps'],
            'input_throughput_percent' => $this->parsePercent($output, 'Input Instantaneous bandwidth throughput'),
            'output_throughput_percent' => $this->parsePercent($output, 'Output Instantaneous bandwidth throughput'),
            'input_average_throughput_percent' => $this->parsePercent($output, 'Input Average bandwidth throughput'),
            'output_average_throughput_percent' => $this->parsePercent($output, 'Output Average bandwidth throughput'),
            'input_peak_bps' => $inputPeakRate['bps'],
            'output_peak_bps' => $outputPeakRate['bps'],
            'input_peak_pps' => $inputPeakRate['pps'],
            'output_peak_pps' => $outputPeakRate['pps'],
            'gpon_counters' => $this->parseGponCounters($output),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function parseOpticalModuleInfo(string $output): ?array
    {
        $output = $this->cleanCliOutput($output);

        if ($output === '' || $this->isInvalidOutput($output) || ! str_contains($output, 'Optical module information')) {
            return null;
        }

        $fields = $this->extractOpticalFields($output);

        if ($fields === []) {
            return null;
        }

        $thresholds = [];
        foreach ([
            'RxPower-Upper',
            'RxPower-Lower',
            'TxPower-Upper',
            'TxPower-Lower',
            'Bias-Upper',
            'Bias-Lower',
            'Voltage-Upper',
            'Voltage-Lower',
            'Temperature-Upper',
            'Temperature-Lower',
        ] as $label) {
            if (array_key_exists($label, $fields)) {
                $thresholds[$label] = $this->parseMeasure($fields[$label]);
            }
        }

        return [
            'optical_vendor_name' => $this->nullableText($fields['Vendor-Name'] ?? null),
            'optical_vendor_pn' => $this->nullableText($fields['Vendor-Pn'] ?? null),
            'optical_vendor_sn' => $this->nullableText($fields['Vendor-Sn'] ?? null),
            'optical_module_type' => $this->nullableText($fields['Module-Type'] ?? null),
            'optical_wavelength_nm' => $this->parseIntegerMeasure($fields['Wavelength'] ?? null),
            'optical_connector' => $this->nullableText($fields['Connector'] ?? null),
            'optical_trans_distance' => $this->nullableText($fields['Trans-Distance'] ?? null),
            'rx_power_dbm' => $this->parseMeasure($fields['RxPower'] ?? null),
            'tx_power_dbm' => $this->parseMeasure($fields['TxPower'] ?? null),
            'tx_bias_current_ma' => $this->parseMeasure($fields['TxBias-Current'] ?? null),
            'laser_rate' => $this->nullableText($fields['Laser-Rate'] ?? null),
            'temperature_c' => $this->parseMeasure($fields['Temperature'] ?? null),
            'supply_voltage_v' => $this->parseMeasure($fields['Supply-Vol'] ?? null),
            'optical_thresholds' => $thresholds,
        ];
    }

    /**
     * @return string[]
     */
    public function parseTaggedVlans(string $output): array
    {
        $output = $this->cleanCliOutput($output);
        $output = str_replace(["\r\n", "\r"], "\n", $output);
        $lines = explode("\n", $output);
        $collecting = false;
        $vlanBlock = '';

        foreach ($lines as $line) {
            $line = trim($line);

            if (stripos($line, 'TaggedVlan:') !== false) {
                $collecting = true;
                $inline = trim(substr($line, strpos($line, ':') + 1));

                if ($inline !== '' && preg_match('/^[\d,\-]+$/', $inline)) {
                    $vlanBlock .= ($vlanBlock ? ',' : '').$inline;
                }

                continue;
            }

            if (! $collecting) {
                continue;
            }

            if ($line === '') {
                continue;
            }

            if (preg_match('/^[\d,\-]+$/', $line)) {
                $vlanBlock .= ($vlanBlock ? ',' : '').$line;
            } else {
                break;
            }
        }

        if ($vlanBlock === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $vlanBlock)),
            fn (string $v) => $v !== ''
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function snapshotGponInterfaceRows(SnmpOlt $olt): array
    {
        $cardsBySlot = collect($this->getCardStatus($olt))->keyBy('slot');
        $snapshot = $olt->last_test_result ?? [];

        return collect(data_get($snapshot, 'ports', []))
            ->map(function (array $port) use ($cardsBySlot, $snapshot) {
                $interface = (string) data_get($port, 'name', '');

                // Normalise legacy gpon_ snapshots to canonical gpon-olt_ format.
                $interface = preg_replace('/^gpon_/i', 'gpon-olt_', $interface) ?? $interface;

                if (! preg_match('/^gpon(?:-olt)?_\d+\/(\d+)\/(\d+)$/', $interface, $m)) {
                    return null;
                }

                $slot = (int) ($port['slot'] ?? $m[1]);
                $portNumber = (int) ($port['port'] ?? $m[2]);
                $onus = data_get($snapshot, "port_onus.{$slot}_{$portNumber}.onus", []);

                return [
                    'id' => null,
                    'interface' => $interface,
                    'interface_type' => 'gpon',
                    'slot' => $slot,
                    'port' => $portNumber,
                    'card_type' => data_get($cardsBySlot->get($slot), 'cfg_type'),
                    'admin_status' => null,
                    'link_status' => data_get($port, 'oper_status'),
                    'registered_onu_count' => is_array($onus) && $onus !== [] ? count($onus) : data_get($port, 'onu_count'),
                    'refreshed_at' => null,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $interfaces
     * @return array<int, array<string, mixed>>
     */
    private function uniqueInterfaces(array $interfaces): array
    {
        $unique = [];

        foreach ($interfaces as $interface) {
            $unique[$interface['interface']] = $interface;
        }

        return array_values($unique);
    }

    /**
     * @param  array<string, mixed>  $card
     */
    private function isInactiveCard(array $card): bool
    {
        return in_array(strtoupper((string) ($card['status'] ?? '')), self::INACTIVE_CARD_STATUSES, true);
    }

    private function serializeCard(SmartOltCardStatus $card): array
    {
        return [
            'id' => $card->id,
            'rack' => $card->rack,
            'shelf' => $card->shelf,
            'slot' => $card->slot,
            'cfg_type' => $card->cfg_type,
            'real_type' => $card->real_type,
            'port_count' => $card->port_count,
            'hard_ver' => $card->hard_ver,
            'soft_ver' => $card->soft_ver,
            'status' => $card->status,
            'cpu_load' => $card->cpu_load,
            'mem_load' => $card->mem_load,
            'phy_mem_mb' => $card->phy_mem_mb,
            'raw_line' => $card->raw_line,
            'refreshed_at' => $card->refreshed_at?->toIso8601String(),
        ];
    }

    private function serializeInterface(SmartOltInterfaceStatus $row): array
    {
        return [
            'id' => $row->id,
            'interface' => $row->interface,
            'interface_type' => $row->interface_type,
            'slot' => $row->slot,
            'port' => $row->port,
            'card_type' => $row->card_type,
            'hybrid_status' => $row->hybrid_status,
            'native_vlan' => $row->native_vlan,
            'negotiation' => $row->negotiation,
            'speed_mbps' => $row->speed_mbps,
            'duplex' => $row->duplex,
            'flow_ctrl' => $row->flow_ctrl,
            'admin_status' => $row->admin_status,
            'link_status' => $row->link_status,
            'description' => $row->description,
            'onu_capacity' => $row->onu_capacity,
            'registered_onu_count' => $row->registered_onu_count,
            'input_bps' => $row->input_bps,
            'output_bps' => $row->output_bps,
            'input_pps' => $row->input_pps,
            'output_pps' => $row->output_pps,
            'input_throughput_percent' => $row->input_throughput_percent,
            'output_throughput_percent' => $row->output_throughput_percent,
            'input_average_throughput_percent' => $row->input_average_throughput_percent,
            'output_average_throughput_percent' => $row->output_average_throughput_percent,
            'input_peak_bps' => $row->input_peak_bps,
            'output_peak_bps' => $row->output_peak_bps,
            'input_peak_pps' => $row->input_peak_pps,
            'output_peak_pps' => $row->output_peak_pps,
            'gpon_counters' => $row->gpon_counters ?? [],
            'tagged_vlans' => $row->tagged_vlans ?? [],
            'optical_vendor_name' => $row->optical_vendor_name,
            'optical_vendor_pn' => $row->optical_vendor_pn,
            'optical_vendor_sn' => $row->optical_vendor_sn,
            'optical_module_type' => $row->optical_module_type,
            'optical_wavelength_nm' => $row->optical_wavelength_nm,
            'optical_connector' => $row->optical_connector,
            'optical_trans_distance' => $row->optical_trans_distance,
            'rx_power_dbm' => $row->rx_power_dbm,
            'tx_power_dbm' => $row->tx_power_dbm,
            'tx_bias_current_ma' => $row->tx_bias_current_ma,
            'laser_rate' => $row->laser_rate,
            'temperature_c' => $row->temperature_c,
            'supply_voltage_v' => $row->supply_voltage_v,
            'optical_thresholds' => $row->optical_thresholds ?? [],
            'status_refreshed_at' => $row->status_refreshed_at?->toIso8601String(),
            'vlan_refreshed_at' => $row->vlan_refreshed_at?->toIso8601String(),
            'optical_refreshed_at' => $row->optical_refreshed_at?->toIso8601String(),
            'refreshed_at' => $row->refreshed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function splitCommandOutput(string $output): array
    {
        $segments = [];

        if (preg_match_all('/(?:^|\n)>\s+(.+?)\n(.*?)(?=\n>\s+|\z)/s', $output, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $segments[trim($match[1])] = trim($match[2]);
            }
        }

        return $segments;
    }

    /**
     * @return array<string, mixed>
     */
    private function interfaceMetadata(string $interface): array
    {
        // C600/TITAN 3-tier naming: gpon_olt-1/{slot}/{port}, xgei-1/{slot}/{port} (rack always 1).
        if (preg_match('#^gpon_olt-\d+/(\d+)/(\d+)$#i', $interface, $m)) {
            return [
                'interface_type' => 'gpon',
                'slot' => (int) $m[1],
                'port' => (int) $m[2],
            ];
        }

        if (preg_match('#^(?:xgei|gei)-\d+/(\d+)/(\d+)$#i', $interface, $m)) {
            return [
                'interface_type' => 'uplink',
                'slot' => (int) $m[1],
                'port' => (int) $m[2],
            ];
        }

        if (preg_match('/^(xgei|gei)_(\d+)\/(\d+)\/(\d+)$/i', $interface, $m)) {
            return [
                'interface_type' => 'uplink',
                'slot' => (int) $m[3],
                'port' => (int) $m[4],
            ];
        }

        if (preg_match('/^gpon(?:-olt)?_(\d+)\/(\d+)\/(\d+)$/i', $interface, $m)) {
            return [
                'interface_type' => 'gpon',
                'slot' => (int) $m[2],
                'port' => (int) $m[3],
            ];
        }

        return [
            'interface_type' => 'unknown',
            'slot' => null,
            'port' => null,
        ];
    }

    private function isInvalidOutput(string $output): bool
    {
        return preg_match('/(%Error|invalid input|invalid parameter|unrecognized command|incomplete command)/i', $output) === 1;
    }

    private function cleanCliOutput(string $output): string
    {
        $cleaned = @iconv('UTF-8', 'UTF-8//IGNORE', $output);
        if ($cleaned === false) {
            $cleaned = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $output) ?? '';
        }

        $cleaned = preg_replace('/\x1B\[[0-?]*[ -\/]*[@-~]/', '', $cleaned) ?? $cleaned;
        $cleaned = str_replace("\x08", '', $cleaned);

        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', '', $cleaned) ?? $cleaned;
    }

    private function cardTypeForSlot(SnmpOlt $olt, int $slot): ?string
    {
        if ($slot < 1) {
            return null;
        }

        return SmartOltCardStatus::query()
            ->where('snmp_olt_id', $olt->id)
            ->where('slot', $slot)
            ->value('cfg_type');
    }

    /**
     * @return array{bps:int|null, pps:int|null}
     */
    private function parseRateLine(string $output, string $label): array
    {
        if (preg_match('/'.preg_quote($label, '/').'\s*:\s*(\d+)\s+Bps\s+(\d+)\s+pps/i', $output, $m)) {
            return [
                'bps' => (int) $m[1],
                'pps' => (int) $m[2],
            ];
        }

        return [
            'bps' => null,
            'pps' => null,
        ];
    }

    private function parsePercent(string $output, string $label): ?float
    {
        return preg_match('/'.preg_quote($label, '/').'\s*:\s*(-?\d+(?:\.\d+)?)%/i', $output, $m)
            ? (float) $m[1]
            : null;
    }

    /**
     * @return array<string, array<string, int|null>>
     */
    private function parseGponCounters(string $output): array
    {
        $counters = [
            'input' => [],
            'output' => [],
        ];
        $inTotalStatistic = false;
        $direction = null;

        foreach (explode("\n", $output) as $line) {
            $line = trim($line);

            if (stripos($line, 'Total statistic:') !== false) {
                $inTotalStatistic = true;

                continue;
            }

            if (! $inTotalStatistic) {
                continue;
            }

            if (preg_match('/^Input\s*:/i', $line)) {
                $direction = 'input';

                continue;
            }

            if (preg_match('/^Output\s*:/i', $line)) {
                $direction = 'output';

                continue;
            }

            if ($direction === null) {
                continue;
            }

            if (preg_match_all('/([A-Za-z0-9-]+)\s*:\s*(N\/A|\d+)/i', $line, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $counters[$direction][$match[1]] = strtoupper($match[2]) === 'N/A' ? null : (int) $match[2];
                }
            }
        }

        return $counters;
    }

    /**
     * @return array<string, string>
     */
    private function extractOpticalFields(string $output): array
    {
        $fields = [];

        foreach (explode("\n", $output) as $line) {
            if (! preg_match_all('/([A-Za-z][A-Za-z-]+)\s*:/', $line, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            $count = count($matches[0]);
            for ($i = 0; $i < $count; $i++) {
                $label = $matches[1][$i][0];
                $valueStart = $matches[0][$i][1] + strlen($matches[0][$i][0]);
                $valueEnd = $i + 1 < $count ? $matches[0][$i + 1][1] : strlen($line);
                $value = trim(substr($line, $valueStart, $valueEnd - $valueStart));

                if ($value !== '') {
                    $fields[$label] = preg_replace('/\s+/', ' ', $value) ?? $value;
                }
            }
        }

        return $fields;
    }

    private function nullableText(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '' || strtoupper($value) === 'N/A') {
            return null;
        }

        return $value;
    }

    private function parseMeasure(?string $value): ?float
    {
        if ($this->nullableText($value) === null) {
            return null;
        }

        return preg_match('/-?\d+(?:\.\d+)?/', (string) $value, $m)
            ? (float) $m[0]
            : null;
    }

    private function parseIntegerMeasure(?string $value): ?int
    {
        $measure = $this->parseMeasure($value);

        return $measure === null ? null : (int) $measure;
    }
}
