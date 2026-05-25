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
            'rx_source_label' => 'Rx ONU',
        ];
    }
}
