<script setup>
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

// Sel dropdown Zone untuk satu baris ONU — dipakai bersama oleh tabel Port ONU
// ZTE / C-Data / HiOSO (family-agnostic; assign via route onu-zone.assign).
// Mirip OnuOdpCell.vue, tapi katalog zona bersifat global (bukan per-OLT).
const props = defineProps({
    onu: { type: Object, required: true },
    zones: { type: Array, default: () => [] },
    currentZoneId: { type: [Number, null], default: null },
    oltId: { type: [Number, String], required: true },
    slot: { type: [Number, String], required: true },
    port: { type: [Number, String], required: true },
    disabled: { type: Boolean, default: false },
});

const busy = ref(false);

const onChange = (event) => {
    const value = event.target.value;
    busy.value = true;
    router.post(
        route('onu-zone.assign'),
        {
            snmp_olt_id: props.oltId,
            slot: props.slot,
            port: props.port,
            onu_id: props.onu.onu_id,
            serial_number: props.onu.serial_number ?? null,
            zone_id: value === '' ? null : Number(value),
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
    <select
        class="kv-input w-auto min-w-[8rem] max-w-full text-xs"
        :value="currentZoneId ?? ''"
        :disabled="disabled || busy"
        @change="onChange"
    >
        <option value="">{{ $t('portonus.zone_none') }}</option>
        <option v-for="zone in zones" :key="zone.id" :value="zone.id">{{ zone.name }}</option>
    </select>
</template>
