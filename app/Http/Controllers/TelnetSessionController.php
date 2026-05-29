<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\SnmpOlt;
use App\Support\AuditLogger;
use App\Support\Telnet\TelnetTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelnetSessionController extends Controller
{
    public function token(Request $request, SnmpOlt $olt): JsonResponse
    {
        abort_unless((bool) $request->user()?->canManageOlt(), 403, 'Tidak punya izin telnet ke OLT.');

        if ($olt->cli_transport !== 'telnet') {
            return response()->json(['message' => 'CLI transport OLT bukan telnet. Set ke telnet di pengaturan OLT.'], 422);
        }

        if (! $olt->cli_username || ! $olt->cli_password) {
            return response()->json(['message' => 'Username/password CLI OLT belum diisi.'], 422);
        }

        $token = TelnetTicket::issue($request->user()->id, $olt->id);

        AuditLogger::log(
            AuditLog::EVENT_TELNET_OPENED,
            $olt,
            [],
            "Membuka sesi telnet ke OLT {$olt->name}",
        );

        return response()->json([
            'token' => $token,
            'ws_url' => $this->wsUrl($request, $token),
            'expires_in' => (int) config('telnet.ticket_ttl', 60),
        ]);
    }

    private function wsUrl(Request $request, string $token): string
    {
        $base = config('telnet.ws_url');

        if (! $base) {
            // No public URL configured: connect directly to the daemon port (dev/localhost).
            $base = sprintf('ws://%s:%d', $request->getHost(), (int) config('telnet.proxy.port', 6002));
        } elseif (str_starts_with($base, '/')) {
            // Relative path proxied by the web server: derive scheme + host from the request.
            $base = ($request->isSecure() ? 'wss' : 'ws').'://'.$request->getHost().$base;
        }

        $sep = str_contains($base, '?') ? '&' : '?';

        return rtrim($base, '/').$sep.'token='.urlencode($token);
    }
}
