<script setup>
import { Link } from '@inertiajs/vue3';
import { CheckCircle2, Clock, Cog, XCircle } from '@lucide/vue';

defineProps({
    items: { type: Array, default: () => [] },
});

const iconMap = {
    pending: Clock,
    processing: Cog,
    success: CheckCircle2,
    failed: XCircle,
};

const accentMap = {
    pending: 'kv-circle-cyan',
    processing: 'kv-circle-purple',
    success: 'kv-circle-emerald',
    failed: 'kv-circle-red',
};

const countColorMap = {
    pending: 'text-cyan-300',
    processing: 'text-purple-300',
    success: 'text-emerald-300',
    failed: 'text-red-300',
};
</script>

<template>
    <div class="kv-glass-panel flex h-full flex-col">
        <div class="flex items-center justify-between border-b border-white/5 px-5 py-4">
            <div class="flex items-center gap-3">
                <span class="kv-circle-purple">
                    <Cog class="h-5 w-5" />
                </span>
                <h3 class="text-base font-semibold text-white">{{ $t('dashboard.provisioning_jobs') }}</h3>
            </div>
            <Link :href="route('smartolt.index')" class="text-xs font-medium text-cyan-400 hover:text-cyan-300">
                {{ $t('dashboard.view_all') }} &rsaquo;
            </Link>
        </div>

        <ul class="flex-1 space-y-1 px-5 py-4">
            <li
                v-for="item in items"
                :key="item.key"
                class="flex items-center gap-3 rounded-xl px-2 py-2.5 transition-colors hover:bg-white/[0.03]"
            >
                <span :class="accentMap[item.key]" class="!h-9 !w-9">
                    <component :is="iconMap[item.key]" class="h-4 w-4" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold text-white">{{ $t('dashboard.provisioning.' + item.key + '_label') }}</p>
                    <p class="truncate text-xs text-slate-500">{{ $t('dashboard.provisioning.' + item.key + '_sublabel') }}</p>
                </div>
                <span class="text-lg font-bold tabular-nums" :class="countColorMap[item.key]">{{ item.count }}</span>
            </li>
        </ul>
    </div>
</template>
