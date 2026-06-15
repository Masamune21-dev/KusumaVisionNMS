<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmartOltCardStatus extends Model
{
    protected $table = 'smartolt_card_statuses';

    protected $fillable = [
        'snmp_olt_id',
        'rack',
        'shelf',
        'slot',
        'cfg_type',
        'real_type',
        'port_count',
        'hard_ver',
        'soft_ver',
        'status',
        'cpu_load',
        'mem_load',
        'phy_mem_mb',
        'raw_line',
        'refreshed_at',
    ];

    protected function casts(): array
    {
        return [
            'rack' => 'integer',
            'shelf' => 'integer',
            'slot' => 'integer',
            'port_count' => 'integer',
            'cpu_load' => 'integer',
            'mem_load' => 'integer',
            'phy_mem_mb' => 'integer',
            'refreshed_at' => 'datetime',
        ];
    }

    public function olt(): BelongsTo
    {
        return $this->belongsTo(SnmpOlt::class, 'snmp_olt_id');
    }
}
