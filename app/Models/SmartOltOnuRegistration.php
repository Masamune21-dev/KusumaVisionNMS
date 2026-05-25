<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmartOltOnuRegistration extends Model
{
    protected $table = 'smartolt_onu_registrations';

    protected $fillable = [
        'snmp_olt_id',
        'serial_number',
        'slot',
        'port',
        'onu_id',
        'pon_port',
        'oid_index',
        'customer_name',
        'onu_type',
        'tcont_profile',
        'vlan',
        'vlan_profile',
        'service_name',
        'wan_mode',
        'pppoe_username',
        'pppoe_password',
        'ip_profile',
        'static_ip',
        'static_netmask',
        'cli_script',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'slot' => 'integer',
            'port' => 'integer',
            'onu_id' => 'integer',
            'vlan' => 'integer',
            'pppoe_password' => 'encrypted',
        ];
    }

    public function olt(): BelongsTo
    {
        return $this->belongsTo(SnmpOlt::class, 'snmp_olt_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
