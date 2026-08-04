<?php

use App\Models\AlarmEvent;
use App\Models\PartnerTelegramBot;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PUSATKAN kebijakan alarm di `alarm_settings` (Settings → tab Alarm).
 *
 * Sebelumnya filter alarm (severity minimum, kirim saat naik/pulih, jenis alarm) diduplikasi
 * di `telegram_settings` DAN `fcm_settings` — dua tempat, mudah beda & membingungkan. Kini satu
 * baris kebijakan dipakai bersama kanal Telegram (bot global) + push mobile; tab kanal hanya
 * mengurus koneksinya (token/chat id, saklar push, daftar perangkat).
 *
 * Kolom lama di kedua tabel kanal SENGAJA tidak dihapus: bot Telegram per-partner
 * ({@see PartnerTelegramBot}) masih memakai filter per-bot miliknya sendiri,
 * dan menyimpan nilai lama membuat rollback aman.
 *
 * Dua kolom korelasi root-cause juga masuk ke sini:
 *  - `suppress_child_alarms` — port PON / ODP down mensupres notifikasi alarm ONU di bawahnya.
 *  - `group_odp_alarms`      — beberapa ONU 1 ODP down → 1 pesan grup, bukan pesan per pelanggan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alarm_settings', function (Blueprint $table) {
            $table->string('min_severity')->default(AlarmEvent::SEVERITY_WARNING);
            $table->boolean('notify_on_raise')->default(true);
            $table->boolean('notify_on_clear')->default(false);
            $table->json('notify_types')->nullable(); // null = semua jenis
            $table->boolean('suppress_child_alarms')->default(true);
            $table->boolean('group_odp_alarms')->default(true);
        });

        $this->backfillFromTelegram();
    }

    public function down(): void
    {
        Schema::table('alarm_settings', function (Blueprint $table) {
            $table->dropColumn([
                'min_severity',
                'notify_on_raise',
                'notify_on_clear',
                'notify_types',
                'suppress_child_alarms',
                'group_odp_alarms',
            ]);
        });
    }

    /**
     * Warisi kebijakan yang sudah dipakai admin di tab Telegram supaya perilaku notifikasi
     * tidak berubah diam-diam setelah pemusatan. Jenis alarm baru `odp_down` ikut dicentang
     * bila admin memakai filter jenis (kalau null = semua jenis, otomatis ikut).
     */
    private function backfillFromTelegram(): void
    {
        if (! Schema::hasTable('telegram_settings')) {
            return;
        }

        $telegram = DB::table('telegram_settings')->first();

        if ($telegram === null) {
            return;
        }

        $types = $this->decodeTypes($telegram->notify_types ?? null);

        if ($types !== null && ! in_array(AlarmEvent::TYPE_ODP_DOWN, $types, true)) {
            $types[] = AlarmEvent::TYPE_ODP_DOWN;
        }

        $values = [
            'min_severity' => $telegram->min_severity ?? AlarmEvent::SEVERITY_WARNING,
            'notify_on_raise' => (bool) ($telegram->notify_on_raise ?? true),
            'notify_on_clear' => (bool) ($telegram->notify_on_clear ?? false),
            'notify_types' => $types === null ? null : json_encode(array_values($types)),
            'updated_at' => now(),
        ];

        $existing = DB::table('alarm_settings')->first();

        if ($existing !== null) {
            DB::table('alarm_settings')->where('id', $existing->id)->update($values);

            return;
        }

        DB::table('alarm_settings')->insert($values + [
            'confirm_before_notify' => true,
            'suppress_child_alarms' => true,
            'group_odp_alarms' => true,
            'created_at' => now(),
        ]);
    }

    /**
     * @return array<int, string>|null
     */
    private function decodeTypes(mixed $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);

        return is_array($decoded) ? array_values($decoded) : null;
    }
};
