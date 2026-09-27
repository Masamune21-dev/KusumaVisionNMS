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
                 * Apexcharts SENGAJA TIDAK disebut di sini: memaksanya ke chunk bernama
                 * membuat Rollup mengangkatnya menjadi impor STATIS app.js, sehingga
                 * ±1 MB grafik ikut diunduh di SETIAP halaman. Komponen grafik memuatnya
                 * malas lewat defineAsyncComponent.
                 *
                 * Ikon justru kebalikannya: masalahnya BUKAN besar, melainkan BANYAK.
                 * Bawaan Vite memberi tiap ikon berkasnya sendiri (ratusan byte), sehingga
                 * satu halaman menarik puluhan berkas mungil — yang memakan waktu adalah
                 * jumlah perjalanan bolak-baliknya. Aman digabung karena ikon diimpor
                 * statis oleh hampir semua halaman dan oleh AuthenticatedLayout.
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
