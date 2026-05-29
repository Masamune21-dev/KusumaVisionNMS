<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Titik tunggal untuk menulis baris audit. Otomatis menangkap aktor (user),
 * IP, dan user-agent dari request berjalan bila ada.
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public static function log(
        string $event,
        ?Model $auditable = null,
        array $properties = [],
        ?string $description = null,
        ?Authenticatable $actor = null,
    ): ?AuditLog {
        $actor ??= auth()->user();
        $request = request();

        return AuditLog::create([
            'user_id' => $actor?->getAuthIdentifier(),
            'user_name' => $actor?->name,
            'event' => $event,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'description' => $description,
            'properties' => $properties === [] ? null : $properties,
            'ip_address' => $request?->ip(),
            'user_agent' => $request !== null ? mb_substr((string) $request->userAgent(), 0, 1000) : null,
        ]);
    }

    /**
     * Tulis audit untuk perubahan model (created/updated/deleted) dengan
     * deskripsi yang dibangun dari label & judul model.
     *
     * @param  array<string, mixed>  $properties
     */
    public static function model(string $event, Model $model, array $properties = []): ?AuditLog
    {
        $label = method_exists($model, 'auditLabel') ? $model->auditLabel() : class_basename($model);
        $title = method_exists($model, 'auditTitle') ? (string) $model->auditTitle() : '#'.$model->getKey();

        $verb = [
            AuditLog::EVENT_CREATED => 'Menambahkan',
            AuditLog::EVENT_UPDATED => 'Memperbarui',
            AuditLog::EVENT_DELETED => 'Menghapus',
        ][$event] ?? $event;

        $description = trim(sprintf('%s %s %s', $verb, $label, $title));

        return static::log($event, $model, $properties, $description);
    }
}
