<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmartOltProfile extends Model
{
    public const TYPES = ['onu_type', 'tcont', 'vlan', 'ip'];

    protected $table = 'smartolt_profiles';

    protected $fillable = [
        'profile_type',
        'name',
        'vlan',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'vlan' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
