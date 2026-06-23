<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnuMapPin extends Model
{
    protected $fillable = [
        'snmp_olt_id',
        'slot',
        'port',
        'onu_id',
        'serial_number',
        'latitude',
        'longitude',
        'customer_name',
        'address',
        'phone',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'slot' => 'integer',
            'port' => 'integer',
            'onu_id' => 'integer',
            'latitude' => 'float',
            'longitude' => 'float',
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
