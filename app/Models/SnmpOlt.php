<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
        'last_test_result',
        'last_tested_at',
    ];

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
            'last_test_result' => 'array',
            'last_tested_at' => 'datetime',
        ];
    }

    public function getHostAddress(): string
    {
        return $this->snmp_port === 161
            ? $this->ip
            : "{$this->ip}:{$this->snmp_port}";
    }

    public function defaultCliPort(): int
    {
        return $this->cli_transport === 'ssh' ? 22 : 23;
    }
}
