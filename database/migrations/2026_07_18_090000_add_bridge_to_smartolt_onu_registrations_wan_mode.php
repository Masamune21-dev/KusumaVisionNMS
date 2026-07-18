<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mode WAN "bridge" sudah didukung di validasi (OnuRegistrationService) & script
 * builder, tapi CHECK constraint kolom `wan_mode` (dari enum awal) hanya
 * mengizinkan pppoe/dhcp/static — sehingga registrasi bridge gagal 500
 * (SQLSTATE 23514) baik dari web maupun aplikasi mobile. Perluas constraint.
 *
 * Prod = PostgreSQL (constraint bernama, bisa di-swap). Test = SQLite
 * (constraint inline tanpa nama dari enum awal) — di-rebuild via helper.
 */
return new class extends Migration
{
    private const VALUES = ['pppoe', 'dhcp', 'static', 'bridge'];

    public function up(): void
    {
        $this->setWanModeValues(self::VALUES);
    }

    public function down(): void
    {
        $this->setWanModeValues(['pppoe', 'dhcp', 'static']);
    }

    private function setWanModeValues(array $values): void
    {
        $driver = Schema::getConnection()->getDriverName();
        $constraint = 'smartolt_onu_registrations_wan_mode_check';

        if ($driver === 'pgsql') {
            $list = collect($values)->map(fn ($v) => "'{$v}'")->implode(', ');
            DB::statement("ALTER TABLE smartolt_onu_registrations DROP CONSTRAINT IF EXISTS {$constraint}");
            DB::statement("ALTER TABLE smartolt_onu_registrations ADD CONSTRAINT {$constraint} CHECK ((wan_mode)::text IN ({$list}))");

            return;
        }

        // SQLite: enum awal menaruh CHECK inline tanpa nama, tak bisa di-DROP.
        // Recreate kolom sebagai enum baru via Schema (SQLiteBuilder rebuild tabel,
        // data & indeks dipertahankan). Aman untuk test (tabel kosong).
        Schema::table('smartolt_onu_registrations', function ($table) use ($values) {
            $table->enum('wan_mode', $values)->default('pppoe')->change();
        });
    }
};
