<?php

namespace App\Http\Controllers;

use App\Models\SnmpOlt;
use App\Services\OltPortLabelService;
use App\Support\SmartOltSupport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Label port PON sisi-NMS — dipakai bersama halaman C-Data, HiOSO, dan HsAirPo.
 *
 * OLT ZTE sengaja TIDAK memakai endpoint ini: deskripsi portnya ditulis langsung ke perangkat
 * lewat `smartolt.port.description` (capability `supports_port_description_write`). Gerbangnya
 * capability `supports_port_label`, yang hanya menyala di family non-ZTE.
 */
class OltPortLabelController extends Controller
{
    public function store(Request $request, SnmpOlt $olt, OltPortLabelService $labels): RedirectResponse
    {
        abort_unless((bool) $request->user()?->canManageOlt(), 403);
        abort_unless(
            (bool) (SmartOltSupport::capabilities(SmartOltSupport::driverKey($olt), $olt)['supports_port_label'] ?? false),
            403,
            'OLT ini menamai portnya di perangkat, bukan lewat label NMS.',
        );

        $data = $request->validate([
            'slot' => ['required', 'integer', 'min:0', 'max:99'],
            'port' => ['required', 'integer', 'min:0', 'max:99'],
            'label' => ['nullable', 'string', 'max:'.OltPortLabelService::MAX_LENGTH],
        ]);

        $saved = $labels->set($olt, (int) $data['slot'], (int) $data['port'], $data['label'] ?? null);

        return back()->with('success', $saved === null
            ? __('flash.port_label_cleared')
            : __('flash.port_label_saved', ['label' => $saved]));
    }
}
