<script setup>
import { Link } from '@inertiajs/vue3';
import { BellRing } from '@lucide/vue';
import { formatDateTime } from '@/lib/datetime';

defineProps({
    alarms: { type: Array, default: () => [] },
});

const severityClass = (s) => ({
    critical: 'kv-pill-critical',
    major: 'kv-pill-major',
    minor: 'kv-pill-minor',
    warning: 'kv-pill-warning',
}[s] ?? 'kv-pill-muted');

const formatTime = (iso) => formatDateTime(iso);

const alarmType = (type) => {
    if (!type) return 'Alarm';
    return type.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
};
</script>

<template>
    <div class="kv-glass-panel flex h-full flex-col">
        <div class="flex items-center justify-between border-b border-white/5 px-5 py-4">
            <div class="flex items-center gap-3">
                <span class="kv-circle-red">
                    <BellRing class="h-5 w-5" />
                </span>
                <h3 class="text-base font-semibold text-white">Alarm Terbaru</h3>
            </div>
            <Link :href="route('alarms.index')" class="text-xs font-medium text-cyan-400 hover:text-cyan-300">
                Lihat Semua &rsaquo;
            </Link>
        </div>

        <div v-if="alarms.length > 0" class="kv-mobile-list">
            <article v-for="alarm in alarms" :key="alarm.id" class="kv-mobile-card">
                <div class="kv-mobile-card-header">
                    <div class="min-w-0">
                        <h4 class="kv-mobile-card-title">{{ alarmType(alarm.type) }}</h4>
                        <p class="kv-mobile-card-subtitle">{{ alarm.olt_name ?? 'Perangkat tidak diketahui' }}</p>
                    </div>
                    <span :class="severityClass(alarm.severity)">{{ alarm.severity }}</span>
                </div>
                <div class="kv-mobile-fields">
                    <div class="kv-mobile-field">
                        <span class="kv-mobile-label">Waktu</span>
                        <span class="kv-mobile-value">{{ formatTime(alarm.last_seen_at) }}</span>
                    </div>
                    <div class="kv-mobile-field">
                        <span class="kv-mobile-label">Lokasi</span>
                        <span class="kv-mobile-value">{{ alarm.location ?? '—' }}</span>
                    </div>
                    <div class="kv-mobile-field">
                        <span class="kv-mobile-label">Status</span>
                        <span class="kv-pill-danger">{{ alarm.status_label }}</span>
                    </div>
                </div>
            </article>
        </div>

        <div v-if="alarms.length > 0" class="kv-table-desktop">
            <table class="w-full min-w-[640px] text-sm">
                <thead>
                    <tr class="border-b border-white/5 text-left text-[11px] uppercase tracking-wider text-slate-500">
                        <th class="px-5 py-2.5 font-medium">Waktu</th>
                        <th class="px-2 py-2.5 font-medium">Severitas</th>
                        <th class="px-2 py-2.5 font-medium">Alarm</th>
                        <th class="px-2 py-2.5 font-medium">Perangkat</th>
                        <th class="px-2 py-2.5 font-medium">Lokasi</th>
                        <th class="px-5 py-2.5 font-medium">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    <tr v-for="alarm in alarms" :key="alarm.id" class="transition-colors hover:bg-white/[0.03]">
                        <td class="whitespace-nowrap px-5 py-3 text-xs text-slate-400">{{ formatTime(alarm.last_seen_at) }}</td>
                        <td class="px-2 py-3"><span :class="severityClass(alarm.severity)">{{ alarm.severity }}</span></td>
                        <td class="px-2 py-3 text-slate-200">{{ alarmType(alarm.type) }}</td>
                        <td class="px-2 py-3 text-slate-300">{{ alarm.olt_name ?? '—' }}</td>
                        <td class="px-2 py-3 text-slate-400">{{ alarm.location ?? '—' }}</td>
                        <td class="px-5 py-3"><span class="kv-pill-danger">{{ alarm.status_label }}</span></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div v-else class="px-5 py-10 text-center text-sm text-slate-500">
            Tidak ada alarm aktif.
        </div>
    </div>
</template>
