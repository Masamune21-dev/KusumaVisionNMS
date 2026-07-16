<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { router, usePage } from '@inertiajs/vue3';
import { formatClock } from '@/lib/datetime';
import { Cpu, HardDrive, MemoryStick } from '@lucide/vue';

const { t } = useI18n({ useScope: 'global' });

const page = usePage();
const now = ref(new Date());
let clockTimer = null;
let healthTimer = null;

onMounted(() => {
    clockTimer = window.setInterval(() => (now.value = new Date()), 1000);
    // Segarkan metrik kesehatan tanpa reload penuh — hanya prop systemInfo,
    // pertahankan scroll & state (jam tetap jalan). Server cache 5s.
    healthTimer = window.setInterval(() => {
        router.reload({ only: ['systemInfo'], preserveScroll: true, preserveState: true });
    }, 20000);
});

onUnmounted(() => {
    if (clockTimer) window.clearInterval(clockTimer);
    if (healthTimer) window.clearInterval(healthTimer);
});

const sysInfo = computed(() => page.props.systemInfo ?? {});
const health = computed(() => sysInfo.value.health ?? {});

const formattedTime = computed(() => formatClock(now.value));

const items = computed(() => [
    { label: t('shell.sys_version'), value: sysInfo.value.version ?? '1.0.0' },
    { label: t('shell.sys_time'), value: formattedTime.value, mono: true },
    { label: t('shell.sys_uptime'), value: sysInfo.value.uptime ?? '—' },
    {
        label: t('shell.sys_online'),
        value: sysInfo.value.users_online ?? 1,
        suffix: t('shell.sys_user_suffix'),
        indicator: 'emerald',
    },
]);

// Hijau < 70% · amber 70–89% · merah ≥ 90%.
const levelClasses = (p) => {
    if (p >= 90) return { text: 'text-red-400', bar: 'bg-red-500' };
    if (p >= 70) return { text: 'text-amber-400', bar: 'bg-amber-500' };
    return { text: 'text-emerald-400', bar: 'bg-emerald-500' };
};

const healthItems = computed(() => {
    const out = [];
    const { cpu, memory, disk } = health.value;

    if (cpu && cpu.percent !== null && cpu.percent !== undefined) {
        out.push({ label: 'CPU', icon: Cpu, percent: cpu.percent, detail: `${cpu.load} load · ${cpu.cores} core` });
    }
    if (memory) {
        out.push({ label: 'RAM', icon: MemoryStick, percent: memory.percent, detail: `${memory.used} / ${memory.total}` });
    }
    if (disk) {
        out.push({ label: 'Disk', icon: HardDrive, percent: disk.percent, detail: `${disk.used} / ${disk.total}` });
    }

    return out.map((m) => ({ ...m, ...levelClasses(m.percent) }));
});
</script>

<template>
    <div class="border-t border-white/10 px-3 py-3">
        <div class="rounded-xl border border-white/10 bg-slate-900/50 p-3">
            <div class="mb-2 flex items-center gap-2 text-[10px] font-semibold uppercase tracking-wider text-slate-500">
                <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-400" />
                {{ $t('shell.sys_title') }}
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

            <!-- Kesehatan server (CPU/RAM/disk) -->
            <div v-if="healthItems.length" class="mt-3 space-y-2 border-t border-white/10 pt-3">
                <div v-for="m in healthItems" :key="m.label" :title="m.detail">
                    <div class="flex items-center justify-between gap-2 text-[11px]">
                        <span class="flex items-center gap-1.5 text-slate-500">
                            <component :is="m.icon" class="h-3 w-3" />
                            {{ m.label }}
                        </span>
                        <span class="font-medium tabular-nums" :class="m.text">{{ m.percent }}%</span>
                    </div>
                    <div class="mt-1 h-1 w-full overflow-hidden rounded-full bg-white/10">
                        <div class="h-full rounded-full transition-all duration-500" :class="m.bar" :style="{ width: `${Math.min(100, m.percent)}%` }" />
                    </div>
                    <p class="mt-0.5 truncate text-[10px] text-slate-600">{{ m.detail }}</p>
                </div>
            </div>
        </div>
    </div>
</template>
