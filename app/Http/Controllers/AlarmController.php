<?php

namespace App\Http\Controllers;

use App\Models\AlarmEvent;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AlarmController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->query('status') === 'all' ? 'all' : 'active';

        $query = AlarmEvent::query()
            ->with('olt:id,name')
            ->orderByDesc('last_seen_at');

        if ($status === 'active') {
            $query->where('status', AlarmEvent::STATUS_ACTIVE);
        }

        $alarms = $query->paginate(20)->withQueryString()->through(fn (AlarmEvent $alarm) => [
            'id' => $alarm->id,
            'olt' => [
                'id' => $alarm->snmp_olt_id,
                'name' => $alarm->olt?->name,
            ],
            'type' => $alarm->type,
            'severity' => $alarm->severity,
            'status' => $alarm->status,
            'scope' => $alarm->scope,
            'slot' => $alarm->slot,
            'port' => $alarm->port,
            'onu_id' => $alarm->onu_id,
            'serial_number' => $alarm->serial_number,
            'message' => $alarm->message,
            'first_seen_at' => $alarm->first_seen_at?->toIso8601String(),
            'last_seen_at' => $alarm->last_seen_at?->toIso8601String(),
            'cleared_at' => $alarm->cleared_at?->toIso8601String(),
        ]);

        $summary = AlarmEvent::query()
            ->where('status', AlarmEvent::STATUS_ACTIVE)
            ->selectRaw('severity, count(*) as total')
            ->groupBy('severity')
            ->pluck('total', 'severity');

        return Inertia::render('SmartOlt/Alarms', [
            'alarms' => $alarms,
            'summary' => [
                'critical' => (int) ($summary['critical'] ?? 0),
                'major' => (int) ($summary['major'] ?? 0),
                'minor' => (int) ($summary['minor'] ?? 0),
                'warning' => (int) ($summary['warning'] ?? 0),
                'total' => (int) $summary->sum(),
            ],
            'filter' => ['status' => $status],
        ]);
    }
}
