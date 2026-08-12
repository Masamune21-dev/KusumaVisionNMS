<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Odp;
use App\Services\Odp\OdpPhotoService;
use App\Services\OnuOdpService;
use App\Support\OdpColors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * API ODP (Optical Distribution Point) untuk aplikasi Android: daftar ODP + ONU yang
 * terhubung di dalamnya (baca-saja), plus dua aksi tulis — ganti warna pin dan foto
 * dokumentasi ODP. CRUD ODP selebihnya (tambah/ubah/hapus ODP) tetap web-only.
 *
 * Kepemilikan dijaga `PartnerOltScope` pada model `Odp` — partner hanya melihat ODP
 * milik OLT yang di-assign ke dirinya (route-model binding `{odp}` → 404 di luar itu).
 */
class OdpController extends Controller
{
    public function __construct(
        private readonly OnuOdpService $service,
        private readonly OdpPhotoService $photos,
    ) {}

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
            'meta' => [
                'count' => $odps->count(),
                // Palet warna pin ODP dikirim dari server supaya daftar warnanya tak
                // diduplikasi di aplikasi Android (sumber: App\Support\OdpColors).
                'color_palette' => OdpColors::PALETTE,
                'color_default' => OdpColors::DEFAULT,
            ],
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
     * POST /api/v1/odps/{odp}/color — ganti warna pin ODP.
     *
     * Body: `color` "#rrggbb" (null = reset ke default), `random` (server memilih warna
     * palet yang belum dipakai port lain di OLT ini), `apply_to_port` (bawaan true =
     * mewarnai semua ODP di PON port yang sama).
     */
    public function color(Request $request, Odp $odp): JsonResponse
    {
        $result = $this->service->applyColorInput($odp, $request->validate(OdpColors::RULES));

        return response()->json([
            'data' => [
                'id' => $odp->id,
                'color' => $result['color'],
                'color_effective' => $result['color'] ?? OdpColors::DEFAULT,
                'updated' => $result['updated'],
            ],
        ]);
    }

    /**
     * GET /api/v1/odps/{odp}/photo — berkas foto ODP (WebP) untuk aplikasi.
     *
     * Bukan disk publik: berkas hanya keluar lewat rute ber-token ini, dan route-model
     * binding kena `PartnerOltScope` → ODP di luar scope pengguna 404.
     */
    public function photo(Odp $odp): BinaryFileResponse
    {
        $path = $this->photos->absolutePath($odp);
        abort_if($path === null, 404);

        return response()->file($path, ['Cache-Control' => 'private, max-age=604800']);
    }

    /**
     * POST /api/v1/odps/{odp}/photo — unggah/ganti foto ODP dari aplikasi (multipart,
     * field `photo`). Satu foto per ODP: unggahan baru menimpa yang lama.
     *
     * Validasi & konversi WebP memakai jalur yang sama persis dengan web
     * ({@see OdpPhotoService}), jadi batas format/ukuran tak pernah berbeda antar klien.
     */
    public function storePhoto(Request $request, Odp $odp): JsonResponse
    {
        $request->validate(OdpPhotoService::rules());

        $this->photos->store($odp, $request->file('photo'));

        return response()->json([
            'data' => [
                'id' => $odp->id,
                'photo_url' => $this->photos->url($odp, api: true),
                'ok' => true,
            ],
        ]);
    }

    /**
     * DELETE /api/v1/odps/{odp}/photo — hapus foto ODP.
     */
    public function destroyPhoto(Odp $odp): JsonResponse
    {
        $this->photos->delete($odp);

        return response()->json(['data' => ['id' => $odp->id, 'photo_url' => null, 'ok' => true]]);
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
            'color' => $odp->color,
            'photo_url' => $this->photos->url($odp, api: true),
            'notes' => $odp->notes,
            'onu_count' => (int) ($odp->links_count ?? 0),
        ];
    }
}
