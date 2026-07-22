<?php

namespace App\Models;

use App\Models\Scopes\PartnerOltScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnuOdpLink extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope(new PartnerOltScope);
    }

    protected $fillable = [
        'odp_id',
        'snmp_olt_id',
        'slot',
        'port',
        'onu_id',
        'serial_number',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'odp_id' => 'integer',
            'slot' => 'integer',
            'port' => 'integer',
            'onu_id' => 'integer',
        ];
    }

    public function odp(): BelongsTo
    {
        return $this->belongsTo(Odp::class);
    }

    public function olt(): BelongsTo
    {
        return $this->belongsTo(SnmpOlt::class, 'snmp_olt_id');
    }
}
