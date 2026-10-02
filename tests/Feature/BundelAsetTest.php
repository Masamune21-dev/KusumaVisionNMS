<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Penjaga bentuk bundel produksi.
 *
 * Ada satu kekeliruan yang lolos berbulan-bulan tanpa terlihat, karena tidak ada
 * satu pun test yang memeriksa HASIL build: aturan `manualChunks` yang memaksa
 * pustaka grafik (dulu ApexCharts) ke chunk bernama sendiri membuat Rollup
 * mengangkatnya menjadi impor STATIS milik app.js. Akibatnya ±1,1 MB grafik ikut
 * diunduh, didekompres, dan dikompilasi di SETIAP halaman — termasuk daftar ONU
 * dan Peta yang tidak punya satu grafik pun.
 *
 * Sejak 2 Okt 2026 grafik memakai Chart.js, dimuat lewat import() di
 * Components/Charts/ChartCanvas.vue ke chunk `resources/js/lib/charts.js`.
 * Kode sumbernya terlihat benar; yang salah hanya keluaran build. Jadi yang
 * diperiksa di sini memang manifest, bukan berkas .vue.
 */
class BundelAsetTest extends TestCase
{
    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $path = public_path('build/manifest.json');

        if (! file_exists($path)) {
            $this->markTestSkipped('public/build/manifest.json belum ada — jalankan `npm run build`.');
        }

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Seluruh chunk yang ikut terunduh SEBELUM halaman sempat berjalan, yaitu
     * kunci yang diberikan beserta impor statisnya secara rekursif.
     *
     * @param  array<string, mixed>  $manifest
     * @param  array<string, bool>  $terlihat
     * @return array<int, string>
     */
    private function statisDari(array $manifest, string $kunci, array &$terlihat = []): array
    {
        if (isset($terlihat[$kunci]) || ! isset($manifest[$kunci])) {
            return array_keys($terlihat);
        }

        $terlihat[$kunci] = true;

        foreach ($manifest[$kunci]['imports'] ?? [] as $impor) {
            $this->statisDari($manifest, $impor, $terlihat);
        }

        return array_keys($terlihat);
    }

    private const CHUNK_GRAFIK = 'resources/js/lib/charts.js';

    /** Kunci manifest yang berisi kode pustaka grafik. */
    private function kodeGrafik(string $kunci): bool
    {
        return $kunci === self::CHUNK_GRAFIK || str_contains($kunci, 'node_modules/chart.js');
    }

    public function test_grafik_tidak_pernah_jadi_impor_statis_entry_mana_pun(): void
    {
        $manifest = $this->manifest();

        // Diperiksa untuk SEMUA entry, bukan hanya app.js: NMS juga memakai
        // Pages/Welcome.vue sebagai entry terpisah (halaman depan publik), dan
        // halaman itu paling tidak boleh menyeret pustaka grafik.
        $entries = array_keys(array_filter(
            $manifest,
            fn (array $c) => ! empty($c['isEntry']),
        ));

        $this->assertNotEmpty($entries, 'Manifest tidak punya entry sama sekali.');

        foreach ($entries as $entry) {
            $terlihat = [];
            $statis = $this->statisDari($manifest, $entry, $terlihat);
            $pelanggar = array_values(array_filter($statis, fn (string $k) => $this->kodeGrafik($k)));

            $this->assertSame([], $pelanggar, implode("\n", [
                "Pustaka grafik kembali menjadi impor STATIS dari entry `{$entry}`.",
                'Artinya grafik diunduh & dikompilasi di setiap halaman, juga yang tanpa grafik.',
                'Penyebab yang sudah pernah terjadi: aturan `manualChunks` di vite.config.js yang',
                'menyebut pustaka grafik — Rollup mengangkat chunk bernama menjadi dependensi statis entry.',
                'Chart.js hanya boleh masuk lewat import() di Components/Charts/ChartCanvas.vue.',
            ]));
        }
    }

    public function test_grafik_tetap_dimuat_secara_dinamis_oleh_dashboard(): void
    {
        $manifest = $this->manifest();

        $this->assertArrayHasKey('resources/js/Pages/Dashboard.vue', $manifest);
        $this->assertArrayHasKey(self::CHUNK_GRAFIK, $manifest, 'Chunk grafik tidak ada di manifest.');
        $this->assertTrue((bool) ($manifest[self::CHUNK_GRAFIK]['isDynamicEntry'] ?? false), 'Chunk grafik bukan entry dinamis.');

        // ChartCanvas berada di chunk bersama yang diimpor statis oleh Dashboard;
        // chunk itulah yang memuat grafik secara dinamis.
        $terlihat = [];
        $statis = $this->statisDari($manifest, 'resources/js/Pages/Dashboard.vue', $terlihat);
        $pemuat = array_values(array_filter(
            $statis,
            fn (string $k) => in_array(self::CHUNK_GRAFIK, $manifest[$k]['dynamicImports'] ?? [], true),
        ));

        $this->assertNotEmpty(
            $pemuat,
            'Dashboard tidak lagi memuat grafik secara dinamis — grafiknya mungkin hilang, '
            .'atau malah kembali ditarik statis. Periksa Components/Charts/ChartCanvas.vue.',
        );
    }

    public function test_halaman_tidak_pecah_jadi_puluhan_chunk_mungil(): void
    {
        $manifest = $this->manifest();

        $terlihat = [];
        $this->statisDari($manifest, 'resources/js/Pages/SmartOlt/OnuDetail.vue', $terlihat);
        $this->statisDari($manifest, 'resources/js/app.js', $terlihat);

        $berkas = array_filter(
            array_keys($terlihat),
            fn (string $k) => file_exists(public_path('build/'.$manifest[$k]['file'])),
        );

        /*
         * Bawaan Vite memberi TIAP ikon Lucide berkasnya sendiri — ratusan byte
         * per berkas. Yang memakan waktu di sambungan berlatensi tinggi, di tepi
         * Cloudflare yang masih dingin sehabis deploy, dan di laptop lemah adalah
         * jumlah PERMINTAAN-nya, bukan jumlah bytenya. Setelah ikonnya disatukan
         * jadi chunk `vendor-icons`: 9 berkas.
         *
         * Ambangnya longgar (25) supaya penambahan komponen bersama yang wajar
         * tidak membuat test ini rewel — yang dijaga: jangan kembali ke puluhan.
         */
        $this->assertLessThanOrEqual(25, count($berkas), sprintf(
            'Halaman kembali terpecah jadi %d berkas JS. Periksa aturan `vendor-icons` di '
            .'vite.config.js — tanpa itu tiap ikon Lucide menjadi satu permintaan HTTP sendiri.',
            count($berkas),
        ));
    }
}
