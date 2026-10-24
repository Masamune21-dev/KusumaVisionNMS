<?php

namespace App\Http\Middleware;

use App\Models\SnmpOlt;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tandai OLT rute ini "diubah, belum di-save" ({@see SnmpOlt::markConfigChanged()}) setelah aksi
 * tulis yang tidak ditolak (status < 400) — bahan auto save. Alias `olt.config-changed`, dipasang
 * HANYA di rute yang mengubah running-config OLT. Bukan untuk reboot/refresh/preview, rute yang
 * sudah `write` sendiri (VLAN uplink & deskripsi port ZTE), dan bukan "Matikan Port" PON (sengaja
 * tanpa `write`). Rute yang terlewat tetap tertangkap auto save harian.
 */
class MarkOltConfigChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $olt = $request->route('olt');
        if ($olt instanceof SnmpOlt && $response->getStatusCode() < 400) {
            SnmpOlt::markConfigChanged($olt->id);
        }

        return $response;
    }
}
