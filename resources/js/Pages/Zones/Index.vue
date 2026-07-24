<script setup>
import ConfirmModal from '@/Components/ConfirmModal.vue';
import IconButton from '@/Components/IconButton.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { Head, router, useForm } from '@inertiajs/vue3';
import { MapPin, Pencil, Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n({ useScope: 'global' });

const props = defineProps({
    zones: {
        type: Array,
        required: true,
    },
});

const { confirmState, confirm, handleConfirm, handleCancel } = useConfirm();

// --- crear / renombrar (mismo modal, mismo form) ---
const showModal = ref(false);
const editingZone = ref(null);
const form = useForm({ name: '' });

const openCreate = () => {
    editingZone.value = null;
    form.reset();
    form.clearErrors();
    showModal.value = true;
};

const openEdit = (zone) => {
    editingZone.value = zone;
    form.name = zone.name;
    form.clearErrors();
    showModal.value = true;
};

const closeModal = () => {
    showModal.value = false;
    form.reset();
    form.clearErrors();
};

const submit = () => {
    if (editingZone.value) {
        form.put(route('zones.update', editingZone.value.id), {
            preserveScroll: true,
            onSuccess: () => closeModal(),
        });
    } else {
        form.post(route('zones.store'), {
            preserveScroll: true,
            onSuccess: () => closeModal(),
        });
    }
};

// --- eliminar (con opción de reasignar si tiene ONUs) ---
const showDeleteModal = ref(false);
const deletingZone = ref(null);
const deleteChoice = ref('none'); // 'none' | 'reassign'
const reassignTo = ref('');

const otherZoneOptions = computed(() =>
    props.zones.filter((z) => z.id !== deletingZone.value?.id),
);

const openDelete = async (zone) => {
    if (zone.onu_count === 0) {
        const ok = await confirm({
            title: t('zones.delete_title'),
            message: t('zones.delete_msg_empty', { name: zone.name }),
            confirmLabel: t('common.delete'),
            variant: 'danger',
        });
        if (ok) {
            router.delete(route('zones.destroy', zone.id), { preserveScroll: true });
        }
        return;
    }

    deletingZone.value = zone;
    deleteChoice.value = 'none';
    reassignTo.value = '';
    showDeleteModal.value = true;
};

const closeDeleteModal = () => {
    showDeleteModal.value = false;
    deletingZone.value = null;
};

const confirmDelete = () => {
    router.delete(route('zones.destroy', deletingZone.value.id), {
        data: deleteChoice.value === 'reassign' && reassignTo.value ? { reassign_to: reassignTo.value } : {},
        preserveScroll: true,
        onSuccess: () => closeDeleteModal(),
    });
};
</script>

<template>
    <Head :title="$t('zones.title')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <h2 class="text-lg font-semibold leading-tight sm:text-xl text-white">
                    {{ $t('zones.title') }}
                </h2>
                <PrimaryButton class="w-full sm:w-auto" @click="openCreate">
                    <Plus class="mr-2 h-4 w-4" />
                    {{ $t('zones.add') }}
                </PrimaryButton>
            </div>
        </template>

        <div class="min-h-[60vh] pt-5 pb-16 sm:pt-8">
            <div class="w-full px-4 sm:px-6 lg:px-8">

                <div class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                    <div class="flex items-center gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                        <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-sky-500/15 ring-1 ring-cyan-500/30">
                            <MapPin class="h-5 w-5 text-cyan-400" />
                        </div>
                        <div>
                            <h3 class="text-base font-semibold text-white">{{ $t('zones.list_title') }}</h3>
                            <p class="mt-0.5 text-xs text-slate-500">{{ $t('zones.list_sub') }}</p>
                        </div>
                    </div>

                    <div v-if="zones.length === 0" class="px-6 py-12 text-center">
                        <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-slate-800/60 ring-1 ring-slate-500/30">
                            <MapPin class="h-7 w-7 text-slate-400" />
                        </div>
                        <h3 class="text-sm font-semibold text-white">{{ $t('zones.empty_title') }}</h3>
                        <p class="mt-1 text-sm text-slate-500">{{ $t('zones.empty_sub') }}</p>
                        <div class="mt-5">
                            <PrimaryButton @click="openCreate">
                                <Plus class="mr-2 h-4 w-4" />
                                {{ $t('zones.add') }}
                            </PrimaryButton>
                        </div>
                    </div>

                    <template v-else>
                        <div class="kv-mobile-list">
                            <article v-for="zone in zones" :key="zone.id" class="kv-mobile-card">
                                <div class="kv-mobile-card-header">
                                    <div class="min-w-0">
                                        <h4 class="kv-mobile-card-title">{{ zone.name }}</h4>
                                        <p class="kv-mobile-card-subtitle">{{ $t('zones.col_onu_count') }}: {{ zone.onu_count }}</p>
                                    </div>
                                    <div class="flex flex-shrink-0 gap-2">
                                        <IconButton :title="$t('zones.edit')" @click="openEdit(zone)">
                                            <Pencil class="h-4 w-4" />
                                        </IconButton>
                                        <IconButton variant="danger" :title="$t('zones.delete')" @click="openDelete(zone)">
                                            <Trash2 class="h-4 w-4" />
                                        </IconButton>
                                    </div>
                                </div>
                            </article>
                        </div>

                        <div class="kv-table-desktop">
                        <table class="min-w-[520px] w-full">
                            <thead>
                                <tr class="border-b border-white/10 bg-slate-950/40">
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                                        {{ $t('zones.col_name') }}
                                    </th>
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                                        {{ $t('zones.col_onu_count') }}
                                    </th>
                                    <th class="px-4 py-3.5 text-center text-xs font-semibold uppercase tracking-wider text-slate-500">
                                        {{ $t('common.actions') }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/5">
                                <tr v-for="zone in zones" :key="zone.id" class="transition-colors duration-150 hover:bg-white/[0.03]">
                                    <td class="px-4 py-4 font-medium text-white">{{ zone.name }}</td>
                                    <td class="px-4 py-4 text-sm text-slate-300 tabular-nums">{{ zone.onu_count }}</td>
                                    <td class="px-4 py-4">
                                        <div class="flex justify-center gap-1.5">
                                            <IconButton :title="$t('zones.edit')" @click="openEdit(zone)">
                                                <Pencil class="h-4 w-4" />
                                            </IconButton>
                                            <IconButton variant="danger" :title="$t('zones.delete')" @click="openDelete(zone)">
                                                <Trash2 class="h-4 w-4" />
                                            </IconButton>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <!-- Modal Crear / Renombrar -->
        <Modal :show="showModal" max-width="md" @close="closeModal">
            <form @submit.prevent="submit" class="p-6">
                <h3 class="text-base font-semibold text-white">
                    {{ editingZone ? $t('zones.edit') : $t('zones.add') }}
                </h3>
                <p class="mt-1 text-sm text-slate-500">
                    {{ editingZone ? $t('zones.modal_edit_sub') : $t('zones.modal_create_sub') }}
                </p>

                <div class="mt-5">
                    <InputLabel for="zone_name" :value="$t('zones.col_name')" />
                    <TextInput
                        id="zone_name"
                        v-model="form.name"
                        type="text"
                        class="mt-1 block w-full uppercase"
                        :placeholder="$t('zones.name_placeholder')"
                        autofocus
                    />
                    <InputError :message="form.errors.name" class="mt-1" />
                </div>

                <div class="mt-6 grid gap-2 sm:flex sm:justify-end">
                    <SecondaryButton type="button" @click="closeModal">
                        {{ $t('common.cancel') }}
                    </SecondaryButton>
                    <PrimaryButton type="submit" :disabled="form.processing">
                        {{ editingZone ? $t('zones.save_changes') : $t('zones.create') }}
                    </PrimaryButton>
                </div>
            </form>
        </Modal>

        <!-- Modal Eliminar (con reasignación si tiene ONUs) -->
        <Modal :show="showDeleteModal" max-width="md" @close="closeDeleteModal">
            <div v-if="deletingZone" class="p-6">
                <h3 class="text-base font-semibold text-white">{{ $t('zones.delete_title') }}</h3>
                <p class="mt-1 text-sm text-slate-500">
                    {{ $t('zones.delete_msg_has_onus', { name: deletingZone.name, count: deletingZone.onu_count }) }}
                </p>

                <div class="mt-5 space-y-2">
                    <InputLabel :value="$t('zones.reassign_label')" />
                    <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-white/10 bg-slate-900/60 px-3 py-2.5">
                        <input type="radio" value="none" v-model="deleteChoice" class="h-4 w-4 border-white/20 bg-slate-800 text-cyan-500 focus:ring-cyan-500" />
                        <span class="text-sm text-slate-100">{{ $t('zones.reassign_none_option') }}</span>
                    </label>
                    <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-white/10 bg-slate-900/60 px-3 py-2.5">
                        <input type="radio" value="reassign" v-model="deleteChoice" class="h-4 w-4 border-white/20 bg-slate-800 text-cyan-500 focus:ring-cyan-500" />
                        <span class="text-sm text-slate-100">{{ $t('zones.reassign_to_option') }}</span>
                    </label>

                    <div v-if="deleteChoice === 'reassign'" class="pt-1">
                        <InputLabel for="reassign_to" :value="$t('zones.reassign_to_label')" />
                        <select
                            id="reassign_to"
                            v-model="reassignTo"
                            class="kv-input mt-1 block w-full min-h-11"
                        >
                            <option value="" disabled>{{ $t('zones.reassign_to_placeholder') }}</option>
                            <option v-for="z in otherZoneOptions" :key="z.id" :value="z.id">{{ z.name }}</option>
                        </select>
                    </div>
                </div>

                <div class="mt-6 grid gap-2 sm:flex sm:justify-end">
                    <SecondaryButton type="button" @click="closeDeleteModal">
                        {{ $t('common.cancel') }}
                    </SecondaryButton>
                    <PrimaryButton
                        type="button"
                        class="!bg-red-600 hover:!bg-red-500"
                        :disabled="deleteChoice === 'reassign' && !reassignTo"
                        @click="confirmDelete"
                    >
                        {{ $t('zones.delete') }}
                    </PrimaryButton>
                </div>
            </div>
        </Modal>

        <ConfirmModal :state="confirmState" @confirm="handleConfirm" @cancel="handleCancel" />
    </AuthenticatedLayout>
</template>
