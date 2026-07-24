<?php

namespace App\Services\Alarm;

use App\Models\AlarmEvent;
use App\Models\AlarmNotificationRead;
use App\Models\User;

/**
 * Payload y estado de lectura de la campana de notificaciones.
 *
 * Una alarma cuenta como leída para un usuario si (a) tiene fila en
 * `alarm_notification_reads` (lectura individual) o (b) el timestamp global
 * `users.last_notifications_read_at` es posterior a `last_seen_at` ("marcar todas",
 * que se conserva por compatibilidad con el comportamiento anterior).
 */
class AlarmNotificationService
{
    /** Cuántas alarmas se muestran en el desplegable. */
    private const BELL_LIMIT = 8;

    /**
     * @return array{items:array<int,array<string,mixed>>, unread_count:int}
     */
    public function payloadFor(User $user): array
    {
        $alarms = AlarmEvent::query()
            ->with('olt:id,name')
            ->where('status', AlarmEvent::STATUS_ACTIVE)
            ->orderByDesc('last_seen_at')
            ->limit(self::BELL_LIMIT)
            ->get();

        // Una sola consulta para las lecturas individuales de las alarmas mostradas.
        $readIds = $alarms->isEmpty()
            ? []
            : AlarmNotificationRead::query()
                ->where('user_id', $user->id)
                ->whereIn('alarm_event_id', $alarms->pluck('id'))
                ->pluck('alarm_event_id')
                ->all();
        $readIds = array_flip($readIds);

        $items = $alarms
            ->map(fn (AlarmEvent $a) => [
                'id' => $a->id,
                'alarm_id' => $a->id,
                'olt_name' => $a->olt?->name,
                'severity' => $a->severity,
                'message' => $a->message,
                'created_at' => $a->last_seen_at?->toIso8601String(),
                // Identificadores ESTRUCTURADOS: el frontend nunca debe deducir la
                // ubicación del texto de `message` (que además está localizado).
                'resource_type' => $a->scope,
                'smartolt_id' => $a->snmp_olt_id,
                'board_id' => $a->slot,
                'port_id' => $a->port,
                'resource_id' => $a->onu_id,
                'serial_number' => $a->serial_number,
                // El destino NO se precalcula aquí a propósito: resolverlo exige leer el
                // snapshot del OLT (miles de ONU) y esto corre en CADA request Inertia.
                // Lo resuelve el endpoint `notifications.alarms.open` para una sola alarma.
                'is_read' => $this->isRead($a, $user, $readIds),
                'read_at' => $this->isRead($a, $user, $readIds)
                    ? ($a->last_seen_at?->toIso8601String())
                    : null,
            ])
            ->all();

        return [
            'items' => $items,
            'unread_count' => $this->unreadCountFor($user),
        ];
    }

    /**
     * Total de alarmas ACTIVAS no leídas — sobre todas, no solo las mostradas en la campana
     * (antes el contador solo miraba las 8 cargadas y por eso podía quedarse corto).
     */
    public function unreadCountFor(User $user): int
    {
        return AlarmEvent::query()
            ->where('status', AlarmEvent::STATUS_ACTIVE)
            ->when(
                $user->last_notifications_read_at !== null,
                fn ($query) => $query->where('last_seen_at', '>', $user->last_notifications_read_at),
            )
            ->whereNotExists(
                fn ($query) => $query->selectRaw('1')
                    ->from('alarm_notification_reads')
                    ->whereColumn('alarm_notification_reads.alarm_event_id', 'alarm_events.id')
                    ->where('alarm_notification_reads.user_id', $user->id),
            )
            ->count();
    }

    /**
     * Marca UNA alarma como leída para este usuario, sin tocar las demás.
     */
    public function markRead(AlarmEvent $alarm, User $user): void
    {
        AlarmNotificationRead::query()->updateOrCreate(
            ['user_id' => $user->id, 'alarm_event_id' => $alarm->id],
            ['read_at' => now()],
        );
    }

    /**
     * @param  array<int, int>  $readIds  mapa id-alarma => posición (array_flip)
     */
    private function isRead(AlarmEvent $alarm, User $user, array $readIds): bool
    {
        if (isset($readIds[$alarm->id])) {
            return true;
        }

        return $user->last_notifications_read_at !== null
            && $alarm->last_seen_at !== null
            && $alarm->last_seen_at <= $user->last_notifications_read_at;
    }
}
