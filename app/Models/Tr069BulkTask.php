<?php

namespace App\Models;

use App\Jobs\Tr069BulkConfigJob;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Progress record for a per-OLT "aktifkan TR069 massal" run. The HTTP request
 * only creates this row and dispatches {@see Tr069BulkConfigJob}; the frontend
 * polls the row for live progress (reading + writing every ONU across an OLT is
 * far too long for one synchronous request).
 *
 * Two phases share this model, distinguished by {@see $execute}:
 * - dry-run (execute=false): scan only, report which ONUs WOULD be activated.
 * - execute (execute=true):  scan + write the TR069 config, skipping ones that
 *   already point at the target ACS.
 */
class Tr069BulkTask extends Model
{
    protected $fillable = [
        'snmp_olt_id',
        'created_by',
        'slot',
        'port',
        'execute',
        'total',
        'processed',
        'applied_count',
        'skipped_count',
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
            'slot' => 'integer',
            'port' => 'integer',
            'items' => 'array',
            'total' => 'integer',
            'processed' => 'integer',
            'applied_count' => 'integer',
            'skipped_count' => 'integer',
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
            'slot' => $this->slot,
            'port' => $this->port,
            'execute' => $this->execute,
            'total' => $this->total,
            'processed' => $this->processed,
            // In dry-run, "applied" means "would be activated".
            'applied' => $this->applied_count,
            'skipped' => $this->skipped_count,
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
