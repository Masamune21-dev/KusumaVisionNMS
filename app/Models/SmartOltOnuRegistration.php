<?php

namespace App\Models;

use App\Models\Scopes\DemoScope;
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
        'tr069_enabled',
        'acs_url',
        'acs_username',
        'acs_password',
        'remote_ont_enabled',
        'remote_ont_id',
        'remote_ont_mode',
        'remote_ont_protocol',
        'cli_script',
        'execution_output',
        'execution_error',
        'executed_at',
        'executed_by',
        'status',
        'created_by',
        'is_demo',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new DemoScope);
    }

    protected function casts(): array
    {
        return [
            'slot' => 'integer',
            'is_demo' => 'boolean',
            'port' => 'integer',
            'onu_id' => 'integer',
            'vlan' => 'integer',
            'pppoe_password' => 'encrypted',
            'tr069_enabled' => 'boolean',
            'acs_password' => 'encrypted',
            'remote_ont_enabled' => 'boolean',
            'remote_ont_id' => 'integer',
            'executed_at' => 'datetime',
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

    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }
}
