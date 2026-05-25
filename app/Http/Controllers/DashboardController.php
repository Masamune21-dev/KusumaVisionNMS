<?php

namespace App\Http\Controllers;

use App\Models\AlarmEvent;
use App\Models\SnmpOlt;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        $olts = SnmpOlt::query()->orderBy('name')->get();

        $oltsOnline = 0;
        $portsTotal = 0;
        $portsUp = 0;
        $portsDown = 0;
        $onuTotal = 0;
        $onuOnline = 0;
        $oltStats = [];

        foreach ($olts as $olt) {
            $result = $olt->last_test_result ?? [];
            $reachable = (bool) ($result['ok'] ?? false);
            $ports = collect($result['ports'] ?? []);
            $portOnus = collect($result['port_onus'] ?? []);

            $pUp = $ports->where('oper_status', 'up')->count();
            $pDown = $ports->where('oper_status', 'down')->count();
            $oTotal = (int) $portOnus->sum('count');
            $oOnline = $portOnus->flatMap(fn ($p) => $p['onus'] ?? [])->where('online', true)->count();

            $oltsOnline += $reachable ? 1 : 0;
            $portsTotal += $ports->count();
            $portsUp += $pUp;
            $portsDown += $pDown;
            $onuTotal += $oTotal;
            $onuOnline += $oOnline;

            $oltStats[] = [
                'id' => $olt->id,
                'name' => $olt->name,
                'reachable' => $reachable,
                'polling_enabled' => (bool) $olt->polling_enabled,
                'ports_up' => $pUp,
                'ports_down' => $pDown,
                'onu_total' => $oTotal,
                'onu_online' => $oOnline,
                'onu_offline' => max($oTotal - $oOnline, 0),
                'last_polled_at' => $olt->last_polled_at?->toIso8601String(),
            ];
        }

        return Inertia::render('Dashboard', [
            'stats' => [
                'olts_total' => $olts->count(),
                'olts_online' => $oltsOnline,
                'olts_offline' => $olts->count() - $oltsOnline,
                'ports_total' => $portsTotal,
                'ports_up' => $portsUp,
                'ports_down' => $portsDown,
                'onu_total' => $onuTotal,
                'onu_online' => $onuOnline,
                'onu_offline' => max($onuTotal - $onuOnline, 0),
            ],
            'alarms' => $this->alarmSummary(),
            'olts' => $oltStats,
            'recent_alarms' => $this->recentAlarms(),
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function alarmSummary(): array
    {
        $summary = AlarmEvent::query()
            ->where('status', AlarmEvent::STATUS_ACTIVE)
            ->selectRaw('severity, count(*) as total')
            ->groupBy('severity')
            ->pluck('total', 'severity');

        return [
            'critical' => (int) ($summary['critical'] ?? 0),
            'major' => (int) ($summary['major'] ?? 0),
            'minor' => (int) ($summary['minor'] ?? 0),
            'warning' => (int) ($summary['warning'] ?? 0),
            'total' => (int) $summary->sum(),
        ];
    }

    private function recentAlarms(): Collection
    {
        return AlarmEvent::query()
            ->with('olt:id,name')
            ->where('status', AlarmEvent::STATUS_ACTIVE)
            ->orderByDesc('last_seen_at')
            ->limit(8)
            ->get()
            ->map(fn (AlarmEvent $alarm) => [
                'id' => $alarm->id,
                'olt_name' => $alarm->olt?->name,
                'type' => $alarm->type,
                'severity' => $alarm->severity,
                'message' => $alarm->message,
                'last_seen_at' => $alarm->last_seen_at?->toIso8601String(),
            ]);
    }
}
