<?php

namespace App\Models;

use App\Models\Scopes\DemoScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SnmpOlt extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'vendor',
        'ip',
        'snmp_port',
        'snmp_read_community',
        'snmp_write_community',
        'snmp_version',
        'cli_transport',
        'cli_port',
        'cli_username',
        'cli_password',
        'polling_enabled',
        'poll_interval_minutes',
        'rx_poll_interval_minutes',
        'last_test_result',
        'last_tested_at',
        'last_polled_at',
        'last_rx_polled_at',
        'is_demo',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new DemoScope);
    }

    protected $hidden = [
        'snmp_read_community',
        'snmp_write_community',
        'cli_password',
    ];

    protected function casts(): array
    {
        return [
            'snmp_port' => 'integer',
            'cli_port' => 'integer',
            'snmp_read_community' => 'encrypted',
            'snmp_write_community' => 'encrypted',
            'cli_password' => 'encrypted',
            'polling_enabled' => 'boolean',
            'is_demo' => 'boolean',
            'poll_interval_minutes' => 'integer',
            'rx_poll_interval_minutes' => 'integer',
            'last_test_result' => 'array',
            'last_tested_at' => 'datetime',
            'last_polled_at' => 'datetime',
            'last_rx_polled_at' => 'datetime',
        ];
    }

    public function getHostAddress(): string
    {
        return $this->snmp_port === 161
            ? $this->ip
            : "{$this->ip}:{$this->snmp_port}";
    }

    public function cardStatuses(): HasMany
    {
        return $this->hasMany(SmartOltCardStatus::class, 'snmp_olt_id');
    }

    public function interfaceStatuses(): HasMany
    {
        return $this->hasMany(SmartOltInterfaceStatus::class, 'snmp_olt_id');
    }

    public function defaultCliPort(): int
    {
        return $this->cli_transport === 'ssh' ? 22 : 23;
    }

    public function isPollDue(): bool
    {
        if (! $this->polling_enabled) {
            return false;
        }

        if ($this->last_polled_at === null) {
            return true;
        }

        return $this->last_polled_at->lte(now()->subMinutes($this->pollIntervalMinutes()));
    }

    public function isRxPollDue(): bool
    {
        if ($this->last_rx_polled_at === null) {
            return true;
        }

        return $this->last_rx_polled_at->lte(now()->subMinutes($this->rxPollIntervalMinutes()));
    }

    public function pollIntervalMinutes(): int
    {
        return max(1, (int) ($this->poll_interval_minutes ?: 5));
    }

    public function rxPollIntervalMinutes(): int
    {
        return max(1, (int) ($this->rx_poll_interval_minutes ?: 5));
    }
}
