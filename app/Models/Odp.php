<?php

namespace App\Models;

use App\Models\Scopes\PartnerOltScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Odp extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope(new PartnerOltScope);
    }

    protected $fillable = [
        'snmp_olt_id',
        'name',
        'latitude',
        'longitude',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
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

    public function links(): HasMany
    {
        return $this->hasMany(OnuOdpLink::class);
    }
}
