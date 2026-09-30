<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Jenis alarm baru `port_disabled` (port PON dimatikan dari NMS) ikut dicentang di filter jenis
 * notifikasi yang berupa daftar eksplisit — tanpa ini satu-satunya notifikasi "port dimatikan"
 * yang diminta user tak pernah terkirim. Daftar null (= semua jenis) dibiarkan apa adanya.
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
        $rows = DB::table('alarm_settings')->whereNotNull('notify_types')->get(['id', 'notify_types']);

        foreach ($rows as $row) {
            $types = json_decode((string) $row->notify_types, true);

            if (! is_array($types)) {
                continue;
            }

            DB::table('alarm_settings')
                ->where('id', $row->id)
                ->update(['notify_types' => json_encode(array_values($change($types)))]);
        }
    }
};
