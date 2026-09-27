<script setup>
/**
 * Tombol tema ringkas untuk halaman tanpa menu pengguna (Welcome, halaman tamu).
 *
 * Berputar Gelap → Terang → Ikuti Sistem. Tamu tidak punya baris pengguna,
 * jadi pilihannya hanya disimpan di cookie `kv_theme` (lihat lib/theme.js).
 * Pengguna yang login memilih lewat UserMenu.
 */
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Monitor, Moon, Sun } from '@lucide/vue';
import { useTheme } from '@/lib/theme';

const { t } = useI18n({ useScope: 'global' });
const { preference, cycleTheme } = useTheme();

const ICONS = { dark: Moon, light: Sun, system: Monitor };
const LABELS = { dark: 'common.theme_dark', light: 'common.theme_light', system: 'common.theme_system' };

const icon = computed(() => ICONS[preference.value] ?? Moon);
const label = computed(() => `${t('common.theme')}: ${t(LABELS[preference.value] ?? LABELS.dark)}`);
</script>

<template>
    <button
        type="button"
        class="flex h-10 w-10 items-center justify-center rounded-xl border border-white/10 bg-slate-900/60 text-slate-300 transition-colors hover:border-cyan-500/30 hover:bg-slate-900/80 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cyan-400/60 lg:h-11 lg:w-11"
        :title="label"
        :aria-label="label"
        @click="cycleTheme"
    >
        <component :is="icon" class="h-4 w-4" />
    </button>
</template>
