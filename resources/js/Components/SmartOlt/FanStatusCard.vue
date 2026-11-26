<script setup>
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Fan as FanIcon, RefreshCw, Thermometer } from '@lucide/vue';
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    olt: {
        type: Object,
        required: true,
    },
    // Ringkasan + daftar fan tersimpan; null bila belum pernah di-refresh.
    fan: {
        type: Object,
        default: null,
    },
});

const refreshing = ref(false);

const refresh = () => {
    refreshing.value = true;
    router.post(route('smartolt.fan.refresh', props.olt.id), {}, {
        preserveScroll: true,
        onFinish: () => { refreshing.value = false; },
    });
};

const fans = computed(() => props.fan?.fans ?? []);
const unit = computed(() => props.fan?.unit ?? props.olt?.capabilities?.fan_speed_unit ?? 'rpm');
const onlineCount = computed(() => fans.value.filter((f) => f.status === 'normal').length);
const thresholds = computed(() => (props.fan?.thresholds ?? []).join(' / '));

const modeClass = computed(() => ({
    auto: 'bg-cyan-500/15 text-cyan-300 ring-cyan-500/30',
    fixed: 'bg-amber-500/15 text-amber-300 ring-amber-500/30',
    unknown: 'bg-slate-500/15 text-slate-300 ring-slate-500/30',
}[props.fan?.mode] ?? 'bg-slate-500/15 text-slate-300 ring-slate-500/30'));

const modeLabel = computed(() => ({
    auto: 'detail.fan_mode_auto',
    fixed: 'detail.fan_mode_fixed',
    unknown: 'detail.fan_mode_unknown',
}[props.fan?.mode] ?? 'detail.fan_mode_unknown'));

const speedOf = (fan) => {
    if (unit.value === 'percent') {
        return fan.speed_percent != null ? `${fan.speed_percent}%` : '-';
    }

    return fan.speed_rpm != null ? `${fan.speed_rpm} RPM` : '-';
};

const lastRefresh = computed(() => {
    if (!props.fan?.refreshed_at) return null;

    return new Date(props.fan.refreshed_at).toLocaleString();
});
</script>

<template>
    <div class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
        <div class="flex flex-col gap-3 border-b border-white/10 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-sky-500/15 ring-1 ring-cyan-500/30">
                    <FanIcon class="h-5 w-5 text-cyan-400" />
                </div>
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="text-base font-semibold text-white">{{ $t('detail.fan_title') }}</h3>
                        <span
                            v-if="fan"
                            class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1"
                            :class="modeClass"
                        >
                            {{ $t(modeLabel) }}
                        </span>
                    </div>
                    <p v-if="fan" class="mt-0.5 text-xs text-slate-500">
                        <span v-if="fan.temp_c != null">{{ $t('detail.fan_temp') }}: {{ fan.temp_c }} °C · </span>
                        <span v-else-if="fan.min_level != null">{{ $t('detail.fan_min_level') }}: {{ fan.min_level }} · </span>
                        <span>{{ onlineCount }}/{{ fans.length }} {{ $t('detail.fan_online') }}</span>
                        <span v-if="thresholds"> · {{ $t('detail.fan_thresholds') }}: {{ thresholds }} °C</span>
                        <span v-if="lastRefresh"> · {{ $t('detail.fan_last_refresh') }}: {{ lastRefresh }}</span>
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <span v-if="fan" class="rounded-full bg-white/5 px-2 py-0.5 text-xs font-medium uppercase tracking-wide text-slate-400 ring-1 ring-white/10">
                    {{ fan.source === 'cli' ? $t('detail.fan_source_cli') : $t('detail.fan_source_snmp') }}
                </span>
                <SecondaryButton type="button" :disabled="refreshing" @click="refresh">
                    <RefreshCw class="mr-2 h-4 w-4" :class="{ 'animate-spin': refreshing }" />
                    {{ $t('detail.fan_refresh') }}
                </SecondaryButton>
            </div>
        </div>

        <div v-if="!fan || fans.length === 0" class="px-5 py-10 text-center text-sm text-slate-500">
            <Thermometer class="mx-auto mb-2 h-6 w-6 text-slate-600" />
            {{ $t('detail.fan_empty') }}
        </div>

        <div v-else class="overflow-x-auto">
            <table class="min-w-full divide-y divide-white/5 text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wide text-slate-500">
                        <th class="px-4 py-3 font-medium sm:px-6">#</th>
                        <th class="px-4 py-3 font-medium">{{ $t('detail.fan_col_status') }}</th>
                        <th class="px-4 py-3 font-medium">{{ $t('detail.fan_col_level') }}</th>
                        <th class="px-4 py-3 text-right font-medium sm:px-6">{{ $t('detail.fan_col_speed') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    <tr v-for="item in fans" :key="item.index">
                        <td class="px-4 py-3 font-medium text-slate-300 sm:px-6">{{ item.index }}</td>
                        <td class="px-4 py-3">
                            <span
                                class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1"
                                :class="item.status === 'normal'
                                    ? 'bg-emerald-500/15 text-emerald-300 ring-emerald-500/30'
                                    : 'bg-rose-500/15 text-rose-300 ring-rose-500/30'"
                            >
                                {{ item.status === 'normal' ? $t('detail.fan_status_normal') : $t('detail.fan_status_abnormal') }}
                            </span>
                        </td>
                        <td class="px-4 py-3 tabular-nums text-slate-300">{{ item.level ?? '-' }}</td>
                        <td class="px-4 py-3 text-right tabular-nums font-medium text-slate-100 sm:px-6">{{ speedOf(item) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
