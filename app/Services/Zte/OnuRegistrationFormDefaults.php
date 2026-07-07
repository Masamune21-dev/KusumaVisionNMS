<?php

namespace App\Services\Zte;

use App\Models\SmartOltProfile;
use App\Models\SnmpOlt;

/**
 * Nilai default form registrasi ONU ZTE (mode dasar / single-service template) +
 * helper pemilihan profil & saran ONU-id berikutnya. Dipisah dari controller agar
 * dipakai bersama halaman web dan REST API mobile (endpoint register/options).
 */
class OnuRegistrationFormDefaults
{
    /**
     * @return array<string, mixed>
     */
    public function build(SnmpOlt $olt, ?int $slot = null, ?int $port = null, string $serialNumber = '', int $suggestedOnuId = 0): array
    {
        $slot = (int) $slot;
        $port = (int) $port;

        $acs = config('services.acs');

        return [
            'serial_number' => $serialNumber,
            'slot' => $slot ?: null,
            'port' => $port ?: null,
            'onu_id' => $this->suggestNextOnuId($olt, $slot, $port, $suggestedOnuId),
            'customer_name' => '',
            'onu_type' => $this->firstProfileName($olt, 'onu_type', 'ALL-ONT'),
            'tcont_profile' => $this->firstProfileName($olt, 'tcont', 'SERVER'),
            'vlan' => 100,
            'vlan_profile' => $this->firstProfileName($olt, 'vlan', 'ServiceName'),
            'service_name' => 'ServiceName',
            'service_mode' => 'vlanpri',
            'wan_mode' => 'pppoe',
            'pppoe_username' => '',
            'pppoe_password' => '',
            'ip_profile' => $this->firstProfileName($olt, 'ip', 'INTERNET'),
            'static_ip' => '',
            'static_netmask' => '24',
            'tr069_enabled' => false,
            'acs_url' => $acs['url'] ?? 'http://acs.bmkv.net:7547',
            'acs_username' => $acs['username'] ?? 'cms',
            'acs_password' => $acs['password'] ?? '',
            'remote_ont_enabled' => false,
            'remote_ont_id' => 1,
            'remote_ont_mode' => 'forward',
            'remote_ont_protocol' => 'web',
        ];
    }

    public function suggestNextOnuId(SnmpOlt $olt, int $slot, int $port, int $fallback = 1): int
    {
        $safeFallback = $fallback >= 1 && $fallback <= 4096 ? $fallback : 1;

        if ($slot < 1 || $port < 1) {
            return $safeFallback;
        }

        $onus = data_get($olt->last_test_result ?? [], "port_onus.{$slot}_{$port}.onus", []);

        if ($onus === []) {
            return $safeFallback;
        }

        $used = array_fill_keys(
            array_filter(
                array_map('intval', array_column($onus, 'onu_id')),
                fn (int $id) => $id > 0,
            ),
            true,
        );

        for ($id = 1; $id <= 4096; $id++) {
            if (! isset($used[$id])) {
                return $id;
            }
        }

        return $safeFallback;
    }

    public function firstProfileName(SnmpOlt $olt, string $type, string $fallback): string
    {
        return SmartOltProfile::query()
            ->where('profile_type', $type)
            ->where('is_active', true)
            ->where(function ($query) use ($olt) {
                $query->where('snmp_olt_id', $olt->id)
                    ->orWhereNull('snmp_olt_id');
            })
            ->orderBy('name')
            ->value('name') ?? $fallback;
    }
}
