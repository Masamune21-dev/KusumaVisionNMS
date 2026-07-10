<?php

namespace App\Models\Scopes;

use App\Models\SnmpOlt;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Menegakkan visibilitas OLT sesuai kepemilikan & penugasan:
 *
 * - User ter-scope (lihat {@see User::isOltScoped()}) — partner (selalu) atau
 *   operator dengan assignment → HANYA OLT dalam daftar boleh-akses-nya
 *   ({@see User::allowedOltIds()}: penugasan pivot + OLT privat miliknya).
 * - User TAK ter-scope (admin, operator tanpa assignment, demo) → semua OLT
 *   GLOBAL (`owner_user_id` NULL), TAPI OLT privat milik partner (`owner_user_id`
 *   terisi) disembunyikan total — termasuk dari admin.
 * - Konteks console/queue tanpa auth (mis. scheduler polling) → tidak dibatasi.
 *
 * Dipasang pada {@see SnmpOlt} (kolom id) dan model ber-`snmp_olt_id`
 * (AlarmEvent, PollingEvent, SmartOltOnuRegistration, OnuMapPin). Digabung AND
 * dengan {@see DemoScope} bila ada.
 */
class PartnerOltScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = auth()->user();

        // Konteks console/queue (scheduler poll semua OLT termasuk privat).
        if (! $user) {
            return;
        }

        $table = $model->getTable();

        if ($user->isOltScoped()) {
            $column = $model instanceof SnmpOlt ? 'id' : 'snmp_olt_id';

            // [0] = sentinel "tidak ada OLT" agar user tanpa akses tak melihat apa pun.
            $builder->whereIn($table.'.'.$column, $user->allowedOltIds() ?: [0]);

            return;
        }

        // Non-scoped: sembunyikan OLT privat milik partner (owner_user_id terisi).
        if ($model instanceof SnmpOlt) {
            $builder->whereNull($table.'.owner_user_id');

            return;
        }

        $builder->whereNotIn(
            $table.'.snmp_olt_id',
            SnmpOlt::query()
                ->withoutGlobalScopes()
                ->whereNotNull('owner_user_id')
                ->select('id')
        );
    }
}
