<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Penjaga kontrak dua tema.
 *
 * tailwind.config.js membangun seluruh palet dari daftar nama di
 * tailwind.tokens.mjs menjadi `rgb(var(--kv-nama) / <alpha-value>)`.
 * Nilainya sendiri hidup di resources/css/app.css, satu blok per tema.
 *
 * Kalau sebuah nama dirujuk config tapi TIDAK dideklarasikan di app.css,
 * nilai `rgb(var(--kv-nama) / 1)` menjadi invalid: browser mengabaikan
 * propertinya dan elemen itu jadi transparan — tanpa error build, tanpa
 * error konsol. Kelas yang "hilang" seperti ini pernah lolos berbulan-bulan
 * (lihat UI_DESIGN_SYSTEM.md §4 soal kv-btn-cyan). Test ini yang menangkapnya.
 */
class ThemeTokenTest extends TestCase
{
    private function tokenNames(): array
    {
        $src = file_get_contents(base_path('tailwind.tokens.mjs'));

        $list = function (string $name) use ($src): array {
            preg_match('/export const '.$name.' = \[(.*?)\];/s', $src, $m);
            $this->assertNotEmpty($m, "Daftar {$name} tidak ditemukan di tailwind.tokens.mjs");

            // buang komentar supaya kata di dalamnya tidak ikut terbaca
            $body = preg_replace('#/\*.*?\*/#s', '', $m[1]);
            preg_match_all("/'([^']+)'|(\d+)/", $body, $items, PREG_SET_ORDER);

            return array_map(fn ($i) => $i[1] !== '' ? $i[1] : $i[2], $items);
        };

        $ramps = $list('RAMPS');
        $stops = $list('STOPS');

        $names = [];
        foreach ($ramps as $ramp) {
            foreach ($stops as $stop) {
                $names[] = "{$ramp}-{$stop}";
            }
        }

        return array_merge($names, $list('SINGLES'), $list('SURFACES'), $list('CANVASES'));
    }

    /** @return array<string, list<string>> blok tema => nama variabel yang dideklarasikan */
    private function declaredPerTheme(): array
    {
        $css = file_get_contents(base_path('resources/css/app.css'));

        preg_match_all('/\[data-theme="([a-z]+)"\]\s*\{(.*?)\n\}/s', $css, $blocks, PREG_SET_ORDER);
        $this->assertNotEmpty($blocks, 'Tidak ada blok [data-theme="..."] di app.css');

        // DIGABUNG, bukan ditimpa: satu tema boleh muncul di lebih dari satu
        // aturan (blok token, lalu color-scheme di @layer base). Menimpa
        // membuat aturan terakhir yang kebetulan tanpa token menghapus
        // seluruh daftar dan test lulus/gagal karena alasan yang salah.
        $declared = [];
        foreach ($blocks as $block) {
            preg_match_all('/--kv-([a-z0-9-]+)\s*:/', $block[2], $vars);
            $declared[$block[1]] = array_merge($declared[$block[1]] ?? [], $vars[1]);
        }

        return $declared;
    }

    #[Test]
    public function setiap_token_punya_deklarasi_di_setiap_blok_tema(): void
    {
        $names = $this->tokenNames();
        $this->assertGreaterThan(200, count($names), 'Daftar token tampak terpotong');

        foreach ($this->declaredPerTheme() as $theme => $declared) {
            $missing = array_diff($names, $declared);

            $this->assertEmpty($missing, sprintf(
                "Tema [%s] tidak mendeklarasikan %d token: %s.\n".
                'Tambahkan --kv-<nama> di blok [data-theme="%s"] pada resources/css/app.css.',
                $theme,
                count($missing),
                implode(', ', array_slice($missing, 0, 15)),
                $theme
            ));
        }
    }

    #[Test]
    public function semua_blok_tema_mendeklarasikan_token_yang_sama_persis(): void
    {
        $perTheme = $this->declaredPerTheme();

        if (count($perTheme) < 2) {
            $this->markTestSkipped('Baru ada satu blok tema; pemeriksaan ini relevan sejak tema terang dibuat.');
        }

        $themes = array_keys($perTheme);
        $first = $themes[0];

        foreach (array_slice($themes, 1) as $theme) {
            $this->assertEqualsCanonicalizing(
                $perTheme[$first],
                $perTheme[$theme],
                "Blok tema [{$first}] dan [{$theme}] mendeklarasikan himpunan token yang berbeda. ".
                'Tema yang kekurangan satu token akan mewarisi nilai tema lain secara tak terduga.'
            );
        }
    }

    #[Test]
    public function nilai_token_berbentuk_channel_rgb_bukan_heks(): void
    {
        $css = file_get_contents(base_path('resources/css/app.css'));
        preg_match_all('/\[data-theme="([a-z]+)"\]\s*\{(.*?)\n\}/s', $css, $blocks, PREG_SET_ORDER);

        foreach ($blocks as [$_b, $theme, $body]) {
            preg_match_all('/--kv-([a-z0-9-]+)\s*:\s*([^;]+);/', $body, $vars, PREG_SET_ORDER);

            foreach ($vars as [$_full, $name, $value]) {
                $this->assertMatchesRegularExpression(
                    '/^\d{1,3} \d{1,3} \d{1,3}$/',
                    trim($value),
                    "Token --kv-{$name} di tema [{$theme}] bernilai '".trim($value)."'. ".
                    'Formatnya wajib channel RGB tanpa pembungkus (mis. `15 23 42`) — '.
                    'heks atau rgb() membuat modifier opasitas `/70` berhenti bekerja.'
                );
            }
        }
    }
}
