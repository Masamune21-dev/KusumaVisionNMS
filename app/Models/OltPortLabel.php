<?php

namespace App\Models;

use App\Models\Scopes\PartnerOltScope;
use App\Services\OltPortLabelService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Label port PON milik NMS (bukan konfigurasi OLT) — lihat {@see OltPortLabelService}.
 */
class OltPortLabel extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope(new PartnerOltScope);
    }

    protected $fillable = [
        'snmp_olt_id',
        'slot',
        'port',
        'label',
    ];

    protected function casts(): array
    {
        return [
            'slot' => 'integer',
            'port' => 'integer',
        ];
    }

    public function olt(): BelongsTo
    {
        return $this->belongsTo(SnmpOlt::class, 'snmp_olt_id');
    }
}
