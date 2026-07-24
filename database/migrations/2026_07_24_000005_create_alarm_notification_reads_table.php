<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lecturas per-USUARIO-per-ALARMA. Antes solo existía el timestamp global
        // users.last_notifications_read_at ("marcar todas"), que hacía imposible marcar
        // UNA sola notificación sin marcar también todas las anteriores.
        // La alarma sigue siendo la fuente de verdad; aquí no se duplica su contenido.
        Schema::create('alarm_notification_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('alarm_event_id')->constrained('alarm_events')->cascadeOnDelete();
            $table->timestamp('read_at');
            $table->timestamps();

            $table->unique(['user_id', 'alarm_event_id'], 'alarm_notification_reads_unique');
            // Contador de no-leídas filtra por usuario.
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alarm_notification_reads');
    }
};
