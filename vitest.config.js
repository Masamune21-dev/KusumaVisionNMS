import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';
import path from 'node:path';

// Config aparte de vite.config.js a propósito: el plugin `laravel-vite-plugin` espera el
// contexto del dev-server de Laravel (manifest, hot file) y no aporta nada en tests, así que
// aquí solo se usa el plugin de Vue. El alias `@` normalmente lo inyecta ese plugin, por eso
// hay que declararlo a mano.
export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: {
            '@': path.resolve(__dirname, 'resources/js'),
        },
    },
    test: {
        environment: 'jsdom',
        include: ['tests/js/**/*.spec.js'],
        restoreMocks: true,
    },
});
