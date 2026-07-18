<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * C600 (Model B / SmartOLT) memakai transport WAN **TR069/VEIP**, bukan pppoe/dhcp/static.
 * Registrasi C600 mencatat `wan_mode = 'tr069'` di audit, jadi CHECK constraint perlu memuatnya
 * (kalau tidak: SQLSTATE 23514). Perluas nilai enum (lanjutan migrasi bridge).
 *
 * Prod = PostgreSQL (constraint bernama). Test = SQLite (enum di-rebuild via Schema helper).
 */
return new class extends Migration
{
    private const VALUES = ['pppoe', 'dhcp', 'static', 'bridge', 'tr069'];

    public function up(): void
    {
        $this->setWanModeValues(self::VALUES);
    }

    public function down(): void
    {
        $this->setWanModeValues(['pppoe', 'dhcp', 'static', 'bridge']);
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

        Schema::table('smartolt_onu_registrations', function ($table) use ($values) {
            $table->enum('wan_mode', $values)->default('pppoe')->change();
        });
    }
};
