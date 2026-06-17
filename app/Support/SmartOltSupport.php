<?php

namespace App\Support;

use App\Models\SnmpOlt;

class SmartOltSupport
{
    public const DRIVER_ZTE = 'zte';

    public const DRIVER_UNKNOWN = 'unknown';

    public static function driverKey(?SnmpOlt $olt, ?string $sysDescr = null, ?string $sysObjectId = null): string
    {
        $haystack = strtolower(implode(' ', array_filter([
            $olt?->vendor,
            $olt?->name,
            $sysDescr,
            $sysObjectId,
        ])));

        foreach (['zte', '3902', 'c300', 'c320', 'c600'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return self::DRIVER_ZTE;
            }
        }

        return self::DRIVER_UNKNOWN;
    }

    public static function isC600(?SnmpOlt $olt): bool
    {
        if ($olt === null) {
            return false;
        }

        $haystack = strtolower(implode(' ', array_filter([
            $olt->vendor,
            $olt->name,
            data_get($olt->last_test_result, 'system.sys_descr'),
        ])));

        return str_contains($haystack, 'c600');
    }

    public static function onuInterfaceId(int $slot, int $port, int $onuId, bool $isC600 = false): string
    {
        return $isC600
            ? sprintf('gpon-onu_1/1/%d/%d:%d', $slot, $port, $onuId)
            : sprintf('gpon-onu_1/%d/%d:%d', $slot, $port, $onuId);
    }

    public static function gponOltInterface(int $slot, int $port, bool $isC600 = false): string
    {
        return $isC600
            ? sprintf('gpon-olt_1/1/%d/%d', $slot, $port)
            : sprintf('gpon-olt_1/%d/%d', $slot, $port);
    }

    /**
     * @return array<string, mixed>
     */
    public static function capabilities(string $driver, ?SnmpOlt $olt = null): array
    {
        if ($driver !== self::DRIVER_ZTE) {
            return [
                'driver' => self::DRIVER_UNKNOWN,
                'vendor_family' => 'Unknown',
                'supports_provisioning' => false,
                'supports_cli_onu_detail' => false,
                'supports_cli_onu_configure' => false,
                'supports_snmp_rx' => false,
                'supports_reboot' => false,
                'supports_onu_toggle' => false,
                'supports_onu_info_write' => false,
            ];
        }

        $isC600 = self::isC600($olt);

        return [
            'driver' => self::DRIVER_ZTE,
            'vendor_family' => $isC600 ? 'ZTE GPON (C600)' : 'ZTE GPON',
            'pon_label' => 'GPON',
            'port_label' => 'GPON Port',
            'port_name_prefix' => $isC600 ? 'gpon-olt_1/1' : 'gpon-olt_1',
            'onu_interface_pattern' => $isC600 ? 'gpon-onu_1/1/%d/%d:%d' : 'gpon-onu_1/%d/%d:%d',
            'is_c600' => $isC600,
            'supports_snmp_rx' => true,
            'supports_cli_rx' => true,
            'supports_cli_onu_detail' => true,
            'supports_cli_onu_configure' => true,
            'supports_reboot' => true,
            'reboot_mode' => 'cli',
            'supports_provisioning' => true,
            'supports_onu_delete' => true,
            'supports_separate_description' => ! $isC600,
            'supports_onu_info_write' => true,
            'description_mode' => 'snmp',
            'supports_onu_toggle' => true,
            'rx_source_label' => 'Rx ONU (SNMP)',
        ];
    }

    /**
     * @param  array<string, mixed>  $onu
     */
    public static function customerNameFromOnu(array $onu): ?string
    {
        $serial = (string) ($onu['serial_number'] ?? '');

        return self::cleanCustomerName($onu['name'] ?? null, $serial)
            ?? self::cleanCustomerName($onu['description'] ?? null, $serial);
    }

    public static function cleanCustomerName(mixed $value, string $serial = ''): ?string
    {
        $name = trim((string) $value);

        if ($name === '') {
            return null;
        }

        if (preg_match('/\$\$(.*?)\$\$/', $name, $matches)) {
            $name = trim($matches[1]);
        }

        $lower = strtolower($name);

        if (
            $name === ''
            || in_array($lower, ['-', 'n/a', 'na', 'null', 'none'], true)
            || ($serial !== '' && strcasecmp($name, $serial) === 0)
            || str_starts_with($lower, 'gpon-onu_')
        ) {
            return null;
        }

        return $name;
    }
}
