<?php

namespace App\Models;

use App\Jobs\CopyOnusToPortJob;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Progress record for a batch "copy ONU config to another port" run. The HTTP
 * request only creates this row and dispatches {@see CopyOnusToPortJob};
 * the frontend polls the row for live progress (a 72-ONU + execute batch is far
 * too long for one synchronous request).
 */
class CopyOnuTask extends Model
{
    protected $fillable = [
        'snmp_olt_id',
        'created_by',
        'src_slot',
        'src_port',
        'dst_slot',
        'dst_port',
        'execute',
        'onu_ids',
        'total',
        'processed',
        'created_count',
        'executed_count',
        'failed_count',
        'status',
        'items',
        'error',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'execute' => 'boolean',
            'onu_ids' => 'array',
            'items' => 'array',
            'src_slot' => 'integer',
            'src_port' => 'integer',
            'dst_slot' => 'integer',
            'dst_port' => 'integer',
            'total' => 'integer',
            'processed' => 'integer',
            'created_count' => 'integer',
            'executed_count' => 'integer',
            'failed_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['completed', 'failed'], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function progressPayload(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'execute' => $this->execute,
            'total' => $this->total,
            'processed' => $this->processed,
            'created' => $this->created_count,
            'executed' => $this->executed_count,
            'failed' => $this->failed_count,
            'finished' => $this->isFinished(),
            'items' => $this->items ?? [],
            'error' => $this->error,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
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
