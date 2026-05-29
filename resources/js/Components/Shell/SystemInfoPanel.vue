<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { formatClock } from '@/lib/datetime';

const page = usePage();
const now = ref(new Date());
let timer = null;

onMounted(() => {
    timer = window.setInterval(() => (now.value = new Date()), 1000);
});

onUnmounted(() => {
    if (timer) window.clearInterval(timer);
});

const sysInfo = computed(() => page.props.systemInfo ?? {});

const formattedTime = computed(() => formatClock(now.value));

const items = computed(() => [
    { label: 'Versi', value: sysInfo.value.version ?? '1.0.0' },
    { label: 'Waktu', value: formattedTime.value, mono: true },
    { label: 'Uptime', value: sysInfo.value.uptime ?? '—' },
    {
        label: 'Online',
        value: sysInfo.value.users_online ?? 1,
        suffix: 'user',
        indicator: 'emerald',
    },
]);
</script>

<template>
    <div class="border-t border-white/10 px-3 py-3">
        <div class="rounded-xl border border-white/10 bg-slate-900/50 p-3">
            <div class="mb-2 flex items-center gap-2 text-[10px] font-semibold uppercase tracking-wider text-slate-500">
                <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-400" />
                Sistem
            </div>
            <dl class="space-y-1 text-[11px]">
                <div v-for="item in items" :key="item.label" class="flex items-center justify-between gap-2">
                    <dt class="flex-shrink-0 text-slate-500">{{ item.label }}</dt>
                    <dd class="flex items-center gap-1 truncate text-right font-medium text-slate-200" :class="{ 'font-mono': item.mono }">
                        <span v-if="item.indicator === 'emerald'" class="h-1.5 w-1.5 rounded-full bg-emerald-400" />
                        <span class="truncate">{{ item.value }}<span v-if="item.suffix" class="ml-0.5 text-slate-500">{{ item.suffix }}</span></span>
                    </dd>
                </div>
            </dl>
        </div>
    </div>
</template>
