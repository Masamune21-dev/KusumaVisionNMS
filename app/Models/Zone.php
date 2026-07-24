<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Zone extends Model
{
    use Auditable;

    protected $fillable = [
        'name',
    ];

    // Selalu MAYUSKUL, di mana pun nilainya berasal (form Settings, seeder, API).
    protected function setNameAttribute(string $value): void
    {
        $this->attributes['name'] = mb_strtoupper(trim($value));
    }

    public function links(): HasMany
    {
        return $this->hasMany(OnuZoneLink::class);
    }

    public function auditLabel(): string
    {
        return 'Zone';
    }

    public function auditTitle(): string
    {
        return $this->name;
    }
}
