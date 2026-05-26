<?php

namespace App\Http\Controllers;

use App\Models\AlarmEvent;
use App\Models\SmartOltOnuRegistration;
use App\Models\SnmpOlt;
use App\Support\SmartOltSupport;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class AlarmController extends Controller
{
    public function index(Request $request): Response
    {
        $status = in_array($request->query('status'), ['active', 'cleared', 'all'], true)
            ? $request->query('status')
            : 'active';
        $severity = in_array($request->query('severity'), $this->severities(), true)
            ? $request->query('severity')
            : 'all';
        $scope = in_array($request->query('scope'), ['olt', 'port', 'onu'], true)
            ? $request->query('scope')
            : 'all';
        $type = trim((string) $request->query('type', 'all')) ?: 'all';
        $oltId = $request->integer('olt_id') ?: null;
        $search = trim((string) $request->query('q', ''));

        $query = AlarmEvent::query()
            ->with('olt:id,name')
            ->orderByDesc('last_seen_at');

        $query
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when($severity !== 'all', fn ($query) => $query->where('severity', $severity))
            ->when($scope !== 'all', fn ($query) => $query->where('scope', $scope))
            ->when($type !== 'all', fn ($query) => $query->where('type', $type))
            ->when($oltId !== null, fn ($query) => $query->where('snmp_olt_id', $oltId))
            ->when($search !== '', fn ($query) => $this->applySearch($query, $search));

        $alarmPage = $query->paginate(20)->withQueryString();
        $customerNames = $this->customerNamesFor($alarmPage->getCollection());

        $alarms = $alarmPage->through(fn (AlarmEvent $alarm) => [
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
            'customer_name' => $this->customerNameForAlarm($alarm, $customerNames),
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
            'filter' => [
                'status' => $status,
                'severity' => $severity,
                'scope' => $scope,
                'type' => $type,
                'olt_id' => $oltId,
                'q' => $search,
            ],
            'filterOptions' => [
                'olts' => SnmpOlt::query()
                    ->select('id', 'name')
                    ->orderBy('name')
                    ->get(),
                'types' => AlarmEvent::query()
                    ->select('type')
                    ->distinct()
                    ->orderBy('type')
                    ->pluck('type')
                    ->values(),
                'severities' => $this->severities(),
                'scopes' => ['olt', 'port', 'onu'],
            ],
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function severities(): array
    {
        return [
            AlarmEvent::SEVERITY_CRITICAL,
            AlarmEvent::SEVERITY_MAJOR,
            AlarmEvent::SEVERITY_MINOR,
            AlarmEvent::SEVERITY_WARNING,
        ];
    }

    private function applySearch($query, string $search): void
    {
        $like = '%'.mb_strtolower($search).'%';

        $query->where(function ($query) use ($like) {
            $query
                ->whereRaw('LOWER(type) LIKE ?', [$like])
                ->orWhereRaw('LOWER(message) LIKE ?', [$like])
                ->orWhereRaw('LOWER(COALESCE(serial_number, \'\')) LIKE ?', [$like])
                ->orWhereRaw('LOWER(signature) LIKE ?', [$like])
                ->orWhereHas('olt', fn ($query) => $query->whereRaw('LOWER(name) LIKE ?', [$like]));
        });
    }

    /**
     * @param  Collection<int, AlarmEvent>  $alarms
     * @return array<string, string>
     */
    private function customerNamesFor(Collection $alarms): array
    {
        $serialsByOlt = $alarms
            ->filter(fn (AlarmEvent $alarm) => $alarm->serial_number !== null)
            ->groupBy('snmp_olt_id')
            ->map(fn (Collection $alarms) => $alarms
                ->pluck('serial_number')
                ->filter()
                ->map(fn (string $serial) => strtoupper($serial))
                ->unique()
                ->values());

        if ($serialsByOlt->isEmpty()) {
            return [];
        }

        $names = [];
        $oltIds = $serialsByOlt->keys()->all();
        $serials = $serialsByOlt->flatten()->unique()->values()->all();

        SmartOltOnuRegistration::query()
            ->whereIn('snmp_olt_id', $oltIds)
            ->whereIn('serial_number', $serials)
            ->orderByDesc('created_at')
            ->get(['snmp_olt_id', 'serial_number', 'customer_name'])
            ->each(function (SmartOltOnuRegistration $registration) use (&$names) {
                $key = $this->customerLookupKey($registration->snmp_olt_id, $registration->serial_number);
                $name = SmartOltSupport::cleanCustomerName($registration->customer_name, $registration->serial_number);

                if ($name !== null && ! isset($names[$key])) {
                    $names[$key] = $name;
                }
            });

        SnmpOlt::query()
            ->whereIn('id', $oltIds)
            ->get(['id', 'last_test_result'])
            ->each(function (SnmpOlt $olt) use ($serialsByOlt, &$names) {
                $needed = array_flip($serialsByOlt->get($olt->id, collect())->all());

                foreach (data_get($olt->last_test_result ?? [], 'port_onus', []) as $portData) {
                    foreach ($portData['onus'] ?? [] as $onu) {
                        $serial = strtoupper((string) ($onu['serial_number'] ?? ''));

                        if ($serial === '' || ! isset($needed[$serial])) {
                            continue;
                        }

                        $key = $this->customerLookupKey($olt->id, $serial);

                        if (! isset($names[$key]) && ($name = SmartOltSupport::customerNameFromOnu($onu)) !== null) {
                            $names[$key] = $name;
                        }
                    }
                }
            });

        return $names;
    }

    /**
     * @param  array<string, string>  $customerNames
     */
    private function customerNameForAlarm(AlarmEvent $alarm, array $customerNames): ?string
    {
        if ($alarm->serial_number === null) {
            return null;
        }

        return $customerNames[$this->customerLookupKey($alarm->snmp_olt_id, $alarm->serial_number)]
            ?? SmartOltSupport::cleanCustomerName(data_get($alarm->meta, 'customer_name'), $alarm->serial_number);
    }

    private function customerLookupKey(int $oltId, string $serial): string
    {
        return $oltId.':'.strtoupper($serial);
    }
}
