<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Penjaga bentuk bundel produksi.
 *
 * Ada satu kekeliruan yang lolos berbulan-bulan tanpa terlihat, karena tidak ada
 * satu pun test yang memeriksa HASIL build: aturan `manualChunks` yang memaksa
 * apexcharts ke chunk bernama sendiri membuat Rollup mengangkatnya menjadi impor
 * STATIS milik app.js. Akibatnya ±1,1 MB grafik ikut diunduh, didekompres, dan
 * dikompilasi di SETIAP halaman — termasuk daftar ONU dan Peta yang tidak punya
 * satu grafik pun.
 *
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

    public function test_apexcharts_tidak_pernah_jadi_impor_statis_entry_mana_pun(): void
    {
        $manifest = $this->manifest();

        // Diperiksa untuk SEMUA entry, bukan hanya app.js: NMS juga memakai
        // Pages/Welcome.vue sebagai entry terpisah (halaman depan publik), dan
        // halaman itu paling tidak boleh menyeret 1,1 MB grafik.
        $entries = array_keys(array_filter(
            $manifest,
            fn (array $c) => ! empty($c['isEntry']),
        ));

        $this->assertNotEmpty($entries, 'Manifest tidak punya entry sama sekali.');

        foreach ($entries as $entry) {
            $terlihat = [];
            $statis = $this->statisDari($manifest, $entry, $terlihat);
            $pelanggar = array_values(array_filter($statis, fn (string $k) => str_contains($k, 'apexcharts')));

            $this->assertSame([], $pelanggar, implode("\n", [
                "apexcharts kembali menjadi impor STATIS dari entry `{$entry}`.",
                'Artinya ±1,1 MB grafik diunduh & dikompilasi di setiap halaman, juga yang tanpa grafik.',
                'Penyebab yang sudah pernah terjadi: aturan `manualChunks` di vite.config.js yang',
                'menyebut apexcharts — Rollup mengangkat chunk bernama menjadi dependensi statis entry.',
                'Grafik harus tetap masuk lewat `defineAsyncComponent`.',
            ]));
        }
    }

    public function test_apexcharts_tetap_dimuat_secara_dinamis_oleh_dashboard(): void
    {
        $manifest = $this->manifest();

        $this->assertArrayHasKey('resources/js/Pages/Dashboard.vue', $manifest);

        $dinamis = $manifest['resources/js/Pages/Dashboard.vue']['dynamicImports'] ?? [];
        $cocok = array_values(array_filter($dinamis, fn (string $k) => str_contains($k, 'apexcharts')));

        $this->assertNotEmpty(
            $cocok,
            'Dashboard tidak lagi memuat apexcharts secara dinamis — grafiknya mungkin hilang, '
            .'atau malah kembali ditarik statis. Periksa defineAsyncComponent di Components/Dashboard/StatCard.vue.',
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
