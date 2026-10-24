<?php

namespace App\Http\Controllers;

use App\Models\SnmpOlt;
use App\Services\Olt\OltConfigAutosaveService;
use Illuminate\Http\RedirectResponse;

class OltConfigAutosaveController extends Controller
{
    /**
     * Saklar auto save per-OLT — satu rute untuk semua family, otorisasi `role:admin,operator,partner`
     * (= canManageOlt, sama dengan tombol Save; partner hanya OLT-nya). Menyalakan butuh OLT yang bisa di-save NMS
     * ({@see OltConfigAutosaveService::supports()}); mematikan selalu boleh.
     */
    public function toggle(SnmpOlt $olt, OltConfigAutosaveService $autosave): RedirectResponse
    {
        $enabled = ! $olt->config_autosave_enabled;

        if ($enabled && ! $autosave->supports($olt)) {
            return back()->with('error', __('flash.autosave_unsupported', ['name' => $olt->name]));
        }

        $olt->update(['config_autosave_enabled' => $enabled]);

        return back()->with(
            'success',
            $enabled
                ? __('flash.autosave_on', ['name' => $olt->name])
                : __('flash.autosave_off', ['name' => $olt->name]),
        );
    }
}
