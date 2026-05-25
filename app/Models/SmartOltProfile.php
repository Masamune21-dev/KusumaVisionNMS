<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmartOltProfile extends Model
{
    public const TYPES = ['onu_type', 'tcont', 'vlan', 'ip'];

    protected $table = 'smartolt_profiles';

    protected $fillable = [
        'snmp_olt_id',
        'profile_type',
        'name',
        'source',
        'vlan',
        'params',
        'notes',
        'is_active',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'vlan' => 'integer',
            'params' => 'array',
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }
}
