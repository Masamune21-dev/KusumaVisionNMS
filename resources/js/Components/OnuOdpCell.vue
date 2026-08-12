<script setup>
import { odpColor } from '@/lib/odpColors';
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

// Sel dropdown ODP untuk satu baris ONU — dipakai bersama oleh tabel Port ONU
// ZTE / C-Data / HiOSO (family-agnostic; assign via route onu-odp.assign).
const props = defineProps({
    onu: { type: Object, required: true },
    odps: { type: Array, default: () => [] },
    currentOdpId: { type: [Number, null], default: null },
    oltId: { type: [Number, String], required: true },
    slot: { type: [Number, String], required: true },
    port: { type: [Number, String], required: true },
    disabled: { type: Boolean, default: false },
});

const busy = ref(false);

// Titik warna ODP terpasang — samakan dgn warna pin ODP di peta (warnanya diatur dari
// peta/halaman ODP; di sini hanya ditampilkan).
const current = computed(() => props.odps.find((odp) => odp.id === props.currentOdpId) ?? null);

const onChange = (event) => {
    const value = event.target.value;
    busy.value = true;
    router.post(
        route('onu-odp.assign'),
        {
            snmp_olt_id: props.oltId,
            slot: props.slot,
            port: props.port,
            onu_id: props.onu.onu_id,
            serial_number: props.onu.serial_number ?? null,
            odp_id: value === '' ? null : Number(value),
        },
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => (busy.value = false),
        },
    );
};
</script>

<template>
    <span class="inline-flex items-center gap-1.5">
        <span
            v-if="current"
            class="inline-block h-2 w-2 shrink-0 rounded-sm ring-1 ring-white/30"
            :style="{ background: odpColor(current) }"
            :title="current.name"
        ></span>
        <select
            class="kv-input w-auto min-w-[8rem] max-w-full text-xs"
            :value="currentOdpId ?? ''"
            :disabled="disabled || busy || odps.length === 0"
            @change="onChange"
        >
            <option value="">{{ odps.length ? $t('portonus.odp_none') : $t('portonus.odp_empty') }}</option>
            <option v-for="odp in odps" :key="odp.id" :value="odp.id">{{ odp.name }}</option>
        </select>
    </span>
</template>
