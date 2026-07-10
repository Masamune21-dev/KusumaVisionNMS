<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AlarmEvent;
use App\Support\SmartOltSupport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only API alarm jaringan (OLT unreachable, port down, LOS, dying gasp,
 * ONU offline, redaman RX tinggi). Default hanya alarm aktif.
 */
class AlarmController extends Controller
{
    /**
     * GET /api/v1/alarms — daftar alarm, ter-paginasi.
     *
     * Query: status (active|cleared|all, default active), severity (critical|major|minor|warning),
     *        olt_id, type, page, per_page (default 50, maks 200).
     */
    public function index(Request $request): JsonResponse
    {
        $status = in_array($request->query('status'), ['active', 'cleared', 'all'], true)
            ? $request->query('status')
            : 'active';
        $severity = in_array($request->query('severity'), ['critical', 'major', 'minor', 'warning'], true)
            ? $request->query('severity')
            : null;
        $type = in_array($request->query('type'), AlarmEvent::types(), true)
            ? $request->query('type')
            : null;
        $oltId = $request->integer('olt_id') ?: null;
        $perPage = min(max((int) $request->integer('per_page', 50), 1), 200);

        $page = AlarmEvent::query()
            ->with('olt:id,name,vendor')
            ->orderByDesc('last_seen_at')
            // Kecualikan alarm PENDING (menunggu konfirmasi poll ke-2) — internal, tak untuk dikonsumsi.
            ->whereIn('status', [AlarmEvent::STATUS_ACTIVE, AlarmEvent::STATUS_CLEARED])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($severity !== null, fn ($q) => $q->where('severity', $severity))
            ->when($type !== null, fn ($q) => $q->where('type', $type))
            ->when($oltId !== null, fn ($q) => $q->where('snmp_olt_id', $oltId))
            ->paginate($perPage)
            ->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (AlarmEvent $a) => [
                'id' => $a->id,
                'olt_id' => $a->snmp_olt_id,
                'olt_name' => $a->olt?->name,
                'type' => $a->type,
                'type_label' => AlarmEvent::typeLabel($a->type, SmartOltSupport::ponLabel($a->olt)),
                'severity' => $a->severity,
                'status' => $a->status,
                'scope' => $a->scope,
                'slot' => $a->slot,
                'port' => $a->port,
                'onu_id' => $a->onu_id,
                'serial_number' => $a->serial_number,
                'customer_name' => SmartOltSupport::cleanCustomerName(
                    data_get($a->meta, 'customer_name'),
                    (string) $a->serial_number,
                ),
                'message' => $a->message,
                'first_seen_at' => $a->first_seen_at?->toIso8601String(),
                'last_seen_at' => $a->last_seen_at?->toIso8601String(),
                'cleared_at' => $a->cleared_at?->toIso8601String(),
            ])->all(),
            'meta' => [
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'count' => count($page->items()),
            ],
        ]);
    }
}
