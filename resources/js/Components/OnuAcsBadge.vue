<script setup>
import { computed } from 'vue';
import { CheckCircle2, CircleDashed, Clock } from '@lucide/vue';
import { formatDateTime } from '@/lib/datetime';

/**
 * Penanda "ONU ini sudah terhubung ke ACS atau belum".
 *
 * `acs` berasal dari prop halaman (tabel lokal `genieacs_device_map`) — tidak
 * ada panggilan ke GenieACS saat merender. Tiga keadaan:
 *   null            → belum berpasangan dengan device mana pun
 *   online = true   → berpasangan dan inform terakhir masih baru
 *   online = false  → berpasangan tapi sudah lama tak inform
 */
const props = defineProps({
    acs: { type: Object, default: null },
});

const state = computed(() => {
    if (!props.acs) return 'none';

    return props.acs.online ? 'online' : 'stale';
});

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
            'bg-slate-500/10 text-slate-400 ring-slate-500/20': state === 'none',
        }"
        :title="title"
    >
        <CheckCircle2 v-if="state === 'online'" class="h-3.5 w-3.5" />
        <Clock v-else-if="state === 'stale'" class="h-3.5 w-3.5" />
        <CircleDashed v-else class="h-3.5 w-3.5" />
        {{ state === 'online' ? $t('portonus.acs_yes') : state === 'stale' ? $t('portonus.acs_stale') : $t('portonus.acs_no') }}
    </span>
</template>
