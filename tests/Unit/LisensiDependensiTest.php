<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Penjaga lisensi dependensi: repo ini MIT (dan edisi publiknya open source),
 * jadi setiap paket yang ikut terbawa ke aplikasi wajib berlisensi permisif.
 *
 * Latar belakang (audit 2 Okt 2026): dua paket berganti lisensi diam-diam saat
 * naik versi mayor — typed.js 3 menjadi GPL-3.0 dan ApexCharts 5 menjadi
 * lisensi komunitas berbatas omzet (bukan open source). Keduanya sudah diganti
 * (efek ketik sendiri + Chart.js). Test ini menangkap kejadian berikutnya saat
 * `npm install`/`composer update`, sebelum sampai ke repo publik.
 *
 * Menambah pengecualian? Tulis alasannya di sini dan pastikan pemilik setuju.
 */
class LisensiDependensiTest extends TestCase
{
    /** Lisensi permisif — boleh untuk paket yang ikut ke aplikasi. */
    private const PERMISIF = [
        'MIT', 'MIT-0', 'ISC', 'BSD-2-Clause', 'BSD-3-Clause', 'Apache-2.0',
        '0BSD', 'Unlicense', 'CC0-1.0', 'BlueOak-1.0.0',
    ];

    /**
     * Tambahan khusus alat build/test npm (devDependencies): tidak ikut ke bundel
     * yang dikirim ke browser, jadi copyleft berkas (MPL) & lisensi data
     * (CC-BY: caniuse-lite) tidak membebani aplikasi.
     */
    private const DEV_NPM = ['MPL-2.0', 'CC-BY-4.0'];

    /**
     * LGPL boleh untuk PHP: dompdf dipakai sebagai pustaka lewat Composer tanpa
     * diubah — kewajiban LGPL hanya muncul bila pustakanya sendiri dimodifikasi.
     */
    private const COMPOSER_LGPL = ['LGPL-2.1', 'LGPL-2.1-only', 'LGPL-2.1-or-later', 'LGPL-3.0', 'LGPL-3.0-only', 'LGPL-3.0-or-later'];

    /**
     * Pengecualian per paket (nama => alasan).
     *
     * gsap: "Standard no-charge license" Webflow — gratis termasuk komersial,
     * bukan lisensi OSI; satu-satunya larangan adalah membuat alat animasi
     * visual pesaing Webflow. Dinilai aman oleh pemilik, 2 Okt 2026.
     */
    private const PENGECUALIAN_NPM = [
        'gsap' => 'Standard no-charge license (Webflow), disetujui 2 Okt 2026',
    ];

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public function test_paket_npm_berlisensi_permisif(): void
    {
        $lock = json_decode((string) file_get_contents($this->root().'/package-lock.json'), true, flags: JSON_THROW_ON_ERROR);

        $pelanggar = [];
        foreach ($lock['packages'] as $path => $paket) {
            if ($path === '') {
                continue; // proyek ini sendiri
            }

            $nama = substr($path, strrpos($path, 'node_modules/') + strlen('node_modules/'));
            $lisensi = $paket['license'] ?? null;
            $lisensi = is_array($lisensi) ? ($lisensi['type'] ?? null) : $lisensi;
            $dev = ! empty($paket['dev']);

            if (isset(self::PENGECUALIAN_NPM[$nama])) {
                continue;
            }

            $boleh = $dev ? [...self::PERMISIF, ...self::DEV_NPM] : self::PERMISIF;
            if (! $this->spdxDiizinkan($lisensi, $boleh)) {
                $pelanggar[] = sprintf('%s (%s): %s', $nama, $dev ? 'dev' : 'aplikasi', $lisensi ?? 'tanpa lisensi');
            }
        }

        $this->assertSame([], $pelanggar, "Paket npm berlisensi tidak permisif:\n".implode("\n", $pelanggar));
    }

    public function test_paket_composer_berlisensi_permisif(): void
    {
        $lock = json_decode((string) file_get_contents($this->root().'/composer.lock'), true, flags: JSON_THROW_ON_ERROR);

        $pelanggar = [];
        foreach (['packages', 'packages-dev'] as $bagian) {
            foreach ($lock[$bagian] ?? [] as $paket) {
                // Beberapa lisensi dalam satu larik = pilihan (OR): cukup satu yang boleh.
                $pilihan = $paket['license'] ?? [];
                $boleh = [...self::PERMISIF, ...self::COMPOSER_LGPL];
                if (! array_filter($pilihan, fn (string $l) => $this->spdxDiizinkan($l, $boleh))) {
                    $pelanggar[] = sprintf('%s: %s', $paket['name'], $pilihan ? implode(' OR ', $pilihan) : 'tanpa lisensi');
                }
            }
        }

        $this->assertSame([], $pelanggar, "Paket Composer berlisensi tidak permisif:\n".implode("\n", $pelanggar));
    }

    /** Ekspresi SPDX sederhana: "A", "(A OR B)", "A AND B". */
    private function spdxDiizinkan(?string $ekspresi, array $boleh): bool
    {
        if ($ekspresi === null || trim($ekspresi) === '') {
            return false;
        }

        $ekspresi = trim($ekspresi, " ()");
        if (str_contains($ekspresi, ' OR ')) {
            foreach (explode(' OR ', $ekspresi) as $bagian) {
                if ($this->spdxDiizinkan($bagian, $boleh)) {
                    return true;
                }
            }

            return false;
        }
        if (str_contains($ekspresi, ' AND ')) {
            foreach (explode(' AND ', $ekspresi) as $bagian) {
                if (! $this->spdxDiizinkan($bagian, $boleh)) {
                    return false;
                }
            }

            return true;
        }

        return in_array($ekspresi, $boleh, true);
    }
}
