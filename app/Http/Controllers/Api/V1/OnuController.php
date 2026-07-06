<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SnmpOlt;
use App\Services\OnuInventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only API inventaris ONU lintas-OLT (sumber: cache `port_onus` per-OLT,
 * via OnuInventoryService). Inilah data utama untuk aplikasi monitoring eksternal:
 * status online/offline, RX power, SN, dan nama pelanggan tiap ONU.
 */
class OnuController extends Controller
{
    public function __construct(private readonly OnuInventoryService $inventory) {}

    /**
     * GET /api/v1/onus — daftar ONU lintas-OLT, terfilter & ter-paginasi.
     *
     * Query: olt_id, status (online|offline|warning), q (cari SN/nama/interface/pelanggan),
     *        page, per_page (default 50, maks 200).
     */
    public function index(Request $request): JsonResponse
    {
        $oltId = $request->integer('olt_id') ?: null;
        $status = in_array($request->query('status'), ['online', 'offline', 'warning'], true)
            ? $request->query('status')
            : null;
        $search = trim((string) $request->query('q', ''));
        $perPage = min(max((int) $request->integer('per_page', 50), 1), 200);
        $page = max((int) $request->integer('page', 1), 1);

        $olts = $oltId
            ? SnmpOlt::query()->whereKey($oltId)->get()
            : null;

        $onus = collect($this->inventory->collect($olts)['onus']);

        if ($status !== null) {
            $onus = $onus->filter(fn (array $onu) => $this->matchesStatus($onu, $status));
        }

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $onus = $onus->filter(function (array $onu) use ($needle) {
                $hay = mb_strtolower(implode(' ', array_filter([
                    $onu['serial_number'] ?? null,
                    $onu['mac'] ?? null,
                    $onu['name'] ?? null,
                    $onu['description'] ?? null,
                    $onu['customer_name'] ?? null,
                    $onu['interface'] ?? null,
                    $onu['olt_name'] ?? null,
                ])));

                return str_contains($hay, $needle);
            });
        }

        $onus = $onus->values();
        $total = $onus->count();
        $items = $onus->slice(($page - 1) * $perPage, $perPage)->values();

        return response()->json([
            'data' => $items->all(),
            'meta' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => (int) max(ceil($total / $perPage), 1),
                'count' => $items->count(),
            ],
        ]);
    }

    /**
     * GET /api/v1/olts/{olt}/onus/{slot}/{port}/{onuId} — detail satu ONU.
     */
    public function show(SnmpOlt $olt, int $slot, int $port, int $onuId): JsonResponse
    {
        $onu = $this->inventory->findOne($olt, $slot, $port, $onuId);

        if ($onu === null) {
            return response()->json([
                'message' => 'ONU tidak ditemukan pada snapshot OLT ini.',
            ], 404);
        }

        return response()->json(['data' => $onu]);
    }

    /**
     * @param  array<string, mixed>  $onu
     */
    private function matchesStatus(array $onu, string $status): bool
    {
        $online = (bool) ($onu['online'] ?? false);

        return match ($status) {
            'online' => $online,
            'offline' => ! $online,
            // Warning = online tapi RX power di luar zona aman (-25…-10 dBm).
            'warning' => $online
                && is_numeric($onu['rx_power_dbm'] ?? null)
                && ((float) $onu['rx_power_dbm'] <= -25 || (float) $onu['rx_power_dbm'] >= -10),
            default => true,
        };
    }
}
