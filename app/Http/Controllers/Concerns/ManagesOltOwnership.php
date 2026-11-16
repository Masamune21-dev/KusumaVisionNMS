<?php

namespace App\Http\Controllers\Concerns;

use App\Models\SnmpOlt;
use App\Models\User;
use App\Support\OltTargetGuard;
use Closure;
use Illuminate\Http\Request;

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
            abort_unless($user->ownsOlt($olt), 403, __('olt.delete_own_olt_only'));
        }
    }

    /**
     * Aturan validasi `ip` OLT (semua family) — {@see OltTargetGuard}. Pemiliknya dinilai dari
     * OLT yang diubah, atau dari pembuatnya untuk OLT baru (partner → privat miliknya).
     * Nilai yang bukan IP dibiarkan ke aturan `ip` supaya tak muncul dua pesan galat.
     */
    protected function oltTargetRule(Request $request, ?SnmpOlt $olt = null): Closure
    {
        $user = $request->user();
        $ownerId = $olt ? $olt->owner_user_id : ($user?->isPartner() ? $user->id : null);

        return function (string $attribute, mixed $value, Closure $fail) use ($ownerId): void {
            if (! is_string($value) || filter_var($value, FILTER_VALIDATE_IP) === false) {
                return;
            }

            if (! OltTargetGuard::allowsAddress($value, $ownerId)) {
                $fail($ownerId === null ? __('olt.target_not_allowed') : __('olt.target_partner_range'));
            }
        };
    }

    /**
     * Kolom koneksi OLT yang dikunci untuk partner pada OLT global
     * yang di-assign: alamat, port, komunitas SNMP, transport & kredensial CLI, port web (HSGQ).
     *
     * @var list<string>
     */
    protected const OLT_CONNECTION_FIELDS = [
        'ip', 'snmp_port', 'snmp_version', 'snmp_read_community', 'snmp_write_community',
        'cli_transport', 'cli_port', 'cli_username', 'cli_password', 'web_port',
    ];

    /**
     * Guard update OLT (semua family). Partner boleh mengubah nama,
     * vendor, polling, dsb. pada OLT global yang di-assign, tetapi setiap upaya
     * mengganti kolom koneksi (IP/port/SNMP/CLI) ditolak 403 — kecuali OLT itu
     * privat miliknya. Staf Pusat tidak dibatasi.
     *
     * @param  array<string, mixed>  $data  payload tervalidasi (setelah secret kosong dibuang)
     */
    protected function authorizeOltUpdate(SnmpOlt $olt, ?User $user, array $data): void
    {
        if (! $user || $user->canEditOltConnection($olt)) {
            return;
        }

        foreach (self::OLT_CONNECTION_FIELDS as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $incoming = $data[$field];
            $current = $olt->{$field};

            // Normalisasi: port/angka dibandingkan sebagai string agar "23" == 23.
            if ((string) ($incoming ?? '') !== (string) ($current ?? '')) {
                abort(403, __('olt.connection_fields_central_only'));
            }
        }
    }

    /**
     * Guard uji koneksi/probe OLT: partner hanya pada OLT miliknya.
     */
    protected function authorizeOltConnectionTest(SnmpOlt $olt, ?User $user): void
    {
        if ($user && ! $user->canEditOltConnection($olt)) {
            abort(403, __('olt.connection_test_forbidden'));
        }
    }

    /**
     * Guard akses rahasia OLT (CLI telnet, isi backup running-config).
     */
    protected function authorizeOltSecretAccess(SnmpOlt $olt, ?User $user): void
    {
        abort_unless((bool) $user?->canAccessOltSecrets($olt), 403, __('olt.secret_access_forbidden'));
    }
}
