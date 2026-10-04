<script setup>
import { router } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed } from 'vue';

// Navigasi cepat antar port PON di OLT yang sama — padanan pemilih port di
// header halaman ONU per port ZTE, dipakai bersama halaman Port ONU C-Data &
// HiOSO. Daftar port dibaca dari cache `last_test_result.ports` (tanpa SNMP).
const props = defineProps({
    oltId: { type: [Number, String], required: true },
    slot: { type: Number, required: true },
    port: { type: Number, required: true },
    // [{ slot, port }] dari `olt.last_test_result.ports`.
    ports: { type: Array, default: () => [] },
    // Peta "slot_port" → label port sisi-NMS (OltPortLabelService::forOlt).
    labels: { type: Object, default: () => ({}) },
    // "EPON" / "GPON" (capabilities.pon_label).
    ponLabel: { type: String, default: 'PON' },
    // cdata-olt.port-onus | hioso-olt.port-onus
    routeName: { type: String, required: true },
});

const navPorts = computed(() => {
    const list = props.ports.map((p) => ({ slot: Number(p.slot), port: Number(p.port) }));
    if (!list.some((p) => p.slot === props.slot && p.port === props.port)) {
        list.push({ slot: props.slot, port: props.port });
    }
    return list.sort((a, b) => a.slot - b.slot || a.port - b.port);
});

const currentIndex = computed(() =>
    navPorts.value.findIndex((p) => p.slot === props.slot && p.port === props.port),
);
const currentKey = computed(() => `${props.slot}_${props.port}`);
const prevPort = computed(() => (currentIndex.value > 0 ? navPorts.value[currentIndex.value - 1] : null));
const nextPort = computed(() =>
    currentIndex.value >= 0 && currentIndex.value < navPorts.value.length - 1
        ? navPorts.value[currentIndex.value + 1]
        : null,
);

const portName = (p) => `${props.ponLabel} 0/${p.slot}/${p.port}`;
const portLabel = (p) => props.labels?.[`${p.slot}_${p.port}`] ?? null;

const goToPort = (p) => {
    if (!p || (p.slot === props.slot && p.port === props.port)) return;
    router.get(route(props.routeName, [props.oltId, p.slot, p.port]));
};
const onSelect = (event) => {
    const [slot, port] = event.target.value.split('_').map(Number);
    goToPort({ slot, port });
};
</script>

<template>
    <div v-if="navPorts.length > 1" class="flex min-w-0 items-center gap-1 rounded-lg border border-white/10 bg-slate-900/80 p-1">
        <button
            type="button"
            class="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-md text-slate-300 transition-colors enabled:hover:bg-white/5 enabled:hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cyan-400/60 disabled:cursor-not-allowed disabled:opacity-30 sm:h-9 sm:w-9"
            :disabled="!prevPort"
            :title="prevPort ? portName(prevPort) : $t('portonus.port_first')"
            :aria-label="prevPort ? portName(prevPort) : $t('portonus.port_first')"
            @click="goToPort(prevPort)"
        >
            <ChevronLeft class="h-4 w-4" />
        </button>
        <select
            :value="currentKey"
            class="min-h-9 min-w-0 flex-1 rounded-md border-0 bg-transparent py-1.5 pl-2 pr-7 text-sm font-medium text-slate-100 focus:ring-1 focus:ring-cyan-500 sm:max-w-[16rem] sm:flex-none"
            :title="$t('portonus.switch_port')"
            :aria-label="$t('portonus.switch_port')"
            @change="onSelect"
        >
            <option v-for="p in navPorts" :key="`${p.slot}_${p.port}`" :value="`${p.slot}_${p.port}`" class="bg-slate-900">
                {{ portName(p) }}<template v-if="portLabel(p)"> — {{ portLabel(p) }}</template>
            </option>
        </select>
        <button
            type="button"
            class="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-md text-slate-300 transition-colors enabled:hover:bg-white/5 enabled:hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cyan-400/60 disabled:cursor-not-allowed disabled:opacity-30 sm:h-9 sm:w-9"
            :disabled="!nextPort"
            :title="nextPort ? portName(nextPort) : $t('portonus.port_last')"
            :aria-label="nextPort ? portName(nextPort) : $t('portonus.port_last')"
            @click="goToPort(nextPort)"
        >
            <ChevronRight class="h-4 w-4" />
        </button>
    </div>
</template>
