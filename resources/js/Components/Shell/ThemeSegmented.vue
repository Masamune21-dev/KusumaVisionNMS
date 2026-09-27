<script setup>
/**
 * Pemilih tema tiga pilihan (Gelap / Terang / Sistem) untuk drawer navigasi HP.
 *
 * Di desktop pemilihnya ada di UserMenu (header). Header itu tidak tampil di HP,
 * jadi tanpa komponen ini pengguna HP tidak punya jalan mengganti tema.
 */
import { Monitor, Moon, Sun } from '@lucide/vue';
import { useTheme } from '@/lib/theme';

const { preference, setTheme } = useTheme();
const CHOICES = [
    { value: 'dark', label: 'common.theme_dark', icon: Moon },
    { value: 'light', label: 'common.theme_light', icon: Sun },
    { value: 'system', label: 'common.theme_system_short', icon: Monitor },
];
</script>

<template>
    <div role="radiogroup" :aria-label="$t('common.theme')" class="grid grid-cols-3 gap-1 rounded-lg border border-white/10 bg-slate-900/60 p-1">
        <button
            v-for="choice in CHOICES"
            :key="choice.value"
            type="button"
            role="radio"
            :aria-checked="preference === choice.value"
            :title="$t(choice.label)"
            class="flex min-h-11 flex-col items-center justify-center gap-0.5 rounded-md px-1 py-1 text-xs font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cyan-400/60"
            :class="preference === choice.value ? 'bg-cyan-500/15 text-cyan-300' : 'text-slate-400 hover:bg-white/5 hover:text-white'"
            @click="setTheme(choice.value)"
        >
            <component :is="choice.icon" class="h-3.5 w-3.5 flex-shrink-0" />
            <span class="truncate">{{ $t(choice.label) }}</span>
        </button>
    </div>
</template>
