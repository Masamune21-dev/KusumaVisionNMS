<script setup>
import { computed } from 'vue';
import { CheckCircle2, CircleDashed, Clock, Unplug } from '@lucide/vue';
import { formatDateTime } from '@/lib/datetime';

/**
 * Penanda "ONU ini sudah terhubung ke ACS atau belum".
 *
 * `acs` berasal dari prop halaman (tabel lokal `genieacs_device_map`) — tidak
 * ada panggilan ke GenieACS saat merender. Empat keadaan:
 *   null              → belum berpasangan dengan device mana pun
 *   onuOnline = false → berpasangan, tapi ONU offline/LOS di OLT
 *   online = true     → berpasangan dan inform terakhir masih baru
 *   online = false    → berpasangan tapi sudah lama tak inform
 *
 * `onuOnline` (status OLT) didahulukan: inform terakhir ONU yang baru putus
 * masih di bawah ambang 2 jam, jadi tanpa itu lencana tetap "Terhubung"
 * di samping status ONU yang offline/LOS.
 */
const props = defineProps({
    acs: { type: Object, default: null },
    onuOnline: { type: Boolean, default: null },
});

const state = computed(() => {
    if (!props.acs) return 'none';
    if (props.onuOnline === false) return 'onu_down';

    return props.acs.online ? 'online' : 'stale';
});

const label = computed(() => ({
    online: 'portonus.acs_yes',
    stale: 'portonus.acs_stale',
    onu_down: 'portonus.acs_onu_down',
    none: 'portonus.acs_no',
})[state.value]);

const lastInform = computed(() =>
    props.acs?.last_inform_at ? formatDateTime(props.acs.last_inform_at) : null,
);

const title = computed(() => {
    if (!props.acs) return null;

    const bits = [props.acs.device_id];
    if (props.acs.product_class) bits.push(props.acs.product_class);
    if (lastInform.value) bits.push(lastInform.value);

    return bits.join(' · ');
});
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium ring-1"
        :class="{
            'bg-emerald-500/15 text-emerald-300 ring-emerald-500/30': state === 'online',
            'bg-amber-500/15 text-amber-300 ring-amber-500/30': state === 'stale',
            'bg-slate-500/10 text-slate-400 ring-slate-500/20': state === 'none' || state === 'onu_down',
        }"
        :title="title"
    >
        <CheckCircle2 v-if="state === 'online'" class="h-3.5 w-3.5" />
        <Clock v-else-if="state === 'stale'" class="h-3.5 w-3.5" />
        <Unplug v-else-if="state === 'onu_down'" class="h-3.5 w-3.5" />
        <CircleDashed v-else class="h-3.5 w-3.5" />
        {{ $t(label) }}
    </span>
</template>
