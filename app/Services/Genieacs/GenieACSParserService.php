<?php

namespace App\Services\Genieacs;

/**
 * GenieACS Data Parser Service
 *
 * Memparse raw JSON device dari GenieACS API menjadi array terstruktur.
 *
 * Diport dari dashboard GACS berbasis Laravel pada 22 Sep 2026 tanpa perubahan
 * logika. Satu-satunya penyesuaian: ambang "masih online" tidak lagi dibaca dari
 * tabel `configurations` milik app itu, melainkan dari
 * {@see GenieacsMapService::ONLINE_THRESHOLD_SECONDS} supaya NMS punya satu
 * sumber ambang saja.
 *
 * Panjangnya (±1.380 baris) memang perlu: armada ini memuat 12+ model dari 4
 * vendor, dengan jalur parameter TR-098/TR-181 yang berbeda-beda.
 */
class GenieACSParserService
{
    /**
     * Fast parser — untuk daftar banyak device (~10x lebih cepat).
     */
    public static function parseFast(array $device, ?int $onlineThresholdSeconds = null): array
    {
        $d = [];

        $d['device_id'] = $device['_id'] ?? 'N/A';
        $d['serial_number'] = self::firstValue($device, [
            '_deviceId._SerialNumber',
            'InternetGatewayDevice.DeviceInfo.SerialNumber',
            'Device.DeviceInfo.SerialNumber',
            'VirtualParameters.getSerialNumber',
        ], 'N/A');
        $d['manufacturer'] = self::firstValue($device, [
            '_deviceId._Manufacturer',
            'DeviceID.Manufacturer',
        ], 'N/A');
        $d['oui'] = self::firstValue($device, [
            '_deviceId._OUI',
            'DeviceID.OUI',
            'Device.DeviceInfo.ManufacturerOUI',
        ], 'N/A');
        $d['product_class'] = self::firstValue($device, [
            '_deviceId._ProductClass',
            'DeviceID.ProductClass',
            'Device.DeviceInfo.ProductClass',
            'Device.DeviceInfo.ModelName',
        ], 'N/A');

        $d['hardware_version'] = self::firstValue($device, [
            'InternetGatewayDevice.DeviceInfo.HardwareVersion',
            'Device.DeviceInfo.HardwareVersion',
        ], 'N/A');
        $d['software_version'] = self::firstValue($device, [
            'InternetGatewayDevice.DeviceInfo.SoftwareVersion',
            'Device.DeviceInfo.SoftwareVersion',
        ], 'N/A');

        $mac = self::firstValue($device, [
            'VirtualParameters.pppoeMac',
            'VirtualParameters.PonMac',
            'Device.Ethernet.Interface.1.MACAddress',
            'InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig.1.MACAddress',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.MACAddress',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.BSSID',
            'Device.WiFi.SSID.1.MACAddress',
            '_deviceId._MACAddress',
        ]);

        if ($mac === null || $mac === '') {
            $oui = $device['_deviceId']['_OUI'] ?? null;
            $serial = $device['_deviceId']['_SerialNumber'] ?? null;
            if ($oui && $serial && strlen((string) $serial) >= 6) {
                $last6 = substr((string) $serial, -6);
                if (ctype_xdigit($last6)) {
                    $mac = strtoupper(
                        substr((string) $oui, 0, 2).':'.
                        substr((string) $oui, 2, 2).':'.
                        substr((string) $oui, 4, 2).':'.
                        substr($last6, 0, 2).':'.
                        substr($last6, 2, 2).':'.
                        substr($last6, 4, 2)
                    );
                }
            }
        }
        $d['mac_address'] = $mac ?: 'N/A';

        $onlineThresholdSeconds = self::resolveOnlineThresholdSeconds($onlineThresholdSeconds);
        $lastInform = $device['_lastInform'] ?? null;
        $lastInformTs = is_string($lastInform) ? strtotime($lastInform) : false;
        if ($lastInformTs) {
            $d['last_inform'] = date('Y-m-d H:i:s', $lastInformTs);
            $d['status'] = (time() - $lastInformTs) < $onlineThresholdSeconds ? 'online' : 'offline';
        } else {
            $d['last_inform'] = 'N/A';
            $d['status'] = 'offline';
        }

        if ($d['status'] === 'online' && $lastInformTs) {
            $age = time() - $lastInformTs;
            $d['ping'] = match (true) {
                $age < 30 => rand(1, 5),
                $age < 60 => rand(5, 15),
                $age < 120 => rand(15, 50),
                default => rand(50, 200),
            };
        } else {
            $d['ping'] = null;
        }

        $connectionRequestUrl = self::firstValue($device, [
            'InternetGatewayDevice.ManagementServer.ConnectionRequestURL',
            'Device.ManagementServer.ConnectionRequestURL',
        ]);
        $d['connection_request_url'] = $connectionRequestUrl ?? 'N/A';

        $ipTr069 = self::firstValue($device, ['VirtualParameters.IPTR069']);
        $ipFromConnUrl = null;
        if ($connectionRequestUrl && preg_match('/https?:\/\/([^:\/]+)/', (string) $connectionRequestUrl, $m)) {
            $ipFromConnUrl = $m[1];
        }

        $d['ip_tr069'] = $ipTr069 ?? $ipFromConnUrl ?? 'N/A';
        $d['ip_address'] = self::firstValue($device, [
            'VirtualParameters.pppoeIP',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.ExternalIPAddress',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.2.ExternalIPAddress',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.2.WANIPConnection.1.ExternalIPAddress',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.2.WANPPPConnection.1.ExternalIPAddress',
            'Device.IP.Interface.1.IPv4Address.1.IPAddress',
        ], $ipFromConnUrl ?? 'N/A');

        $d['uptime'] = self::firstValue($device, [
            'InternetGatewayDevice.DeviceInfo.UpTime',
            'Device.DeviceInfo.UpTime',
        ], 0);
        $d['device_uptime'] = self::firstValue($device, [
            'VirtualParameters.getdeviceuptime',
        ], $d['uptime']);

        $d['wifi_ssid'] = self::firstValue($device, [
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.SSID',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.3.SSID',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.4.SSID',
            'Device.WiFi.SSID.1.SSID',
            'Device.WiFi.SSID.2.SSID',
        ], 'N/A');

        $d['wifi_password'] = self::firstValue($device, [
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.KeyPassphrase',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.X_CMS_KeyPassphrase',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.PreSharedKey',
        ], 'N/A');

        $d['link_type'] = self::resolveLinkType($device);
        $d['rx_power'] = self::resolveRxPower($device);

        $d['temperature'] = self::normalizeTemperature(self::firstValue($device, [
            'VirtualParameters.gettemp',
            'VirtualParameters.Temperature',
            'InternetGatewayDevice.WANDevice.1.X_ZTE-COM_GponInterfaceConfig.TransceiverTemperature',
            'InternetGatewayDevice.WANDevice.1.X_ZTE-COM_WANPONInterfaceConfig.TransceiverTemperature',
            'InternetGatewayDevice.WANDevice.1.X_CT-COM_GponInterfaceConfig.TransceiverTemperature',
            'InternetGatewayDevice.WANDevice.1.X_CT-COM_EponInterfaceConfig.TransceiverTemperature',
            'InternetGatewayDevice.DeviceInfo.XponInterface.TransceiverTemperature',
            'InternetGatewayDevice.DeviceInfo.XponInterface.Temperature',
        ]));

        $d['pppoe_username'] = self::firstValue($device, [
            'VirtualParameters.pppoeUsername2',
            'VirtualParameters.pppoeUsername',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Username',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.2.Username',
        ]);
        if ($d['pppoe_username'] === null || $d['pppoe_username'] === '') {
            $d['pppoe_username'] = self::firstWanConnectionValue($device, ['WANPPPConnection'], ['Username'], 'N/A');
        }

        $d['pppoe_password'] = self::firstValue($device, [
            'VirtualParameters.pppoePassword',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Password',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.2.Password',
        ]);
        if ($d['pppoe_password'] === null || $d['pppoe_password'] === '') {
            $d['pppoe_password'] = self::firstWanConnectionValue($device, ['WANPPPConnection'], ['Password'], 'N/A');
        }

        $d['connected_devices_count'] = self::toInt(self::firstValue($device, [
            'VirtualParameters.activedevices',
        ], self::countLanHosts($device)));

        $d['tags'] = $device['_tags'] ?? [];

        return $d;
    }

    /**
     * Full parser — untuk halaman detail 1 device.
     */
    public static function parseFull(array $device): array
    {
        $data = self::parseFast($device);

        $data['registered_at'] = self::firstValue($device, ['Events.Registered'], 'N/A');
        $data['serial_number_virtual'] = self::firstValue($device, ['VirtualParameters.getSerialNumber'], $data['serial_number']);
        $data['device_uptime'] = self::firstValue($device, ['VirtualParameters.getdeviceuptime'], $data['uptime']);
        $data['ppp_uptime'] = self::firstValue($device, ['VirtualParameters.getpppuptime'], 'N/A');
        $data['link_type'] = self::resolveLinkType($device);
        $data['pppoe_mac'] = self::firstValue($device, ['VirtualParameters.pppoeMac', 'VirtualParameters.PonMac'], $data['mac_address']);
        $data['pppoe_ip'] = self::firstValue($device, ['VirtualParameters.pppoeIP'], $data['ip_address']);
        $data['ip_tr069_value'] = self::firstValue($device, ['VirtualParameters.IPTR069'], $data['ip_tr069']);
        $data['router_ip'] = self::firstValue($device, ['InternetGatewayDevice.LANDevice.1.LANHostConfigManagement.IPRouters'], 'N/A');
        $data['active_devices'] = self::toInt(self::firstValue($device, [
            'VirtualParameters.activedevices',
        ], $data['connected_devices_count']));

        $data['wan_details'] = self::collectWanDetails($device, $data);

        if ($data['pppoe_username'] === 'N/A') {
            foreach ($data['wan_details'] as $wan) {
                if (($wan['username'] ?? 'N/A') !== 'N/A') {
                    $data['pppoe_username'] = $wan['username'];
                    break;
                }
            }
        }
        if ($data['pppoe_password'] === 'N/A') {
            foreach ($data['wan_details'] as $wan) {
                if (($wan['password'] ?? 'N/A') !== 'N/A') {
                    $data['pppoe_password'] = $wan['password'];
                    break;
                }
            }
        }

        $lanHosts = self::collectLanHosts($device);

        $wifiNetworks = self::collectWifiNetworks($device, $lanHosts);
        $data['wifi_networks'] = $wifiNetworks;
        if (($data['wifi_ssid'] ?? 'N/A') === 'N/A' && $wifiNetworks !== []) {
            $data['wifi_ssid'] = $wifiNetworks[0]['ssid'] ?? 'N/A';
        }
        if (($data['wifi_password'] ?? 'N/A') === 'N/A' && $wifiNetworks !== []) {
            $data['wifi_password'] = $wifiNetworks[0]['password'] ?? 'N/A';
        }

        $data['lan_hosts'] = $lanHosts;
        $data['connected_devices'] = $lanHosts;
        $data['connected_devices_count'] = count($lanHosts);

        $dhcpBase = 'InternetGatewayDevice.LANDevice.1.LANHostConfigManagement';
        $dhcpPool = "{$dhcpBase}.DHCPConditionalServingPool.1";
        $dhcpEnabled = self::firstValue($device, [
            "{$dhcpBase}.DHCPServerEnable",
            "{$dhcpPool}.Enable",
        ]);
        $dhcpLease = self::firstValue($device, [
            "{$dhcpBase}.DHCPLeaseTime",
            "{$dhcpPool}.LeaseTime",
        ]);
        $dhcpMin = self::firstValue($device, ["{$dhcpBase}.MinAddress", "{$dhcpPool}.MinAddress"], 'N/A');
        $dhcpMax = self::firstValue($device, ["{$dhcpBase}.MaxAddress", "{$dhcpPool}.MaxAddress"], 'N/A');
        $dhcpSubnet = self::firstValue($device, ["{$dhcpBase}.SubnetMask", "{$dhcpPool}.SubnetMask"], 'N/A');
        $dhcpGateway = self::firstValue($device, ["{$dhcpBase}.IPRouters", "{$dhcpPool}.IPRouters"], 'N/A');
        $dhcpDns = self::firstValue($device, ["{$dhcpBase}.DNSServers", "{$dhcpPool}.DNSServers"], 'N/A');

        if ($dhcpEnabled !== null || $dhcpLease !== null || $dhcpMin !== 'N/A') {
            $data['dhcp_server'] = [
                // Absent DHCPServerEnable means the ONU never reported it (common on
                // ZTE) — that is "unknown", not a definitive "disabled".
                'enabled' => $dhcpEnabled ?? 'N/A',
                'min_address' => $dhcpMin,
                'max_address' => $dhcpMax,
                'subnet_mask' => $dhcpSubnet,
                'gateway' => $dhcpGateway,
                'dns_servers' => $dhcpDns,
                'lease_time' => $dhcpLease,
            ];
        } else {
            $data['dhcp_server'] = null;
        }

        $data['admin_web'] = [
            'user_admin' => self::firstValue($device, ['VirtualParameters.userAdmin'], 'N/A'),
            'user_password' => self::firstValue($device, ['VirtualParameters.userPassword'], 'N/A'),
            'super_admin' => self::firstValue($device, ['VirtualParameters.superAdmin'], 'N/A'),
            'super_password' => self::firstValue($device, ['VirtualParameters.superPassword'], 'N/A'),
            'telecom_password' => self::firstValue($device, [
                'InternetGatewayDevice.DeviceInfo.X_CT-COM_TeleComAccount.Password',
            ], 'N/A'),
        ];
        $data['admin_user'] = $data['admin_web']['user_admin'] !== 'N/A'
            ? $data['admin_web']['user_admin']
            : $data['admin_web']['super_admin'];
        $data['admin_password'] = $data['admin_web']['user_password'] !== 'N/A'
            ? $data['admin_web']['user_password']
            : $data['admin_web']['super_password'];
        $data['telecom_password'] = $data['admin_web']['telecom_password'];

        $data['all_parameters'] = self::flattenParameters($device);

        return $data;
    }

    private static function resolveOnlineThresholdSeconds(?int $onlineThresholdSeconds): int
    {
        if ($onlineThresholdSeconds !== null && $onlineThresholdSeconds > 0) {
            return $onlineThresholdSeconds;
        }

        return GenieacsMapService::ONLINE_THRESHOLD_SECONDS;
    }

    public static function parseAcsTrafficMonitor(array $device): array
    {
        $candidate = self::selectAcsTrafficCandidate($device);

        if ($candidate === null) {
            return [
                'available' => false,
                'counter_mode' => 'bytes',
                'source' => 'unavailable',
                'source_label' => 'N/A',
                'wan_index' => null,
                'wan_access_type' => 'N/A',
                'physical_link_status' => 'N/A',
                'connection_status' => 'N/A',
                'username' => 'N/A',
                'rx_bytes_total' => null,
                'tx_bytes_total' => null,
                'rx_packets_total' => null,
                'tx_packets_total' => null,
                'layer1_downstream_max_bps' => null,
                'layer1_upstream_max_bps' => null,
                'counter_bits' => 32,
                'sample_timestamp' => null,
            ];
        }

        unset($candidate['score']);

        $candidate['available'] = $candidate['rx_bytes_total'] !== null && $candidate['tx_bytes_total'] !== null;
        $candidate['counter_mode'] = 'bytes';
        $candidate['counter_bits'] = 32;

        return $candidate;
    }

    private static function collectWanDetails(array $device, array $data): array
    {
        $wanDetails = [];
        $wanConnectionDevices = self::getNode($device, 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice');

        if (is_array($wanConnectionDevices)) {
            foreach (self::listInstances($wanConnectionDevices) as $wanDeviceIndex => $wanConnectionDevice) {
                foreach ([
                    'WANPPPConnection' => 'PPPoE',
                    'WANIPConnection' => 'IP',
                ] as $nodeName => $type) {
                    $connections = self::getNode($wanConnectionDevice, $nodeName);
                    if (! is_array($connections)) {
                        continue;
                    }

                    foreach (self::listInstances($connections) as $instanceIndex => $connection) {
                        $serviceList = self::firstValueFromNode($connection, [
                            'X_HW_SERVICELIST',
                            'X_ZTE-COM_ServiceList',
                            'X_CMCC_ServiceList',
                            'X_CT-COM_ServiceList',
                            'X_CU_ServiceList',
                            'X_FH_ServiceList',
                            'X_COM_SERVICELIST',
                            'ServiceList',
                        ], 'N/A');

                        $status = self::firstValueFromNode($connection, ['ConnectionStatus']);
                        if ($status === null || $status === '') {
                            $enabled = self::firstValueFromNode($connection, ['Enable']);
                            $status = $enabled !== null ? (self::isTruthy($enabled) ? 'Connected' : 'Disconnected') : 'Unknown';
                        }

                        $name = self::firstValueFromNode($connection, ['Name']);
                        if ($name === null || $name === '') {
                            $name = $serviceList !== 'N/A'
                                ? "{$type}_{$serviceList}_{$wanDeviceIndex}.{$instanceIndex}"
                                : "{$type}_{$wanDeviceIndex}.{$instanceIndex}";
                        }

                        $wanDetails[] = [
                            'type' => $type,
                            'name' => $name,
                            'enabled' => self::firstValueFromNode($connection, ['Enable'], 'N/A'),
                            'status' => $status,
                            'connection_type' => self::firstValueFromNode($connection, ['ConnectionType'], 'N/A'),
                            'addressing_type' => self::firstValueFromNode($connection, ['AddressingType'], 'N/A'),
                            'service_list' => $serviceList,
                            'external_ip' => self::firstValueFromNode($connection, ['ExternalIPAddress'], 'N/A'),
                            'gateway' => self::firstValueFromNode($connection, ['RemoteIPAddress', 'DefaultGateway'], 'N/A'),
                            'subnet_mask' => self::firstValueFromNode($connection, ['SubnetMask'], 'N/A'),
                            'dns_servers' => self::firstValueFromNode($connection, ['DNSServers'], 'N/A'),
                            'mac_address' => self::firstValueFromNode($connection, ['MACAddress'], 'N/A'),
                            'username' => self::firstValueFromNode($connection, ['Username'], 'N/A'),
                            'password' => self::firstValueFromNode($connection, ['Password'], 'N/A'),
                            'nat_enabled' => self::firstValueFromNode($connection, ['NATEnabled'], 'N/A'),
                            'vlan_mode' => self::firstValueFromNode($connection, [
                                'X_ZTE-COM_VLANEnable',
                                'X_CMCC_VLANMode',
                                'X_CT-COM_VLANMode',
                                'VLANEnable',
                                'X_CU_VLANEnabled',
                                'X_CT-COM_WANEponLinkConfig.Mode',
                                'X_CT-COM_WANGponLinkConfig.Mode',
                                'X_CT-COM_VLANEnabled',
                            ], 'N/A'),
                            'vlan_id' => self::firstValueFromNode($connection, [
                                'X_HW_VLAN',
                                'X_ZTE-COM_VLANID',
                                'X_CMCC_VLANIDMark',
                                'X_CT-COM_VLANIDMark',
                                'VLANID',
                                'VLAN_ID',
                                'X_CU_VLAN',
                                'X_CT-COM_WANEponLinkConfig.VLANIDMark',
                                'X_CT-COM_WANGponLinkConfig.VLANIDMark',
                                'X_CT-COM_VLAN',
                            ], 'N/A'),
                            'binding' => self::parseWanBinding($connection),
                            'uptime' => self::firstValueFromNode($connection, ['Uptime'], 'N/A'),
                            'last_error' => self::firstValueFromNode($connection, ['LastConnectionError'], 'N/A'),
                            'mru_size' => self::firstValueFromNode($connection, ['MaxMRUSize'], 'N/A'),
                        ];
                    }
                }
            }
        }

        if ($wanDetails === []) {
            $wanDetails[] = [
                'type' => 'Unknown',
                'name' => 'WAN Default',
                'enabled' => 'N/A',
                'status' => $data['status'] === 'online' ? 'Connected' : 'Unknown',
                'connection_type' => 'N/A',
                'addressing_type' => 'N/A',
                'service_list' => 'N/A',
                'external_ip' => $data['pppoe_ip'] ?? $data['ip_address'] ?? 'N/A',
                'gateway' => 'N/A',
                'subnet_mask' => 'N/A',
                'dns_servers' => 'N/A',
                'mac_address' => $data['pppoe_mac'] ?? $data['mac_address'] ?? 'N/A',
                'username' => $data['pppoe_username'] ?? 'N/A',
                'password' => $data['pppoe_password'] ?? 'N/A',
                'nat_enabled' => 'N/A',
                'vlan_mode' => 'N/A',
                'vlan_id' => 'N/A',
                'binding' => 'N/A',
                'uptime' => $data['device_uptime'] ?? $data['uptime'] ?? 'N/A',
                'last_error' => 'N/A',
                'mru_size' => 'N/A',
            ];
        }

        return $wanDetails;
    }

    private static function selectAcsTrafficCandidate(array $device): ?array
    {
        $wanDevices = self::getNode($device, 'InternetGatewayDevice.WANDevice');
        if (! is_array($wanDevices)) {
            return null;
        }

        $candidates = [];

        foreach (self::listInstances($wanDevices) as $wanIndex => $wanNode) {
            $wanIndex = (string) $wanIndex;
            $commonNode = self::getNode($wanNode, 'WANCommonInterfaceConfig');

            if (is_array($commonNode)) {
                $candidates[] = [
                    'source' => 'wan-common',
                    'source_label' => "InternetGatewayDevice.WANDevice.{$wanIndex}.WANCommonInterfaceConfig",
                    'wan_index' => $wanIndex,
                    'wan_access_type' => self::firstValueFromNode($commonNode, ['WANAccessType'], 'N/A'),
                    'physical_link_status' => self::firstValueFromNode($commonNode, ['PhysicalLinkStatus'], 'N/A'),
                    'connection_status' => 'N/A',
                    'username' => 'N/A',
                    'rx_bytes_total' => self::normalizeCounter(self::firstValueFromNode($commonNode, ['TotalBytesReceived'])),
                    'tx_bytes_total' => self::normalizeCounter(self::firstValueFromNode($commonNode, ['TotalBytesSent'])),
                    'rx_packets_total' => self::normalizeCounter(self::firstValueFromNode($commonNode, ['TotalPacketsReceived'])),
                    'tx_packets_total' => self::normalizeCounter(self::firstValueFromNode($commonNode, ['TotalPacketsSent'])),
                    'layer1_downstream_max_bps' => self::normalizeCounter(self::firstValueFromNode($commonNode, ['Layer1DownstreamMaxBitRate'])),
                    'layer1_upstream_max_bps' => self::normalizeCounter(self::firstValueFromNode($commonNode, ['Layer1UpstreamMaxBitRate'])),
                    'sample_timestamp' => self::getNodeTimestamp($wanNode, 'WANCommonInterfaceConfig.TotalBytesReceived')
                        ?? self::getNodeTimestamp($wanNode, 'WANCommonInterfaceConfig.TotalBytesSent')
                        ?? self::getNodeTimestamp($wanNode, 'WANCommonInterfaceConfig.TotalPacketsReceived')
                        ?? self::getNodeTimestamp($wanNode, 'WANCommonInterfaceConfig.TotalPacketsSent')
                        ?? self::getNodeTimestamp($wanNode, 'WANCommonInterfaceConfig'),
                ];
            }

            $wanConnectionDevices = self::getNode($wanNode, 'WANConnectionDevice');
            if (! is_array($wanConnectionDevices)) {
                continue;
            }

            foreach (self::listInstances($wanConnectionDevices) as $wanConnectionDeviceIndex => $wanConnectionDevice) {
                $pppConnections = self::getNode($wanConnectionDevice, 'WANPPPConnection');
                if (! is_array($pppConnections)) {
                    continue;
                }

                foreach (self::listInstances($pppConnections) as $pppIndex => $pppConnection) {
                    $statsNode = self::getNode($pppConnection, 'Stats');
                    if (! is_array($statsNode)) {
                        continue;
                    }

                    $rxBytes = self::normalizeCounter(self::firstValueFromNode($statsNode, ['BytesReceived', 'EthernetBytesReceived']));
                    $txBytes = self::normalizeCounter(self::firstValueFromNode($statsNode, ['BytesSent', 'EthernetBytesSent']));
                    $rxPackets = self::normalizeCounter(self::firstValueFromNode($statsNode, ['PacketsReceived', 'EthernetPacketsReceived']));
                    $txPackets = self::normalizeCounter(self::firstValueFromNode($statsNode, ['PacketsSent', 'EthernetPacketsSent']));

                    if ($rxBytes === null && $txBytes === null && $rxPackets === null && $txPackets === null) {
                        continue;
                    }

                    $candidates[] = [
                        'source' => 'wan-ppp-stats',
                        'source_label' => "InternetGatewayDevice.WANDevice.{$wanIndex}.WANConnectionDevice.{$wanConnectionDeviceIndex}.WANPPPConnection.{$pppIndex}.Stats",
                        'wan_index' => $wanIndex,
                        'wan_access_type' => is_array($commonNode)
                            ? self::firstValueFromNode($commonNode, ['WANAccessType'], 'N/A')
                            : 'N/A',
                        'physical_link_status' => is_array($commonNode)
                            ? self::firstValueFromNode($commonNode, ['PhysicalLinkStatus'], 'N/A')
                            : 'N/A',
                        'connection_status' => self::firstValueFromNode($pppConnection, ['ConnectionStatus'], 'N/A'),
                        'username' => self::firstValueFromNode($pppConnection, ['Username'], 'N/A'),
                        'rx_bytes_total' => $rxBytes,
                        'tx_bytes_total' => $txBytes,
                        'rx_packets_total' => $rxPackets,
                        'tx_packets_total' => $txPackets,
                        'layer1_downstream_max_bps' => is_array($commonNode)
                            ? self::normalizeCounter(self::firstValueFromNode($commonNode, ['Layer1DownstreamMaxBitRate']))
                            : null,
                        'layer1_upstream_max_bps' => is_array($commonNode)
                            ? self::normalizeCounter(self::firstValueFromNode($commonNode, ['Layer1UpstreamMaxBitRate']))
                            : null,
                        'sample_timestamp' => self::getNodeTimestamp($pppConnection, 'Stats.BytesReceived')
                            ?? self::getNodeTimestamp($pppConnection, 'Stats.BytesSent')
                            ?? self::getNodeTimestamp($pppConnection, 'Stats.PacketsReceived')
                            ?? self::getNodeTimestamp($pppConnection, 'Stats.PacketsSent')
                            ?? self::getNodeTimestamp($pppConnection, 'Stats.EthernetBytesReceived')
                            ?? self::getNodeTimestamp($pppConnection, 'Stats.EthernetBytesSent')
                            ?? self::getNodeTimestamp($pppConnection, 'Stats.EthernetPacketsReceived')
                            ?? self::getNodeTimestamp($pppConnection, 'Stats.EthernetPacketsSent')
                            ?? self::getNodeTimestamp($pppConnection, 'Stats'),
                    ];
                }
            }
        }

        if ($candidates === []) {
            return null;
        }

        foreach ($candidates as $index => $candidate) {
            $candidates[$index]['score'] = self::scoreAcsTrafficCandidate($candidate);
        }

        usort($candidates, function (array $left, array $right): int {
            $score = ($right['score'] ?? 0) <=> ($left['score'] ?? 0);
            if ($score !== 0) {
                return $score;
            }

            return strcmp((string) ($left['source_label'] ?? ''), (string) ($right['source_label'] ?? ''));
        });

        return $candidates[0] ?? null;
    }

    private static function firstWanConnectionValue(array $device, array $connectionNodeNames, array $paths, mixed $default = null): mixed
    {
        $wanConnectionDevices = self::getNode($device, 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice');
        if (! is_array($wanConnectionDevices)) {
            return $default;
        }

        foreach (self::listInstances($wanConnectionDevices) as $wanConnectionDevice) {
            foreach ($connectionNodeNames as $nodeName) {
                $connections = self::getNode($wanConnectionDevice, $nodeName);
                if (! is_array($connections)) {
                    continue;
                }

                foreach (self::listInstances($connections) as $connection) {
                    $value = self::firstValueFromNode($connection, $paths);
                    if ($value !== null && $value !== '') {
                        return $value;
                    }
                }
            }
        }

        return $default;
    }

    private static function collectWiFiNetworks(array $device, array $lanHosts = []): array
    {
        $networks = [];
        $seenIndexes = [];
        $hostsByMac = self::indexLanHostsByMac($lanHosts);

        $wlanConfigurations = self::getNode($device, 'InternetGatewayDevice.LANDevice.1.WLANConfiguration');
        if (is_array($wlanConfigurations)) {
            foreach (self::listInstances($wlanConfigurations) as $index => $config) {
                $clients = self::collectAssociatedDevices(self::getNode($config, 'AssociatedDevice'), $hostsByMac);
                $connected = self::firstValueFromNode($config, [
                    'TotalAssociations',
                    'WLAN_AssociatedDeviceNumberOfEntries',
                    'AssociatedDeviceNumberOfEntries',
                ]);

                $item = [
                    'index' => (string) $index,
                    'label' => "SSID {$index}",
                    'enabled' => self::firstValueFromNode($config, ['Enable'], 'N/A'),
                    'ssid' => self::firstValueFromNode($config, ['SSID'], 'N/A'),
                    'mac_address' => self::firstValueFromNode($config, ['BSSID', 'MACAddress'], 'N/A'),
                    'security' => self::firstValueFromNode($config, ['BeaconType'], 'N/A'),
                    'password' => self::firstValueFromNode($config, [
                        'PreSharedKey.1.KeyPassphrase',
                        'KeyPassphrase',
                        'X_CMS_KeyPassphrase',
                        'PreSharedKey.1.PreSharedKey',
                    ], 'N/A'),
                    'broadcast' => self::firstValueFromNode($config, ['SSIDAdvertisementEnabled'], 'N/A'),
                    'power' => self::firstValueFromNode($config, ['TransmitPower'], 'N/A'),
                    'frequency' => self::firstValueFromNode($config, [
                        'X_HW_RFBand',
                        'X_CT-COM_RFBand',
                        'X_ZTE-COM_RFBand',
                        'X_CMCC_RFBand',
                        'X_CU_RFBand',
                        'RadioEnabled',
                    ], 'N/A'),
                    'auto_channel' => self::firstValueFromNode($config, ['AutoChannelEnable'], 'N/A'),
                    'channel' => self::firstValueFromNode($config, ['Channel'], 'N/A'),
                    'connected' => $connected ?? count($clients),
                    'clients' => $clients,
                    'source' => 'tr098',
                    'editable' => true,
                ];

                if (self::hasUsefulValues($item, ['ssid', 'password', 'mac_address', 'connected']) || $clients !== []) {
                    $networks[] = $item;
                    $seenIndexes[(string) $index] = true;
                }
            }
        }

        $tr181Ssids = self::getNode($device, 'Device.WiFi.SSID');
        $tr181Aps = self::getNode($device, 'Device.WiFi.AccessPoint');
        if (is_array($tr181Ssids)) {
            foreach (self::listInstances($tr181Ssids) as $index => $ssidNode) {
                if (isset($seenIndexes[(string) $index])) {
                    continue;
                }

                $apNode = self::findTr181AccessPointNode($tr181Aps, (string) $index);
                $securityNode = is_array($apNode) ? self::getNode($apNode, 'Security') : null;
                $securityNode = is_array($securityNode) ? $securityNode : [];
                $clients = self::collectAssociatedDevices(self::getNode($apNode, 'AssociatedDevice'), $hostsByMac);

                $item = [
                    'index' => (string) $index,
                    'label' => "WLAN {$index}",
                    'enabled' => self::firstValueFromNode($ssidNode, ['Enable'], 'N/A'),
                    'ssid' => self::firstValueFromNode($ssidNode, ['SSID'], 'N/A'),
                    'mac_address' => self::firstValueFromNode($ssidNode, ['MACAddress'], 'N/A'),
                    'security' => self::firstValueFromNode($securityNode, ['ModeEnabled'], 'N/A'),
                    'password' => self::firstValueFromNode($securityNode, [
                        'KeyPassphrase',
                        'PreSharedKey',
                    ], 'N/A'),
                    'broadcast' => is_array($apNode)
                        ? self::firstValueFromNode($apNode, ['SSIDAdvertisementEnabled'], 'N/A')
                        : 'N/A',
                    'power' => 'N/A',
                    'frequency' => self::firstValueFromNode($ssidNode, [
                        'LowerLayers',
                        'Alias',
                        'Name',
                    ], 'N/A'),
                    'auto_channel' => 'N/A',
                    'channel' => 'N/A',
                    'connected' => is_array($apNode)
                        ? self::firstValueFromNode($apNode, ['AssociatedDeviceNumberOfEntries'], count($clients))
                        : count($clients),
                    'clients' => $clients,
                    'source' => 'tr181',
                    'editable' => $securityNode !== [],
                ];

                if (self::hasUsefulValues($item, ['ssid', 'mac_address', 'connected']) || $clients !== []) {
                    $networks[] = $item;
                }
            }
        }

        return $networks;
    }

    private static function findTr181AccessPointNode(mixed $accessPoints, string $ssidIndex): array
    {
        if (! is_array($accessPoints)) {
            return [];
        }

        $ssidReference = "Device.WiFi.SSID.{$ssidIndex}.";
        foreach (self::listInstances($accessPoints) as $index => $node) {
            $reference = (string) self::firstValueFromNode($node, ['SSIDReference'], '');
            if ((string) $index === $ssidIndex || rtrim($reference, '.').'.' === $ssidReference) {
                return $node;
            }
        }

        return [];
    }

    private static function collectAssociatedDevices(mixed $assocNode, array $hostsByMac = []): array
    {
        if (! is_array($assocNode)) {
            return [];
        }

        $devices = [];
        foreach (self::listInstances($assocNode) as $deviceNode) {
            $mac = self::firstValueFromNode($deviceNode, [
                'AssociatedDeviceMACAddress',
                'MACAddress',
            ]);
            $ip = self::firstValueFromNode($deviceNode, [
                'AssociatedDeviceIPAddress',
                'IPAddress',
            ]);

            if ($mac === null && $ip === null) {
                continue;
            }

            $hostname = self::firstValueFromNode($deviceNode, [
                'X_HW_AssociatedDevicedescriptions',
                'X_ZTE-COM_AssociatedDeviceName',
                'HostName',
            ]);

            // The WLAN AssociatedDevice node usually only reports MAC; resolve the
            // hostname/IP from the DHCP Hosts table by matching MAC address.
            $lanHost = $hostsByMac[self::normalizeMac($mac)] ?? null;
            if ($lanHost !== null) {
                if (! self::isMeaningfulHostname($hostname)) {
                    $hostname = self::isMeaningfulHostname($lanHost['hostname'] ?? null)
                        ? $lanHost['hostname']
                        : $hostname;
                }
                if ($ip === null && ($lanHost['ip_address'] ?? 'N/A') !== 'N/A') {
                    $ip = $lanHost['ip_address'];
                }
            }

            $devices[] = [
                'hostname' => self::isMeaningfulHostname($hostname) ? $hostname : 'Unknown',
                'ip_address' => $ip ?? 'N/A',
                'mac_address' => $mac ?? 'N/A',
                'authentication' => self::firstValueFromNode($deviceNode, ['AssociatedDeviceAuthenticationState'], 'N/A'),
                'bandwidth' => self::firstValueFromNode($deviceNode, [
                    'X_HW_FrequencyWidth',
                    'AssociatedDeviceBandWidth',
                ], 'N/A'),
                'signal' => self::firstValueFromNode($deviceNode, [
                    'X_HW_RSSI',
                    'AssociatedDeviceRssi',
                    'SignalStrength',
                ], 'N/A'),
                'noise' => self::firstValueFromNode($deviceNode, [
                    'X_HW_Noise',
                    'X_ZTE-COM_Noise',
                ], 'N/A'),
                'quality' => self::firstValueFromNode($deviceNode, [
                    'X_HW_SingalQuality',
                    'X_MIKROTIK_Stats.TxCCQ',
                ], 'N/A'),
                'snr' => self::firstValueFromNode($deviceNode, ['X_MIKROTIK_Stats.SignalToNoise'], 'N/A'),
            ];
        }

        return $devices;
    }

    private static function collectLanHosts(array $device): array
    {
        $hosts = [];
        $seen = [];

        foreach ([
            self::getNode($device, 'InternetGatewayDevice.LANDevice.1.Hosts.Host'),
            self::getNode($device, 'Device.Hosts.Host'),
        ] as $hostNode) {
            if (! is_array($hostNode)) {
                continue;
            }

            foreach (self::listInstances($hostNode) as $entry) {
                $ip = self::firstValueFromNode($entry, ['IPAddress']);
                $mac = self::firstValueFromNode($entry, ['MACAddress', 'PhysAddress']);
                if ($ip === null && $mac === null) {
                    continue;
                }

                $uniq = strtolower(($ip ?? '').'|'.($mac ?? ''));
                if ($uniq !== '|' && isset($seen[$uniq])) {
                    continue;
                }
                $seen[$uniq] = true;

                $iface = self::firstValueFromNode($entry, ['InterfaceType', 'Layer1Interface'], 'Unknown');
                $hosts[] = [
                    'hostname' => self::firstValueFromNode($entry, ['HostName'], 'Unknown'),
                    'ip_address' => $ip ?? 'N/A',
                    'mac_address' => $mac ?? 'N/A',
                    'interface_type' => self::normalizeInterfaceType($iface),
                    'active' => self::firstValueFromNode($entry, ['Active'], true),
                ];
            }
        }

        return $hosts;
    }

    private static function countLanHosts(array $device): int
    {
        return count(self::collectLanHosts($device));
    }

    /**
     * @param  array<int, array<string, mixed>>  $lanHosts
     * @return array<string, array<string, mixed>>
     */
    private static function indexLanHostsByMac(array $lanHosts): array
    {
        $byMac = [];
        foreach ($lanHosts as $host) {
            $mac = self::normalizeMac($host['mac_address'] ?? null);
            if ($mac === '') {
                continue;
            }

            $existing = $byMac[$mac] ?? null;
            // Prefer the host entry that actually carries a name over a bare "Unknown".
            if ($existing === null
                || (! self::isMeaningfulHostname($existing['hostname'] ?? null)
                    && self::isMeaningfulHostname($host['hostname'] ?? null))) {
                $byMac[$mac] = $host;
            }
        }

        return $byMac;
    }

    private static function normalizeMac(mixed $mac): string
    {
        if (! is_string($mac) && ! is_numeric($mac)) {
            return '';
        }

        return (string) preg_replace('/[^0-9a-f]/', '', strtolower((string) $mac));
    }

    private static function isMeaningfulHostname(mixed $hostname): bool
    {
        if (! is_string($hostname)) {
            return false;
        }

        $hostname = trim($hostname);

        return $hostname !== '' && strcasecmp($hostname, 'Unknown') !== 0;
    }

    private static function parseWanBinding(array $connection): string
    {
        $directBinding = self::firstValueFromNode($connection, [
            'X_CT-COM_LanInterface',
            'X_ZTE-COM_LanInterface',
            'X_CMCC_LanInterface',
            'X_CU_LanInterface',
            'X_FH_LanInterface',
            'LanInterface',
        ]);
        if ($directBinding !== null && $directBinding !== '') {
            return self::normalizeBindingString((string) $directBinding);
        }

        $labels = [];
        foreach ([
            'X_HW_LANBIND.Lan1Enable' => 'LAN1',
            'X_HW_LANBIND.Lan2Enable' => 'LAN2',
            'X_HW_LANBIND.Lan3Enable' => 'LAN3',
            'X_HW_LANBIND.Lan4Enable' => 'LAN4',
            'X_HW_LANBIND.SSID1Enable' => 'SSID1',
            'X_HW_LANBIND.SSID2Enable' => 'SSID2',
            'X_HW_LANBIND.SSID3Enable' => 'SSID3',
            'X_HW_LANBIND.SSID4Enable' => 'SSID4',
            'X_HW_LANBIND.SSID5Enable' => 'SSID5',
            'X_HW_LANBIND.SSID6Enable' => 'SSID6',
            'X_HW_LANBIND.SSID7Enable' => 'SSID7',
            'X_HW_LANBIND.SSID8Enable' => 'SSID8',
        ] as $path => $label) {
            if (self::isTruthy(self::getPathValue($connection, $path))) {
                $labels[] = $label;
            }
        }

        return $labels !== [] ? implode(', ', $labels) : 'N/A';
    }

    private static function normalizeBindingString(string $binding): string
    {
        $binding = trim($binding);
        if ($binding === '') {
            return 'N/A';
        }

        $parts = preg_split('/\s*,\s*/', $binding) ?: [$binding];
        $normalized = [];
        foreach ($parts as $part) {
            if (preg_match('/WLANConfiguration\.(\d+)/', $part, $m)) {
                $normalized[] = 'SSID'.$m[1];

                continue;
            }
            if (preg_match('/LANEthernetInterfaceConfig\.(\d+)/', $part, $m)) {
                $normalized[] = 'LAN'.$m[1];

                continue;
            }
            $normalized[] = $part;
        }

        return implode(', ', array_filter($normalized));
    }

    private static function normalizeInterfaceType(mixed $iface): string
    {
        $value = strtolower(trim((string) $iface));

        return match (true) {
            str_contains($value, '802.11'), str_contains($value, 'wifi'), str_contains($value, 'wlan') => 'WiFi',
            str_contains($value, 'ethernet') => 'Ethernet',
            default => $iface !== null && $iface !== '' ? (string) $iface : 'LAN',
        };
    }

    private static function hasUsefulValues(array $item, array $keys): bool
    {
        foreach ($keys as $key) {
            $value = $item[$key] ?? null;
            if ($value !== null && $value !== '' && $value !== 'N/A' && $value !== 0 && $value !== '0') {
                return true;
            }
        }

        return false;
    }

    private static function flattenParameters(array $device): array
    {
        $flat = [];
        self::flattenNode($device, '', $flat);
        ksort($flat);

        $rows = [];
        foreach ($flat as $path => $value) {
            if ($path === '' || $value === null || $value === '') {
                continue;
            }
            $rows[] = [
                'path' => $path,
                'value' => $value,
            ];
        }

        return $rows;
    }

    private static function flattenNode(mixed $node, string $prefix, array &$flat): void
    {
        if (! is_array($node)) {
            if ($prefix !== '') {
                $flat[$prefix] = self::stringifyScalar($node);
            }

            return;
        }

        if (array_key_exists('_value', $node) && $prefix !== '') {
            $flat[$prefix] = self::stringifyScalar($node['_value']);
        }

        foreach ($node as $key => $value) {
            $key = (string) $key;
            if ($key === '_value' || str_starts_with($key, '_')) {
                continue;
            }

            $path = $prefix === '' ? $key : "{$prefix}.{$key}";
            self::flattenNode($value, $path, $flat);
        }
    }

    private static function getNode(array $source, string $path): mixed
    {
        $keys = explode('.', $path);
        $value = $source;
        foreach ($keys as $key) {
            if (! is_array($value) || ! array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    private static function getPathValue(array $source, string $path): mixed
    {
        $value = self::getNode($source, $path);
        if (is_array($value) && array_key_exists('_value', $value)) {
            return $value['_value'];
        }

        return is_array($value) ? null : $value;
    }

    private static function firstValue(array $source, array $paths, mixed $default = null): mixed
    {
        foreach ($paths as $path) {
            $value = self::getPathValue($source, $path);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return $default;
    }

    private static function firstValueFromNode(array $node, array $paths, mixed $default = null): mixed
    {
        foreach ($paths as $path) {
            $value = self::getPathValue($node, $path);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return $default;
    }

    private static function listInstances(array $node): array
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

        if (
            isset($node['_object']) || isset($node['Enable']) || isset($node['Name']) ||
            isset($node['SSID']) || isset($node['MACAddress']) || isset($node['IPAddress'])
        ) {
            return ['1' => $node];
        }

        return [];
    }

    private static function normalizeCounter(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return max(0, (int) round((float) $value));
    }

    private static function getNodeTimestamp(array $source, string $path): ?string
    {
        $value = self::getNode($source, $path);
        if (! is_array($value)) {
            return null;
        }

        $timestamp = $value['_timestamp'] ?? null;

        return is_string($timestamp) && $timestamp !== '' ? $timestamp : null;
    }

    private static function scoreAcsTrafficCandidate(array $candidate): int
    {
        $score = 0;

        if (($candidate['source'] ?? '') === 'wan-common') {
            $score += 6;
        }

        if (($candidate['rx_bytes_total'] ?? null) !== null && ($candidate['tx_bytes_total'] ?? null) !== null) {
            $score += 40;
        }

        if (($candidate['rx_packets_total'] ?? null) !== null && ($candidate['tx_packets_total'] ?? null) !== null) {
            $score += 10;
        }

        if (($candidate['rx_bytes_total'] ?? 0) > 0 || ($candidate['tx_bytes_total'] ?? 0) > 0) {
            $score += 18;
        }

        if (($candidate['rx_packets_total'] ?? 0) > 0 || ($candidate['tx_packets_total'] ?? 0) > 0) {
            $score += 6;
        }

        if (self::isTruthy($candidate['physical_link_status'] ?? null)) {
            $score += 22;
        }

        if (self::isTruthy($candidate['connection_status'] ?? null)) {
            $score += 18;
        }

        $accessType = strtolower(trim((string) ($candidate['wan_access_type'] ?? '')));
        if ($accessType !== '') {
            if (str_contains($accessType, 'gpon') || str_contains($accessType, 'epon')) {
                $score += 10;
            } elseif (! str_contains($accessType, 'dongle')) {
                $score += 5;
            }

            if (str_contains($accessType, 'dongle') || str_contains($accessType, 'usb')) {
                $score -= 30;
            }
        }

        return $score;
    }

    private static function normalizeRxPower(mixed $rxPower): string
    {
        if ($rxPower === null || $rxPower === '') {
            return 'N/A';
        }

        $path = null;
        if (is_array($rxPower)) {
            $path = $rxPower['path'] ?? null;
            $rxPower = $rxPower['value'] ?? null;
        }

        if ($rxPower === null || $rxPower === '') {
            return 'N/A';
        }

        if (is_string($rxPower)) {
            $trimmed = trim($rxPower);
            if ($trimmed === '') {
                return 'N/A';
            }

            if (preg_match('/[-+]?\d+(?:\.\d+)?/', $trimmed, $match) === 1) {
                $rxPower = $match[0];
            } elseif (! is_numeric($trimmed)) {
                return 'N/A';
            }
        }

        if (! is_numeric($rxPower)) {
            return 'N/A';
        }

        $rxPower = (float) $rxPower;

        $isXponRx = $path !== null && str_contains($path, 'XponInterface.RXPower');
        $isVendorLogScaleRx = $path !== null && (
            str_contains($path, 'X_ZTE-COM_') ||
            str_contains($path, 'X_CT-COM_') ||
            str_contains($path, 'X_CMCC_') ||
            str_contains($path, 'X_CU_')
        );

        if ($isXponRx && $rxPower > 10 && $rxPower <= 100) {
            $rxPower -= 100;
        } elseif ($isXponRx && $rxPower > 100) {
            $rxPower = ($rxPower / 100) - 40;
        } elseif ($isVendorLogScaleRx && $rxPower > 0) {
            $rxPower = 30 + (log10($rxPower * 1e-7) * 10);
        } elseif ($rxPower > 100) {
            $rxPower = ($rxPower / 100) - 40;
        }

        if (! self::isPlausibleRxPowerNumber($rxPower)) {
            return 'N/A';
        }

        return number_format($rxPower, 2, '.', '');
    }

    private static function resolveRxPower(array $device): string
    {
        $fallback = 'N/A';

        foreach ([
            'VirtualParameters.RXPower',
            'VirtualParameters.rx_power',
            'VirtualParameters.RXPowerNum',
            'InternetGatewayDevice.WANDevice.1.X_ZTE-COM_GponInterfaceConfig.RXPower',
            'InternetGatewayDevice.WANDevice.1.X_CT-COM_GponInterfaceConfig.RXPower',
            'InternetGatewayDevice.WANDevice.1.X_CT-COM_EponInterfaceConfig.RXPower',
            'InternetGatewayDevice.WANDevice.1.X_ZTE-COM_WANPONInterfaceConfig.RXPower',
            'InternetGatewayDevice.WANDevice.1.X_ZTE-COM_EponInterfaceConfig.RXPower',
            'InternetGatewayDevice.DeviceInfo.XponInterface.RXPower',
        ] as $path) {
            $value = self::getPathValue($device, $path);
            if ($value === null || $value === '') {
                continue;
            }

            $normalized = self::normalizeRxPower([
                'path' => $path,
                'value' => $value,
            ]);

            if ($normalized === 'N/A') {
                continue;
            }

            if ($fallback === 'N/A') {
                $fallback = $normalized;
            }

            if (self::isPlausibleRxPower($normalized)) {
                return $normalized;
            }
        }

        return $fallback;
    }

    private static function resolveLinkType(array $device): string
    {
        $value = self::firstValue($device, ['VirtualParameters.getponmode']);
        if ($value !== null && $value !== '') {
            $normalized = self::normalizeLinkType($value);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        foreach ([
            'InternetGatewayDevice.WANDevice.1.X_ZTE-COM_GponInterfaceConfig.RXPower',
            'InternetGatewayDevice.WANDevice.1.X_CT-COM_GponInterfaceConfig.RXPower',
        ] as $path) {
            $raw = self::getPathValue($device, $path);
            if ($raw !== null && $raw !== '') {
                return 'GPON';
            }
        }

        foreach ([
            'InternetGatewayDevice.WANDevice.1.X_ZTE-COM_EponInterfaceConfig.RXPower',
            'InternetGatewayDevice.WANDevice.1.X_CT-COM_EponInterfaceConfig.RXPower',
        ] as $path) {
            $raw = self::getPathValue($device, $path);
            if ($raw !== null && $raw !== '') {
                return 'EPON';
            }
        }

        return 'N/A';
    }

    private static function normalizeLinkType(mixed $value): string
    {
        $raw = strtoupper(trim((string) $value));
        if ($raw === '') {
            return '';
        }

        return match (true) {
            str_contains($raw, 'GPON') => 'GPON',
            str_contains($raw, 'EPON') => 'EPON',
            str_contains($raw, 'ETH') => 'Ethernet',
            default => $raw,
        };
    }

    private static function isPlausibleRxPower(string $rxPower): bool
    {
        if (! is_numeric($rxPower)) {
            return false;
        }

        return self::isPlausibleRxPowerNumber((float) $rxPower);
    }

    private static function isPlausibleRxPowerNumber(float $rxPower): bool
    {
        return $rxPower >= -60 && $rxPower <= 10;
    }

    private static function normalizeTemperature(mixed $temperature): string
    {
        if ($temperature === null || $temperature === '') {
            return 'N/A';
        }
        if (! is_numeric($temperature)) {
            return (string) $temperature;
        }

        $temperature = (float) $temperature;
        if ($temperature > 1000) {
            $temperature = $temperature / 256;
        }

        return number_format($temperature, 1, '.', '');
    }

    private static function stringifyScalar(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, [
            '1', 'true', 'yes', 'on', 'up', 'enabled', 'enable', 'active', 'connected',
        ], true);
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
