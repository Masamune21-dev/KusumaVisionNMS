<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmartOltFanStatus extends Model
{
    protected $table = 'smartolt_fan_statuses';

    protected $fillable = [
        'snmp_olt_id',
        'rack',
        'shelf',
        'fan_index',
        'fan_name',
        'status',
        'admin_status',
        'oper_status',
        'online_status',
        'level',
        'speed_rpm',
        'speed_percent',
        'raw_line',
        'refreshed_at',
    ];

    protected function casts(): array
    {
        return [
            'rack' => 'integer',
            'shelf' => 'integer',
            'fan_index' => 'integer',
            'admin_status' => 'integer',
            'oper_status' => 'integer',
            'online_status' => 'integer',
            'level' => 'integer',
            'speed_rpm' => 'integer',
            'speed_percent' => 'integer',
            'refreshed_at' => 'datetime',
        ];
    }

    public function olt(): BelongsTo
    {
        return $this->belongsTo(SnmpOlt::class, 'snmp_olt_id');
    }
}
