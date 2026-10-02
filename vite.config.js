import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    build: {
        // Keep previous hashed chunks so active sessions survive a deployment.
        emptyOutDir: false,
        rollupOptions: {
            output: {
                /*
                 * Chart.js SENGAJA TIDAK disebut di sini.
                 *
                 * Menyebut pustaka grafik di aturan ini JUSTRU membatalkan
                 * pemuatan malasnya: Rollup mengangkat chunk bernama menjadi
                 * impor STATIS milik app.js, sehingga grafik ikut diunduh,
                 * didekompres, dan dikompilasi di SETIAP halaman — termasuk
                 * daftar ONU dan Peta yang tidak punya satu grafik pun. Itu
                 * pernah terjadi dengan ApexCharts (1,1 MB, Sep 2026). Grafik
                 * masuk lewat import() di Components/Charts/ChartCanvas.vue.
                 *
                 * Ikon justru kebalikannya: masalahnya BUKAN besar, melainkan
                 * BANYAK. Bawaan Vite memberi tiap ikon berkasnya sendiri
                 * (ratusan byte), sehingga satu halaman menarik puluhan berkas
                 * mungil — dan yang memakan waktu adalah jumlah PERJALANAN
                 * BOLAK-BALIK-nya, bukan jumlah bytenya. Aman digabung karena
                 * ikon diimpor statis oleh hampir semua halaman dan oleh
                 * AuthenticatedLayout, jadi tidak ada kemalasan yang bisa
                 * dibatalkan.
                 */
                manualChunks(id) {
                    if (id.includes('node_modules/@lucide/') || id.includes('node_modules/lucide-vue-next')) {
                        return 'vendor-icons';
                    }

                    return undefined;
                },
            },
        },
    },
    plugins: [
        laravel({
            input: 'resources/js/app.js',
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
});
