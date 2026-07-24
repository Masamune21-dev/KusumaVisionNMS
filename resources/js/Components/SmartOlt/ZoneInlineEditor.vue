<script setup>
import { router } from '@inertiajs/vue3';
import { Check, Pencil, X } from '@lucide/vue';
import { ref } from 'vue';

// Edit inline zona di halaman Detail ONU: lihat nama zona → pensil → pilih dari
// dropdown → simpan tanpa keluar halaman (Inertia partial visit, sama pola dgn
// OnuOdpCell.vue tapi dgn toggle mode lihat/edit, bukan select selalu-tampil).
const props = defineProps({
    zones: { type: Array, default: () => [] },
    currentZoneId: { type: [Number, null], default: null },
    currentZoneName: { type: [String, null], default: null },
    oltId: { type: [Number, String], required: true },
    slot: { type: [Number, String], required: true },
    port: { type: [Number, String], required: true },
    onuId: { type: [Number, String], required: true },
    serialNumber: { type: [String, null], default: null },
});

const editing = ref(false);
const busy = ref(false);
const selected = ref(props.currentZoneId ?? '');

const startEdit = () => {
    selected.value = props.currentZoneId ?? '';
    editing.value = true;
};

const cancel = () => {
    editing.value = false;
};

const save = () => {
    busy.value = true;
    router.post(
        route('onu-zone.assign'),
        {
            snmp_olt_id: props.oltId,
            slot: props.slot,
            port: props.port,
            onu_id: props.onuId,
            serial_number: props.serialNumber,
            zone_id: selected.value === '' ? null : Number(selected.value),
        },
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                busy.value = false;
                editing.value = false;
            },
        },
    );
};
</script>

<template>
    <div v-if="!editing" class="flex items-center gap-2">
        <span class="font-medium text-slate-200">{{ currentZoneName || '—' }}</span>
        <button
            type="button"
            class="inline-flex h-6 w-6 items-center justify-center rounded-md text-slate-500 transition hover:bg-white/5 hover:text-cyan-400"
            :title="$t('common.edit')"
            @click="startEdit"
        >
            <Pencil class="h-3.5 w-3.5" />
        </button>
    </div>
    <div v-else class="flex items-center gap-2">
        <select
            v-model="selected"
            class="kv-input h-8 min-w-[10rem] text-xs"
            :disabled="busy"
        >
            <option value="">{{ $t('onumonitor.zone_none') }}</option>
            <option v-for="z in zones" :key="z.id" :value="z.id">{{ z.name }}</option>
        </select>
        <button
            type="button"
            class="inline-flex h-6 w-6 items-center justify-center rounded-md text-emerald-400 transition hover:bg-emerald-500/15 disabled:opacity-50"
            :disabled="busy"
            :title="$t('common.save')"
            @click="save"
        >
            <Check class="h-3.5 w-3.5" />
        </button>
        <button
            type="button"
            class="inline-flex h-6 w-6 items-center justify-center rounded-md text-slate-500 transition hover:bg-white/5 hover:text-slate-300 disabled:opacity-50"
            :disabled="busy"
            :title="$t('common.cancel')"
            @click="cancel"
        >
            <X class="h-3.5 w-3.5" />
        </button>
    </div>
</template>
