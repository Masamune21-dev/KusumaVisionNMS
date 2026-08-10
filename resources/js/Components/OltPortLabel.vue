<script setup>
import { router } from '@inertiajs/vue3';
import { Check, Pencil, X } from '@lucide/vue';
import { nextTick, ref } from 'vue';

// Label port PON sisi-NMS — dipakai bersama tabel port & header Port ONU
// C-Data / HiOSO / HsAirPo. Tidak pernah ditulis ke OLT (beda dari deskripsi
// port ZTE yang memang disimpan di perangkat).
const props = defineProps({
    oltId: { type: [Number, String], required: true },
    slot: { type: [Number, String], required: true },
    port: { type: [Number, String], required: true },
    label: { type: [String, null], default: null },
    editable: { type: Boolean, default: false },
    // Header halaman Port ONU: teks lebih besar & selalu tampil sebaris.
    variant: { type: String, default: 'cell' },
});

const editing = ref(false);
const busy = ref(false);
const draft = ref('');
const input = ref(null);

const start = async () => {
    draft.value = props.label ?? '';
    editing.value = true;
    await nextTick();
    input.value?.focus();
    input.value?.select();
};

const save = () => {
    busy.value = true;
    router.post(
        route('olt.port-label.store', props.oltId),
        { slot: props.slot, port: props.port, label: draft.value },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => { editing.value = false; },
            onFinish: () => { busy.value = false; },
        },
    );
};
</script>

<template>
    <div v-if="editing" class="flex items-center gap-1.5">
        <input
            ref="input"
            v-model="draft"
            type="text"
            maxlength="64"
            class="kv-input w-full min-w-[9rem] max-w-[18rem] text-xs"
            :placeholder="$t('portlabel.placeholder')"
            :disabled="busy"
            @keyup.enter="save"
            @keyup.esc="editing = false"
        />
        <button
            type="button"
            class="rounded p-1 text-emerald-400 transition hover:bg-emerald-500/10 hover:text-emerald-300 disabled:opacity-50"
            :title="$t('common.save')"
            :disabled="busy"
            @click="save"
        >
            <Check class="h-4 w-4" />
        </button>
        <button
            type="button"
            class="rounded p-1 text-slate-400 transition hover:bg-white/5 hover:text-white disabled:opacity-50"
            :title="$t('common.cancel')"
            :disabled="busy"
            @click="editing = false"
        >
            <X class="h-4 w-4" />
        </button>
    </div>

    <div v-else class="flex items-center gap-1.5">
        <span
            v-if="label"
            class="break-words"
            :class="variant === 'header' ? 'text-sm text-slate-300' : 'text-sm text-white'"
        >{{ label }}</span>
        <span v-else class="text-xs text-slate-600">{{ editable ? $t('portlabel.empty') : '-' }}</span>
        <button
            v-if="editable"
            type="button"
            class="rounded p-1 text-slate-500 transition hover:bg-white/5 hover:text-cyan-300"
            :title="label ? $t('portlabel.edit_title') : $t('portlabel.add_title')"
            @click="start"
        >
            <Pencil class="h-3.5 w-3.5" />
        </button>
    </div>
</template>
