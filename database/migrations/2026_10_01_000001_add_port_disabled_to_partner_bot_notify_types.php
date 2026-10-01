<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pasangan migrasi `2026_09_30_000001_add_port_disabled_to_alarm_notify_types` untuk bot Telegram
 * partner: bot yang filter jenisnya berupa daftar eksplisit ikut mencentang `port_disabled`, supaya
 * partner yang mematikan/menyalakan port PON di OLT-nya sendiri menerima notifikasinya. Daftar null
 * (= semua jenis) dibiarkan apa adanya.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rewrite(fn (array $types) => in_array('port_disabled', $types, true) ? $types : [...$types, 'port_disabled']);
    }

    public function down(): void
    {
        $this->rewrite(fn (array $types) => array_diff($types, ['port_disabled']));
    }

    private function rewrite(callable $change): void
    {
        $rows = DB::table('partner_telegram_bots')->whereNotNull('notify_types')->get(['id', 'notify_types']);

        foreach ($rows as $row) {
            $types = json_decode((string) $row->notify_types, true);

            if (! is_array($types)) {
                continue;
            }

            DB::table('partner_telegram_bots')
                ->where('id', $row->id)
                ->update(['notify_types' => json_encode(array_values($change($types)))]);
        }
    }
};
