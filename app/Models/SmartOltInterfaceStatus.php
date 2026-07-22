<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmartOltInterfaceStatus extends Model
{
    protected $table = 'smartolt_interface_statuses';

    protected $fillable = [
        'snmp_olt_id',
        'interface',
        'interface_type',
        'slot',
        'port',
        'card_type',
        'hybrid_status',
        'native_vlan',
        'negotiation',
        'speed_mbps',
        'duplex',
        'flow_ctrl',
        'admin_status',
        'link_status',
        'description',
        'onu_capacity',
        'registered_onu_count',
        'input_bps',
        'output_bps',
        'input_pps',
        'output_pps',
        'input_throughput_percent',
        'output_throughput_percent',
        'input_average_throughput_percent',
        'output_average_throughput_percent',
        'input_peak_bps',
        'output_peak_bps',
        'input_peak_pps',
        'output_peak_pps',
        'gpon_counters',
        'tagged_vlans',
        'optical_vendor_name',
        'optical_vendor_pn',
        'optical_vendor_sn',
        'optical_module_type',
        'optical_wavelength_nm',
        'optical_connector',
        'optical_trans_distance',
        'rx_power_dbm',
        'tx_power_dbm',
        'tx_bias_current_ma',
        'laser_rate',
        'temperature_c',
        'supply_voltage_v',
        'optical_thresholds',
        'raw_status',
        'raw_vlan',
        'raw_optical',
        'status_refreshed_at',
        'vlan_refreshed_at',
        'optical_refreshed_at',
        'refreshed_at',
    ];

    protected function casts(): array
    {
        return [
            'slot' => 'integer',
            'port' => 'integer',
            'native_vlan' => 'integer',
            'speed_mbps' => 'integer',
            'onu_capacity' => 'integer',
            'registered_onu_count' => 'integer',
            'input_bps' => 'integer',
            'output_bps' => 'integer',
            'input_pps' => 'integer',
            'output_pps' => 'integer',
            'input_throughput_percent' => 'float',
            'output_throughput_percent' => 'float',
            'input_average_throughput_percent' => 'float',
            'output_average_throughput_percent' => 'float',
            'input_peak_bps' => 'integer',
            'output_peak_bps' => 'integer',
            'input_peak_pps' => 'integer',
            'output_peak_pps' => 'integer',
            'gpon_counters' => 'array',
            'tagged_vlans' => 'array',
            'optical_wavelength_nm' => 'integer',
            'rx_power_dbm' => 'float',
            'tx_power_dbm' => 'float',
            'tx_bias_current_ma' => 'float',
            'temperature_c' => 'float',
            'supply_voltage_v' => 'float',
            'optical_thresholds' => 'array',
            'status_refreshed_at' => 'datetime',
            'vlan_refreshed_at' => 'datetime',
            'optical_refreshed_at' => 'datetime',
            'refreshed_at' => 'datetime',
        ];
    }

    public function olt(): BelongsTo
    {
        return $this->belongsTo(SnmpOlt::class, 'snmp_olt_id');
    }

    /**
     * Peta deskripsi port PON (hasil parse CLI `show interface`) ber-key "slot/port".
     * Dipakai bersama web (`SmartOltController::serializeSnapshot`) dan API v1
     * (`Api\V1\OltController::show`) untuk menempelkan deskripsi ke tiap kartu port.
     *
     * @return array<string, string>
     */
    public static function descriptionsBySlotPort(int $oltId): array
    {
        return self::query()
            ->where('snmp_olt_id', $oltId)
            ->whereNotNull('description')
            ->where('description', '!=', '')
            ->get(['slot', 'port', 'description'])
            ->mapWithKeys(fn (self $row) => ["{$row->slot}/{$row->port}" => $row->description])
            ->all();
    }
}
