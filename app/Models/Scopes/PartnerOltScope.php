<?php

namespace App\Models\Scopes;

use App\Models\SnmpOlt;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Membatasi user role "partner" hanya pada OLT yang di-assign kepadanya:
 * - user partner → hanya baris yang OLT-nya ada di daftar assignment (olt_user),
 * - selain itu (admin/operator/demo, atau konteks console/queue tanpa auth) → tidak dibatasi.
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

        if (! $user || ! $user->isPartner()) {
            return;
        }

        $column = $model instanceof SnmpOlt ? 'id' : 'snmp_olt_id';

        // [0] = sentinel "tidak ada OLT" agar partner tanpa assignment tak melihat apa pun.
        $builder->whereIn(
            $model->getTable().'.'.$column,
            $user->allowedOltIds() ?: [0]
        );
    }
}
