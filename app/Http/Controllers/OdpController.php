<?php

namespace App\Http\Controllers;

use App\Models\Odp;
use App\Models\SnmpOlt;
use App\Services\OnuOdpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OdpController extends Controller
{
    public function __construct(private readonly OnuOdpService $service) {}

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

        return redirect()->route('map.index')->with('success', __('flash.odp_saved'));
    }

    public function update(Request $request, Odp $odp): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'slot' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'port' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $odp->name = trim($data['name']);
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
        $odp->notes = $data['notes'] ?? null;
        $odp->save();

        return redirect()->route('map.index')->with('success', __('flash.odp_updated'));
    }

    public function destroy(Odp $odp): RedirectResponse
    {
        $odp->delete();

        return redirect()->route('map.index')->with('success', __('flash.odp_deleted'));
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
