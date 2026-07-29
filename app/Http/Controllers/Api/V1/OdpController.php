<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Odp;
use App\Services\OnuOdpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only API ODP (Optical Distribution Point) untuk aplikasi Android:
 * daftar ODP + ONU yang terhubung di dalamnya.
 *
 * Kepemilikan dijaga `PartnerOltScope` pada model `Odp` — partner hanya melihat ODP
 * milik OLT yang di-assign ke dirinya (route-model binding `{odp}` → 404 di luar itu).
 */
class OdpController extends Controller
{
    public function __construct(private readonly OnuOdpService $service) {}

    /**
     * GET /api/v1/odps — daftar ODP.
     *
     * Query: olt_id, slot, port, q (nama ODP / nama OLT / catatan).
     */
    public function index(Request $request): JsonResponse
    {
        $oltId = $request->integer('olt_id') ?: null;
        $slot = $request->query('slot');
        $port = $request->query('port');
        $search = mb_strtolower(trim((string) $request->query('q', '')));

        $odps = Odp::query()
            ->withCount('links')
            ->with('olt:id,name')
            ->when($oltId !== null, fn ($query) => $query->where('snmp_olt_id', $oltId))
            ->when(is_numeric($slot), fn ($query) => $query->where('slot', (int) $slot))
            ->when(is_numeric($port), fn ($query) => $query->where('port', (int) $port))
            ->orderBy('name')
            ->get()
            ->map(fn (Odp $odp) => $this->serialize($odp))
            ->when($search !== '', fn ($rows) => $rows->filter(function (array $row) use ($search) {
                $hay = mb_strtolower(implode(' ', array_filter([
                    $row['name'],
                    $row['olt_name'],
                    $row['notes'],
                    $row['slot'] !== null ? "{$row['slot']}/{$row['port']}" : null,
                ])));

                return str_contains($hay, $search);
            }))
            ->values();

        return response()->json([
            'data' => $odps->all(),
            'meta' => ['count' => $odps->count()],
        ]);
    }

    /**
     * GET /api/v1/odps/{odp} — detail satu ODP (tanpa daftar ONU; lihat onus()).
     */
    public function show(Odp $odp): JsonResponse
    {
        $odp->loadCount('links');
        $odp->load('olt:id,name');

        return response()->json(['data' => $this->serialize($odp)]);
    }

    /**
     * GET /api/v1/odps/{odp}/onus — ONU yang terhubung ke ODP ini, ter-enrich status live
     * (online, RX power) + koordinat pin peta bila ONU-nya sudah di-pin.
     */
    public function onus(Odp $odp): JsonResponse
    {
        $onus = $this->service->connectedOnus(collect([$odp]))[$odp->id] ?? [];

        return response()->json([
            'data' => $onus,
            'meta' => [
                'odp_id' => $odp->id,
                'count' => count($onus),
                'online' => count(array_filter($onus, fn (array $onu) => (bool) ($onu['online'] ?? false))),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Odp $odp): array
    {
        return [
            'id' => $odp->id,
            'snmp_olt_id' => $odp->snmp_olt_id,
            'olt_name' => $odp->olt?->name,
            'name' => $odp->name,
            'slot' => $odp->slot,
            'port' => $odp->port,
            'latitude' => (float) $odp->latitude,
            'longitude' => (float) $odp->longitude,
            'notes' => $odp->notes,
            'onu_count' => (int) ($odp->links_count ?? 0),
        ];
    }
}
