<?php

namespace App\Models\Concerns;

use App\Support\AuditLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * Mencatat perubahan model (created/updated/deleted) ke audit_logs secara
 * otomatis. Atribut sensitif ($hidden, password, dll.) tidak pernah ikut
 * tersimpan. Model dapat menambah pengecualian lewat properti $auditExclude
 * dan memberi label/judul lewat method auditLabel()/auditTitle().
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (Model $model) {
            AuditLogger::model('created', $model, ['attributes' => $model->auditableSnapshot()]);
        });

        static::updated(function (Model $model) {
            $changes = $model->auditableChanges();

            if ($changes['new'] === []) {
                return;
            }

            AuditLogger::model('updated', $model, $changes);
        });

        static::deleted(function (Model $model) {
            AuditLogger::model('deleted', $model, ['attributes' => $model->auditableSnapshot()]);
        });
    }

    /**
     * @return array<int, string>
     */
    public function auditableExcluded(): array
    {
        return array_merge(
            ['id', 'created_at', 'updated_at', 'remember_token', 'password'],
            $this->getHidden(),
            property_exists($this, 'auditExclude') ? $this->auditExclude : [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function auditableSnapshot(): array
    {
        return collect($this->getAttributes())
            ->except($this->auditableExcluded())
            ->all();
    }

    /**
     * @return array{old: array<string, mixed>, new: array<string, mixed>}
     */
    public function auditableChanges(): array
    {
        $new = collect($this->getChanges())
            ->except($this->auditableExcluded())
            ->all();

        $old = collect($this->getOriginal())
            ->only(array_keys($new))
            ->all();

        return ['old' => $old, 'new' => $new];
    }
}
