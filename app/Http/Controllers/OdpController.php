<?php

namespace App\Http\Controllers;

use App\Models\Odp;
use App\Models\OnuOdpLink;
use App\Models\SnmpOlt;
use App\Services\OnuInventoryService;
use App\Services\OnuOdpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OdpController extends Controller
{
    public function __construct(
        private readonly OnuOdpService $service,
        private readonly OnuInventoryService $inventory,
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
                'locked' => (bool) $odp->locked,
                'notes' => $odp->notes,
                'onu_count' => $odp->links_count,
            ])
            ->values();

        return Inertia::render('Odp/Index', [
            'odps' => $odps,
            'olts' => SnmpOlt::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (SnmpOlt $olt) => ['id' => $olt->id, 'name' => $olt->name])
                ->values(),
        ]);
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
        $odp->save();

        // Geser pin ODP (payload koordinat saja) sengaja tanpa flash — lihat catatan sama
        // di OnuMapController::update().
        if (! $request->hasAny(['name', 'slot', 'port', 'locked', 'notes'])) {
            return back();
        }

        return back()->with(
            'success',
            $request->has('locked') && ! $request->has('name')
                ? ($odp->locked ? __('flash.odp_locked') : __('flash.odp_unlocked'))
                : __('flash.odp_updated'),
        );
    }

    public function destroy(Odp $odp): RedirectResponse
    {
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
