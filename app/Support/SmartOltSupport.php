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

        foreach (['zte', '3902', 'c300', 'c320'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return self::DRIVER_ZTE;
            }
        }

        return self::DRIVER_UNKNOWN;
    }

    /**
     * @return array<string, mixed>
     */
    public static function capabilities(string $driver): array
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

        return [
            'driver' => self::DRIVER_ZTE,
            'vendor_family' => 'ZTE GPON',
            'pon_label' => 'GPON',
            'port_label' => 'GPON Port',
            'port_name_prefix' => 'gpon-olt_1',
            'onu_interface_pattern' => 'gpon-onu_1/%d/%d:%d',
            'supports_snmp_rx' => true,
            'supports_cli_rx' => true,
            'supports_cli_onu_detail' => true,
            'supports_cli_onu_configure' => true,
            'supports_reboot' => true,
            'reboot_mode' => 'cli',
            'supports_provisioning' => true,
            'supports_separate_description' => true,
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
