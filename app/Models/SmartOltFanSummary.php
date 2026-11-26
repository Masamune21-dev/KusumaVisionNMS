<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SmartOltFanSummary extends Model
{
    protected $table = 'smartolt_fan_summaries';

    protected $fillable = [
        'snmp_olt_id',
        'source',
        'mode',
        'mode_code',
        'temp_c',
        'min_level',
        'thresholds',
        'percents',
        'raw_output',
        'refreshed_at',
    ];

    protected function casts(): array
    {
        return [
            'mode_code' => 'integer',
            'temp_c' => 'integer',
            'min_level' => 'integer',
            'thresholds' => 'array',
            'percents' => 'array',
            'refreshed_at' => 'datetime',
        ];
    }

    public function olt(): BelongsTo
    {
        return $this->belongsTo(SnmpOlt::class, 'snmp_olt_id');
    }

    public function fans(): HasMany
    {
        return $this->hasMany(SmartOltFanStatus::class, 'snmp_olt_id', 'snmp_olt_id')
            ->orderBy('rack')
            ->orderBy('shelf')
            ->orderBy('fan_index');
    }
}
