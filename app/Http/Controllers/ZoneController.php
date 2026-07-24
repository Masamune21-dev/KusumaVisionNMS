<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\SnmpOlt;
use App\Models\Zone;
use App\Services\ZoneService;
use App\Support\AuditLogger;
use App\Support\SmartOltSupport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ZoneController extends Controller
{
    public function __construct(private readonly ZoneService $service) {}

    public function index(): Response
    {
        return Inertia::render('Zones/Index', [
            'zones' => $this->service->listWithCounts(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            // Unique dicek case-insensitive di bawah (nama disimpan MAYUSKUL via mutator model) —
            // 'unique' bawaan Laravel case-sensitive di sebagian besar driver DB.
            'name' => ['required', 'string', 'max:120'],
        ]);

        $name = mb_strtoupper(trim($data['name']));

        if (Zone::query()->whereRaw('UPPER(name) = ?', [$name])->exists()) {
            return back()->withErrors(['name' => __('flash.zone_name_taken')])->withInput();
        }

        Zone::query()->create(['name' => $name]);

        return back()->with('success', __('flash.zone_saved'));
    }

    public function update(Request $request, Zone $zone): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        $name = mb_strtoupper(trim($data['name']));

        if (Zone::query()->whereRaw('UPPER(name) = ?', [$name])->where('id', '!=', $zone->id)->exists()) {
            return back()->withErrors(['name' => __('flash.zone_name_taken')])->withInput();
        }

        $zone->name = $name;
        $zone->save();

        return back()->with('success', __('flash.zone_updated'));
    }

    public function destroy(Request $request, Zone $zone): RedirectResponse
    {
        $data = $request->validate([
            // null/absen = ONU di zona ini jadi "Sin zona"; diisi = pindahkan dulu ke zona itu.
            'reassign_to' => ['nullable', 'integer', Rule::exists('zones', 'id')],
        ]);

        try {
            $this->service->destroy($zone, isset($data['reassign_to']) ? (int) $data['reassign_to'] : null);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('flash.zone_deleted'));
    }

    /**
     * Assign / lepas zona satu ONU (dipakai edit inline di detail ONU; provisioning
     * mengaitkan zona lewat OnuRegistrationService/storeOnuAdvanced, bukan endpoint ini).
     */
    public function assignOnu(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'snmp_olt_id' => ['required', 'integer', 'exists:snmp_olts,id'],
            'slot' => ['required', 'integer', 'min:0'],
            'port' => ['required', 'integer', 'min:0'],
            'onu_id' => ['required', 'integer', 'min:0'],
            'serial_number' => ['nullable', 'string', 'max:64'],
            'zone_id' => ['nullable', 'integer', 'exists:zones,id'],
        ]);

        // findOrFail kena PartnerOltScope → OLT bukan miliknya 404.
        $olt = SnmpOlt::query()->findOrFail($data['snmp_olt_id']);
        $zoneId = isset($data['zone_id']) ? (int) $data['zone_id'] : null;

        try {
            $this->service->assign(
                $olt,
                (int) $data['slot'],
                (int) $data['port'],
                (int) $data['onu_id'],
                $data['serial_number'] ?? null,
                $zoneId,
                $request->user()?->id,
            );
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        $iface = SmartOltSupport::onuInterfaceId(
            (int) $data['slot'],
            (int) $data['port'],
            (int) $data['onu_id'],
            SmartOltSupport::isC600($olt),
        );
        $zoneName = $zoneId !== null ? Zone::query()->find($zoneId)?->name : null;

        AuditLogger::log(
            AuditLog::EVENT_ONU_ZONE_ASSIGNED,
            $olt,
            ['subject_title' => $iface, 'zone_name' => $zoneName],
            $zoneName !== null
                ? "Set zone {$zoneName} untuk ONU {$iface}"
                : "Hapus zona ONU {$iface}",
        );

        return back()->with(
            'success',
            $zoneId !== null ? __('flash.onu_zone_assigned') : __('flash.onu_zone_cleared'),
        );
    }
}
