<?php

namespace App\Http\Controllers\Concerns;

use App\Models\SnmpOlt;
use App\Models\User;

/**
 * Perilaku kepemilikan OLT bersama untuk controller inventori (ZTE/C-Data/HiOSO).
 *
 * Saat seorang PARTNER menambah OLT, OLT itu menjadi PRIVAT miliknya
 * (`owner_user_id` = id partner) dan otomatis di-assign ke dirinya lewat pivot
 * `olt_user` supaya mesin scope/alarm/Telegram/FCM tetap berfungsi. OLT yang
 * ditambah admin/operator tetap global (`owner_user_id` = null).
 */
trait ManagesOltOwnership
{
    /**
     * Bila $user seorang partner, jadikan OLT ini privat miliknya.
     * owner_user_id di-set via forceFill (bukan mass-assignment) agar tak bisa
     * dipalsukan lewat request.
     */
    protected function claimOltForPartner(SnmpOlt $olt, ?User $user): void
    {
        if (! $user?->isPartner()) {
            return;
        }

        $olt->forceFill(['owner_user_id' => $user->id])->save();
        $olt->partners()->syncWithoutDetaching([
            $user->id => ['alarms_enabled' => true],
        ]);
    }

    /**
     * Cegah partner menghapus OLT yang bukan miliknya. Admin/operator bebas
     * (mereka hanya melihat OLT global, dan route model binding sudah membatasi
     * partner ke OLT dalam scope-nya — guard ini menutup celah OLT global yang
     * kebetulan ter-assign ke partner).
     */
    protected function authorizeOltDeletion(SnmpOlt $olt, ?User $user): void
    {
        if ($user && $user->isPartner()) {
            abort_unless($user->ownsOlt($olt), 403, 'Anda hanya boleh menghapus OLT milik Anda sendiri.');
        }
    }
}
