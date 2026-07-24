<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marca de "leída" de UNA alarma por UN usuario (la alarma sigue siendo la fuente de
 * verdad; aquí no se duplica su contenido). Convive con el timestamp global
 * `users.last_notifications_read_at`, que sigue sirviendo para "marcar todas".
 *
 * Sin scope global a propósito: es una fila propia del usuario, y el acceso a la alarma
 * ya está filtrado por los scopes de {@see AlarmEvent}.
 */
class AlarmNotificationRead extends Model
{
    protected $fillable = [
        'user_id',
        'alarm_event_id',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function alarmEvent(): BelongsTo
    {
        return $this->belongsTo(AlarmEvent::class);
    }
}
