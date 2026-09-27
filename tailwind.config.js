import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import { RAMPS, STOPS, SINGLES, SURFACES, CANVASES } from './tailwind.tokens.mjs';

/* =====================================================================
   DUA TEMA — lihat blok token di resources/css/app.css
   =====================================================================
   Seluruh ramp warna di bawah ini TIDAK berisi nilai, melainkan penunjuk
   ke CSS custom property. Nilainya ditetapkan sekali per tema di app.css
   pada `[data-theme="dark"]` dan `[data-theme="light"]`.

   Akibatnya `bg-slate-900/70`, `text-cyan-400`, `border-white/10` yang
   sudah tersebar di ratusan berkas ikut berganti tema sendiri tanpa satu
   pun kelas perlu disentuh.

   Bentuk `rgb(var(--kv-x) / <alpha-value>)` WAJIB dipertahankan: token
   `<alpha-value>` itulah yang membuat modifier opasitas (`/70`, `/[0.03]`)
   tetap bekerja. Variabelnya menyimpan channel RGB tanpa pembungkus,
   mis. `--kv-slate-900: 15 23 42`.

   ⛔ Menambah ramp di sini TANPA mendeklarasikan variabelnya di app.css
   membuat `rgb(var(--kv-...) / 1)` menjadi nilai invalid — propertinya
   diabaikan diam-diam dan elemennya jadi transparan, tanpa error apa pun.
   Test `tests/Feature/ThemeTokenTest.php` menjaga hal itu tidak terjadi.
   ===================================================================== */

const token = (name) => `rgb(var(--kv-${name}) / <alpha-value>)`;

const ramp = (name) =>
    Object.fromEntries(STOPS.map((stop) => [stop, token(`${name}-${stop}`)]));

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    /* Varian `dark:` sengaja TIDAK dipakai — tema dikerjakan lewat token di
       atas. Pengikatan ini pagar: tanpanya Tailwind v3 memakai default
       'media', yang berarti setelan OS menjadi sumber kebenaran tema kedua
       yang bertabrakan dengan data-theme. Konsekuensinya, atribut
       data-theme WAJIB selalu terisi eksplisit. */
    darkMode: ['selector', '[data-theme="dark"]'],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Manrope', ...defaultTheme.fontFamily.sans],
            },

            colors: {
                ...Object.fromEntries(RAMPS.map((name) => [name, ramp(name)])),
                ...Object.fromEntries(SINGLES.map((name) => [name, token(name)])),

                // surface-1 -> surface.1 ; surface-hover -> surface.hover
                surface: Object.fromEntries(
                    SURFACES.map((name) => [name.replace('surface-', ''), token(name)]),
                ),

                // canvas -> DEFAULT ; canvas-2 -> 2
                canvas: Object.fromEntries(
                    CANVASES.map((name) => [
                        name === 'canvas' ? 'DEFAULT' : name.replace('canvas-', ''),
                        token(name),
                    ]),
                ),
            },
        },
    },

    plugins: [forms],
};
