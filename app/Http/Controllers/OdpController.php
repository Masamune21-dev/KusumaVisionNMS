<?php

namespace App\Http\Controllers;

use App\Models\Odp;
use App\Models\OltPortLabel;
use App\Models\OnuOdpLink;
use App\Models\SnmpOlt;
use App\Services\Odp\OdpPhotoService;
use App\Services\OnuInventoryService;
use App\Services\OnuOdpService;
use App\Support\OdpColors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OdpController extends Controller
{
    public function __construct(
        private readonly OnuOdpService $service,
        private readonly OnuInventoryService $inventory,
        private readonly OdpPhotoService $photos,
    ) {}

    /**
     * Halaman pengelolaan ODP (daftar + filter OLT/port + CRUD + kelola keanggotaan ONU).
     * Kepemilikan dijaga `PartnerOltScope` pada Odp & SnmpOlt — partner hanya lihat OLT-nya.
     */
    public function index(): Response
    {
        $odps = Odp::query()
            ->withCount('links')
            ->with('olt:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (Odp $odp) => [
                'id' => $odp->id,
                'snmp_olt_id' => $odp->snmp_olt_id,
                'olt_name' => $odp->olt?->name,
                'name' => $odp->name,
                'slot' => $odp->slot,
                'port' => $odp->port,
                'latitude' => (float) $odp->latitude,
                'longitude' => (float) $odp->longitude,
                'color' => $odp->color,
                'photo_url' => $this->photos->url($odp),
                'locked' => (bool) $odp->locked,
                'notes' => $odp->notes,
                'onu_count' => $odp->links_count,
            ])
            ->values();

        // Hanya `last_test_result->ports` yang diambil dari DB — cache scan lengkap C300 bisa >1 MB per OLT,
        // dan men-decode seluruhnya untuk tiap OLT membuat halaman ini lambat (pelajaran halaman Peta).
        $olts = SnmpOlt::query()->orderBy('name')->get(['id', 'name', 'last_test_result->ports as ports']);
        $labels = OltPortLabel::query()
            ->whereIn('snmp_olt_id', $olts->pluck('id'))
            ->get(['snmp_olt_id', 'slot', 'port', 'label'])
            ->groupBy('snmp_olt_id');

        return Inertia::render('Odp/Index', [
            'odps' => $odps,
            'olts' => $olts
                ->map(fn (SnmpOlt $olt) => [
                    'id' => $olt->id,
                    'name' => $olt->name,
                    'ports' => $this->portChoices($olt->getAttribute('ports'), $labels->get($olt->id)),
                ])
                ->values(),
            // Palet warna pin ODP dikirim dari server (sumber kebenaran App\Support\OdpColors)
            // supaya daftar warnanya tak diduplikasi di frontend.
            'odp_color_palette' => OdpColors::PALETTE,
            'odp_color_default' => OdpColors::DEFAULT,
        ]);
    }

    /**
     * Pilihan port PON untuk dropdown Slot / PON port di form ODP, dari scan terakhir OLT (`ports`, bentuk
     * sama di ZTE/C-Data/HiOSO). Label = deskripsi port di OLT (ZTE `if_descr`) atau label port sisi-NMS
     * (C-Data/HiOSO). OLT yang belum pernah di-scan → array kosong (form jatuh ke input angka).
     *
     * @param  Collection<int, OltPortLabel>|null  $labels
     * @return list<array{slot: int, port: int, label: ?string}>
     */
    private function portChoices(mixed $raw, ?Collection $labels): array
    {
        $ports = is_string($raw) ? json_decode($raw, true) : $raw;
        $nmsLabels = ($labels ?? collect())->mapWithKeys(fn (OltPortLabel $l) => ["{$l->slot}/{$l->port}" => $l->label]);

        return collect(is_array($ports) ? $ports : [])
            ->filter(fn ($p) => is_array($p) && is_numeric($p['slot'] ?? null) && is_numeric($p['port'] ?? null))
            ->map(function (array $p) use ($nmsLabels) {
                $key = (int) $p['slot'].'/'.(int) $p['port'];
                // Sebagian ZTE mengisi deskripsi port dengan nama port itu sendiri (`gpon_1/2/1`) — bukan label.
                $descr = trim((string) ($p['if_descr'] ?? ''));
                if (in_array(strtolower($descr), [strtolower((string) ($p['name'] ?? '')), strtolower((string) ($p['if_name'] ?? ''))], true)) {
                    $descr = '';
                }
                $label = $descr ?: ($nmsLabels[$key] ?? null);

                return ['slot' => (int) $p['slot'], 'port' => (int) $p['port'], 'label' => $label ?: null];
            })
            ->unique(fn ($p) => "{$p['slot']}/{$p['port']}")
            ->sortBy([['slot', 'asc'], ['port', 'asc']])
            ->values()
            ->all();
    }

    /**
     * Isi modal "Kelola ONU": ONU yang sudah ada di ODP ini + kandidat yang bisa ditambahkan.
     * Read-only JSON (dipanggil axios), penambahan/pelepasan tetap lewat `onu-odp.assign`.
     */
    public function onus(Odp $odp): JsonResponse
    {
        $connected = $this->service->connectedOnus(collect([$odp]))[$odp->id] ?? [];

        $olt = $odp->olt;
        if ($olt === null) {
            return response()->json(['connected' => $connected, 'available' => []]);
        }

        // ODP terkunci ke satu PON port; ODP yang belum punya port boleh memilih ONU mana pun
        // di OLT-nya (port ODP terisi otomatis saat ONU pertama di-assign).
        $rows = $odp->slot !== null && $odp->port !== null
            ? $this->inventory->forPort($olt, $odp->slot, $odp->port)['onus']
            : $this->inventory->collect(collect([$olt]))['onus'];

        // Kaitan ODP lain pada kandidat — supaya operator tahu ONU itu akan dipindah, bukan ganda.
        $links = OnuOdpLink::query()
            ->where('snmp_olt_id', $olt->id)
            ->when($odp->slot !== null, fn ($query) => $query->where('slot', $odp->slot)->where('port', $odp->port))
            ->with('odp:id,name')
            ->get()
            ->keyBy(fn (OnuOdpLink $link) => "{$link->slot}.{$link->port}.{$link->onu_id}");

        $available = [];
        foreach ($rows as $row) {
            $link = $links->get("{$row['slot']}.{$row['port']}.{$row['onu_id']}");
            // ONU yang sudah ada di ODP ini tampil di daftar "terhubung", bukan di kandidat.
            if ($link !== null && $link->odp_id === $odp->id) {
                continue;
            }

            $available[] = [
                'slot' => $row['slot'],
                'port' => $row['port'],
                'onu_id' => $row['onu_id'],
                'interface' => $row['interface'],
                'serial_number' => $row['serial_number'],
                'name' => $row['customer_name'],
                'online' => $row['online'],
                'current_odp_id' => $link?->odp_id,
                'current_odp_name' => $link?->odp?->name,
            ];
        }

        return response()->json([
            'connected' => $connected,
            'available' => $available,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'snmp_olt_id' => ['required', 'integer', 'exists:snmp_olts,id'],
            'name' => ['required', 'string', 'max:128'],
            'slot' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'port' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        // Kepemilikan OLT — findOrFail kena PartnerOltScope, partner tak bisa titipkan ODP ke OLT lain.
        SnmpOlt::query()->findOrFail($data['snmp_olt_id']);

        Odp::query()->create([
            'snmp_olt_id' => $data['snmp_olt_id'],
            'name' => trim($data['name']),
            'slot' => $data['slot'] ?? null,
            'port' => $data['port'] ?? null,
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
            'color' => $this->service->portColor($data['snmp_olt_id'], $data['slot'] ?? null, $data['port'] ?? null),
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()?->id,
        ]);

        return back()->with('success', __('flash.odp_saved'));
    }

    public function update(Request $request, Odp $odp): RedirectResponse
    {
        $data = $request->validate([
            // 'sometimes' supaya PUT koordinat-saja (hasil geser pin di peta) dan PUT
            // lock/unlock tak perlu ikut mengirim ulang nama.
            'name' => ['sometimes', 'required', 'string', 'max:128'],
            'snmp_olt_id' => ['sometimes', 'required', 'integer', 'exists:snmp_olts,id'],
            'slot' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'port' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'locked' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if (isset($data['name'])) {
            $odp->name = trim($data['name']);
        }
        if (isset($data['snmp_olt_id'])) {
            // Kepemilikan OLT tujuan — findOrFail kena PartnerOltScope, sama seperti store().
            $odp->snmp_olt_id = SnmpOlt::query()->findOrFail($data['snmp_olt_id'])->id;
        }
        // slot/port hanya diubah bila field-nya dikirim (edit port opsional).
        if ($request->has('slot')) {
            $odp->slot = $data['slot'] ?? null;
        }
        if ($request->has('port')) {
            $odp->port = $data['port'] ?? null;
        }
        if (($data['latitude'] ?? null) !== null) {
            $odp->latitude = $data['latitude'];
        }
        if (($data['longitude'] ?? null) !== null) {
            $odp->longitude = $data['longitude'];
        }
        if ($request->has('locked')) {
            $odp->locked = (bool) ($data['locked'] ?? false);
        }
        if ($request->has('notes')) {
            $odp->notes = $data['notes'] ?? null;
        }
        $movedPort = $odp->isDirty(['snmp_olt_id', 'slot', 'port']);
        // Pindah ke port yang sudah diwarnai → ikut warnanya; port tujuan polos → warna tetap.
        if ($movedPort) {
            $odp->color = $this->service->portColor($odp->snmp_olt_id, $odp->slot, $odp->port, $odp->id) ?? $odp->color;
        }
        $odp->save();

        // Pindah OLT / ganti port → ONU yang tak lagi di OLT/port itu dilepas, supaya
        // ODP tak memuat ONU dari OLT atau port lain.
        $released = $movedPort ? $this->service->releaseMismatchedLinks($odp) : 0;

        // Geser pin ODP (payload koordinat saja) sengaja tanpa flash — lihat catatan sama
        // di OnuMapController::update().
        if (! $request->hasAny(['name', 'snmp_olt_id', 'slot', 'port', 'locked', 'notes'])) {
            return back();
        }

        if ($released > 0) {
            return back()->with('success', __('flash.odp_updated_links_released', ['count' => $released]));
        }

        return back()->with(
            'success',
            $request->has('locked') && ! $request->has('name')
                ? ($odp->locked ? __('flash.odp_locked') : __('flash.odp_unlocked'))
                : __('flash.odp_updated'),
        );
    }

    /**
     * Ganti warna pin ODP di peta (manual dari palet/hex, atau acak).
     *
     * Rute tersendiri (bukan menempel di update()) karena semantiknya berbeda: bawaannya
     * mewarnai SEMUA ODP di PON port yang sama. Pakai back() supaya bisa dipanggil dari
     * peta maupun halaman ODP.
     */
    public function color(Request $request, Odp $odp): RedirectResponse
    {
        $result = $this->service->applyColorInput($odp, $request->validate(OdpColors::RULES));

        return back()->with('success', __('flash.odp_color_updated', ['count' => $result['updated']]));
    }

    /**
     * Unggah / ganti foto dokumentasi ODP (satu foto per ODP, dikonversi ke WebP).
     */
    public function storePhoto(Request $request, Odp $odp): RedirectResponse
    {
        $request->validate(OdpPhotoService::rules());

        $this->photos->store($odp, $request->file('photo'));

        return back()->with('success', __('flash.odp_photo_saved'));
    }

    public function destroyPhoto(Odp $odp): RedirectResponse
    {
        $this->photos->delete($odp);

        return back()->with('success', __('flash.odp_photo_deleted'));
    }

    /**
     * Sajikan berkas foto. Rute ber-auth (bukan disk publik) — route-model binding kena
     * `PartnerOltScope`, jadi ODP di luar scope pengguna 404.
     */
    public function photo(Odp $odp): BinaryFileResponse
    {
        $path = $this->photos->absolutePath($odp);
        abort_if($path === null, 404);

        // Nama berkas acak + `?v=` token → aman di-cache lama di sisi klien.
        return response()->file($path, ['Cache-Control' => 'private, max-age=604800']);
    }

    public function destroy(Odp $odp): RedirectResponse
    {
        // Berkas fotonya ikut dibuang supaya tak jadi sampah di storage.
        $this->photos->delete($odp);
        $odp->delete();

        return back()->with('success', __('flash.odp_deleted'));
    }

    /**
     * Assign / lepas ODP satu ONU (family-agnostic; dipanggil dari kolom ODP tabel ONU).
     */
    public function assignOnu(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'snmp_olt_id' => ['required', 'integer', 'exists:snmp_olts,id'],
            'slot' => ['required', 'integer', 'min:0'],
            'port' => ['required', 'integer', 'min:0'],
            'onu_id' => ['required', 'integer', 'min:0'],
            'serial_number' => ['nullable', 'string', 'max:64'],
            'odp_id' => ['nullable', 'integer'],
        ]);

        // findOrFail kena PartnerOltScope → OLT bukan miliknya 404.
        $olt = SnmpOlt::query()->findOrFail($data['snmp_olt_id']);

        try {
            $this->service->assign(
                $olt,
                (int) $data['slot'],
                (int) $data['port'],
                (int) $data['onu_id'],
                $data['serial_number'] ?? null,
                isset($data['odp_id']) ? (int) $data['odp_id'] : null,
                $request->user()?->id,
            );
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with(
            'success',
            isset($data['odp_id']) ? __('flash.onu_odp_assigned') : __('flash.onu_odp_cleared'),
        );
    }
}
