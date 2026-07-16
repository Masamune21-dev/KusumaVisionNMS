<script setup>
import { CircleCheck, CircleMinus, FileText, Power, RotateCcw, UploadCloud } from '@lucide/vue';

const emit = defineEmits(['select']);

const actions = [
    { key: 'reboot',  label: 'Reboot',   icon: RotateCcw,   accent: 'kv-circle-cyan' },
    { key: 'reset',   label: 'Reset',    icon: Power,       accent: 'kv-circle-purple' },
    { key: 'upgrade', label: 'Upgrade',  icon: UploadCloud, accent: 'kv-circle-emerald' },
    { key: 'enable',  label: 'Enable',   icon: CircleCheck, accent: 'kv-circle-emerald' },
    { key: 'disable', label: 'Disable',  icon: CircleMinus, accent: 'kv-circle-amber' },
    { key: 'log',     label: 'Log',      icon: FileText,    accent: 'kv-circle-sky' },
];
</script>

<template>
    <div class="kv-glass-panel flex h-full flex-col">
        <div class="kv-glass-header">
            <span class="kv-circle-cyan">
                <Power class="h-5 w-5" />
            </span>
            <h3 class="text-base font-semibold text-white">{{ $t('dashboard.remote_actions') }}</h3>
        </div>
        <div class="grid flex-1 grid-cols-3 gap-2 p-4">
            <button
                v-for="action in actions"
                :key="action.key"
                type="button"
                class="group flex flex-col items-center justify-center gap-2 rounded-xl border border-white/5 bg-slate-900/40 px-2 py-3 text-center transition-all hover:border-cyan-500/30 hover:bg-slate-900/70 hover:shadow-lg hover:shadow-cyan-500/10"
                :title="`${action.label} ONU`"
                @click="emit('select', action.key)"
            >
                <span :class="action.accent" class="!h-10 !w-10 transition-transform group-hover:scale-105">
                    <component :is="action.icon" class="h-4 w-4" />
                </span>
                <span class="text-xs font-semibold text-white">{{ action.label }}</span>
            </button>
        </div>
    </div>
</template>
