<?php

namespace App\Http\Controllers;

use App\Models\AlarmEvent;
use App\Services\Alarm\AlarmNotificationService;
use App\Services\Alarm\AlarmNotificationTargetResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NotificationsController extends Controller
{
    /**
     * Acción masiva: deja fila individual por alarma activa (además del timestamp global),
     * para que la lectura NO se deshaga cuando el poller refresque `last_seen_at`.
     */
    public function markAllRead(Request $request, AlarmNotificationService $notifications): RedirectResponse
    {
        $notifications->markAllRead($request->user());

        return back();
    }

    /**
     * POST /notifications/alarms/{alarm}/read — marca UNA alarma como leída.
     */
    public function markRead(Request $request, AlarmEvent $alarm, AlarmNotificationService $notifications): JsonResponse
    {
        $notifications->markRead($alarm, $request->user());

        return response()->json([
            'data' => ['unread_count' => $notifications->unreadCountFor($request->user())],
        ]);
    }

    /**
     * POST /notifications/alarms/{alarm}/open — marca la alarma como leída y devuelve a qué
     * pantalla debe navegar el cliente.
     *
     * El destino lo decide el SERVIDOR (no el navegador): así se comprueba el permiso en el
     * momento del clic, se sigue la ONU si cambió de puerto, y se evita abrir otra ONU que
     * haya reutilizado slot/port/onu_id. El route-model binding de {alarm} ya aplica
     * PartnerOltScope/DemoScope → 404 si el usuario no debe verla (sin revelar que existe).
     */
    public function open(
        Request $request,
        AlarmEvent $alarm,
        AlarmNotificationService $notifications,
        AlarmNotificationTargetResolver $resolver,
    ): JsonResponse {
        $user = $request->user();

        // Se marca leída aunque el destino no se pueda resolver: el usuario ya la vio.
        $notifications->markRead($alarm, $user);

        $target = $resolver->resolve($alarm);

        return response()->json([
            'data' => [
                'target_url' => $target['url'],
                'fallback_url' => $target['fallback_url'],
                'reason' => $target['reason'],
                'message' => $target['reason'] === null ? null : $this->reasonMessage($target['reason']),
                'unread_count' => $notifications->unreadCountFor($user),
            ],
        ]);
    }

    /**
     * Motivo traducido para el aviso al operador. Códigos estables (no dependen del idioma).
     */
    private function reasonMessage(string $reason): string
    {
        return match ($reason) {
            AlarmNotificationTargetResolver::REASON_ONU_NOT_FOUND => __('flash.notif_onu_not_found'),
            AlarmNotificationTargetResolver::REASON_POSITION_REUSED => __('flash.notif_position_reused'),
            AlarmNotificationTargetResolver::REASON_ONU_MOVED => __('flash.notif_onu_moved'),
            AlarmNotificationTargetResolver::REASON_INCOMPLETE_LOCATION => __('flash.notif_incomplete_location'),
            AlarmNotificationTargetResolver::REASON_OLT_UNAVAILABLE => __('flash.notif_olt_unavailable'),
            default => __('flash.notif_target_unavailable'),
        };
    }
}
