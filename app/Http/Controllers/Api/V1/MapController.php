<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Odp;
use App\Models\SnmpOlt;
use App\Services\Map\OnuMapPayloadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/map — payload Peta ONU untuk aplikasi Android: pin ONU (hijau/merah),
 * pin ODP (kuning) beserta ONU terhubung (untuk garis ODP→ONU), dan titik tengah default.
 *
 * Sumber payload sama persis dengan halaman peta web (`OnuMapPayloadService`), hanya
 * dipangkas: field khusus web (nama rute Inertia, peta capabilities per pin) tidak dikirim
 * karena peta di aplikasi bersifat baca-saja dan aksi tulis dilakukan di layar detail ONU.
 */
class MapController extends Controller
{
    public function __construct(private readonly OnuMapPayloadService $payload) {}

    public function index(Request $request): JsonResponse
    {
        $oltId = $request->integer('olt_id') ?: null;

        // PartnerOltScope aktif di SnmpOlt & Odp → partner hanya melihat OLT miliknya.
        $olts = SnmpOlt::query()
            ->when($oltId !== null, fn ($query) => $query->whereKey($oltId))
            ->orderBy('name')
            ->get();

        $pins = $this->payload->pins($olts)->map(fn (array $pin) => [
            'id' => $pin['id'],
            'olt_id' => $pin['snmp_olt_id'],
            'olt_name' => $pin['olt_name'],
            'slot' => $pin['slot'],
            'port' => $pin['port'],
            'onu_id' => $pin['onu_id'],
            'interface' => $pin['interface'],
            'serial_number' => $pin['serial_number'],
            'latitude' => $pin['latitude'],
            'longitude' => $pin['longitude'],
            'customer_name' => $pin['customer_name'],
            'address' => $pin['address'],
            'phone' => $pin['phone'],
            'notes' => $pin['notes'],
            'rx_power_dbm' => $pin['rx_power_dbm'],
            'rx_power_label' => $pin['rx_power_label'],
            'online' => $pin['online'],
            'has_live' => $pin['has_live'],
        ])->values();

        $odps = Odp::query()
            ->whereIn('snmp_olt_id', $olts->pluck('id')->all())
            ->orderBy('name')
            ->get();
        $odpRows = $this->payload->odps($odps, $olts);

        return response()->json([
            'data' => [
                'pins' => $pins->all(),
                'odps' => $odpRows->all(),
                'olts' => $olts->map(fn (SnmpOlt $olt) => [
                    'id' => $olt->id,
                    'name' => $olt->name,
                ])->values()->all(),
                // Titik tengah dihitung dari pin ONU **dan** pin ODP: di lapangan sering
                // ODP-nya sudah dipetakan sementara ONU-nya belum, jadi peta tetap membuka
                // di area kerja alih-alih jatuh ke koordinat fallback.
                'default_center' => $this->payload->defaultCenter(
                    $pins->concat($odpRows->map(fn (array $odp) => [
                        'latitude' => $odp['latitude'],
                        'longitude' => $odp['longitude'],
                    ]))->all(),
                ),
            ],
            'meta' => [
                'pins' => $pins->count(),
                'odps' => $odps->count(),
            ],
        ]);
    }
}
