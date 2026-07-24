<?php

namespace Database\Seeders;

use App\Models\Zone;
use Illuminate\Database\Seeder;

/**
 * Zona geografis awal. Idempotent (firstOrCreate) — aman dijalankan ulang.
 * Zona baru ke depannya ditambahkan dari UI (Settings → Zones), bukan lewat seeder ini.
 */
class ZoneSeeder extends Seeder
{
    public function run(): void
    {
        $names = [
            'LAS GALERAS',
            'GALERAS CENTRO',
            'PALMARITO',
            'RINCON',
            'LA COLMENA',
            'LOS TOCONES',
            'GUAZUMA',
            'SEIBATABLODUA',
            'LLANADA AL MEDIO',
            'BARRIO LA PLANTA',
            'EL VALLE',
        ];

        foreach ($names as $name) {
            Zone::query()->firstOrCreate(['name' => $name]);
        }
    }
}
