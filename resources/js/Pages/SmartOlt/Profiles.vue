<script setup>
import ConfirmModal from '@/Components/ConfirmModal.vue';
import IconButton from '@/Components/IconButton.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Check, Database, Pencil, Plus, RefreshCw, ServerOff, Trash2, X } from '@lucide/vue';
import { computed, reactive } from 'vue';

const props = defineProps({
    olt: {
        type: Object,
        required: true,
    },
    profiles: {
        type: Object,
        required: true,
    },
    types: {
        type: Array,
        required: true,
    },
});

const page = usePage();
const flash = computed(() => page.props.flash ?? {});
const editing = reactive({});
const { confirmState, confirm, handleConfirm, handleCancel } = useConfirm();

const defaultParams = () => ({
    type: 4,
    maximum: 1024000,
    tag_mode: 'tag',
    pri: 0,
    gateway: '',
    primary_dns: '',
    secondary_dns: '',
});

const createForms = reactive(Object.fromEntries(
    props.types.map((type) => [
        type.key,
        useForm({
            profile_type: type.key,
            name: '',
            vlan: '',
            params: defaultParams(),
            notes: '',
            is_active: true,
            execute_cli: false,
        }),
    ]),
));

const rowsFor = (type) => props.profiles[type.key] ?? [];
const ownedByCurrentOlt = (profile) => profile.snmp_olt_id === props.olt.id;

const startEdit = (profile) => {
    editing[profile.id] = {
        profile_type: profile.profile_type,
        name: profile.name,
        vlan: profile.vlan ?? '',
        params: { ...defaultParams(), ...(profile.params ?? {}) },
        notes: profile.notes ?? '',
        is_active: profile.is_active,
        execute_cli: false,
        errors: {},
        processing: false,
    };
};

const cancelEdit = (profile) => {
    delete editing[profile.id];
};

const store = (type) => {
    const form = createForms[type.key];

    form.post(route('smartolt.profiles.store', props.olt.id), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('name', 'vlan', 'notes', 'execute_cli');
            form.params = defaultParams();
        },
    });
};

const update = (profile) => {
    const form = editing[profile.id];
    form.processing = true;
    form.errors = {};

    router.put(route('smartolt.profiles.update', { olt: props.olt.id, profile: profile.id }), form, {
        preserveScroll: true,
        onError: (errors) => {
            form.errors = errors;
        },
        onFinish: () => {
            form.processing = false;
        },
        onSuccess: () => {
            delete editing[profile.id];
        },
    });
};

const destroyProfile = async (profile, executeCli = false) => {
    const target = executeCli ? 'OLT dan cache lokal' : 'cache lokal';
    const ok = await confirm({
        title: executeCli ? 'Hapus Profile dari OLT' : 'Hapus Profile',
        message: `Hapus profile ${profile.name} dari ${target}?`,
        confirmLabel: 'Hapus',
    });

    if (!ok) {
        return;
    }

    router.delete(route('smartolt.profiles.destroy', { olt: props.olt.id, profile: profile.id }), {
        data: { execute_cli: executeCli },
        preserveScroll: true,
    });
};

const syncFromOlt = () => {
    router.post(route('smartolt.profiles.sync', props.olt.id), {}, {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="SmartOLT Profiles" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="text-xl font-semibold leading-tight text-gray-800">SmartOLT Profiles</h2>
                    <p class="mt-1 text-sm text-gray-500">{{ olt.name }} · {{ olt.ip }}</p>
                </div>
                <div class="flex gap-2">
                    <Link :href="route('smartolt.detail', olt.id)">
                        <SecondaryButton type="button">
                            <ArrowLeft class="mr-2 h-4 w-4" />
                            Detail OLT
                        </SecondaryButton>
                    </Link>
                    <PrimaryButton type="button" @click="syncFromOlt">
                        <RefreshCw class="mr-2 h-4 w-4" />
                        Sync Dari OLT
                    </PrimaryButton>
                </div>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                <div
                    v-if="flash.success"
                    class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800"
                >
                    {{ flash.success }}
                </div>
                <div
                    v-if="flash.error"
                    class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"
                >
                    {{ flash.error }}
                </div>

                <section
                    v-for="type in types"
                    :key="type.key"
                    class="overflow-hidden rounded-lg bg-white shadow-sm"
                >
                    <div class="border-b border-gray-200 px-6 py-4">
                        <div class="flex items-center gap-3">
                            <Database class="h-5 w-5 text-gray-500" />
                            <div>
                                <h3 class="text-base font-semibold text-gray-900">{{ type.label }}</h3>
                                <p class="text-sm text-gray-500">
                                    {{ type.uses_vlan ? 'Profile service dan VLAN ID dari OLT.' : 'Profile CLI yang dipakai pada script provisioning.' }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="border-b border-gray-100 bg-gray-50 px-6 py-4">
                        <form class="grid gap-4 md:grid-cols-12 md:items-end" @submit.prevent="store(type)">
                            <div class="md:col-span-3">
                                <InputLabel :for="`name-${type.key}`" value="Nama Profile" />
                                <TextInput :id="`name-${type.key}`" v-model="createForms[type.key].name" class="mt-1 block w-full" required />
                                <InputError class="mt-2" :message="createForms[type.key].errors.name" />
                            </div>
                            <div v-if="type.key === 'vlan'" class="md:col-span-2">
                                <InputLabel :for="`vlan-${type.key}`" value="VLAN" />
                                <TextInput :id="`vlan-${type.key}`" v-model="createForms[type.key].vlan" type="number" class="mt-1 block w-full" required />
                                <InputError class="mt-2" :message="createForms[type.key].errors.vlan" />
                            </div>
                            <div v-if="type.key === 'tcont'" class="md:col-span-2">
                                <InputLabel :for="`tcont-type-${type.key}`" value="Type" />
                                <TextInput :id="`tcont-type-${type.key}`" v-model="createForms[type.key].params.type" type="number" class="mt-1 block w-full" required />
                                <InputError class="mt-2" :message="createForms[type.key].errors['params.type']" />
                            </div>
                            <div v-if="type.key === 'tcont'" class="md:col-span-2">
                                <InputLabel :for="`maximum-${type.key}`" value="Maximum" />
                                <TextInput :id="`maximum-${type.key}`" v-model="createForms[type.key].params.maximum" type="number" class="mt-1 block w-full" required />
                                <InputError class="mt-2" :message="createForms[type.key].errors['params.maximum']" />
                            </div>
                            <div v-if="type.key === 'ip'" class="md:col-span-3">
                                <InputLabel :for="`gateway-${type.key}`" value="Gateway" />
                                <TextInput :id="`gateway-${type.key}`" v-model="createForms[type.key].params.gateway" class="mt-1 block w-full" required />
                                <InputError class="mt-2" :message="createForms[type.key].errors['params.gateway']" />
                            </div>
                            <div class="md:col-span-3">
                                <InputLabel :for="`notes-${type.key}`" value="Catatan" />
                                <TextInput :id="`notes-${type.key}`" v-model="createForms[type.key].notes" class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="createForms[type.key].errors.notes" />
                            </div>
                            <div class="md:col-span-2">
                                <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                    <input v-model="createForms[type.key].execute_cli" type="checkbox" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
                                    Eksekusi CLI
                                </label>
                            </div>
                            <div class="md:col-span-2">
                                <PrimaryButton class="w-full justify-center" :disabled="createForms[type.key].processing">
                                    <Plus class="mr-2 h-4 w-4" />
                                    Tambah
                                </PrimaryButton>
                            </div>
                        </form>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-white">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Nama</th>
                                    <th v-if="type.key === 'vlan'" class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">VLAN</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Params</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Status</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-600">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white">
                                <tr v-if="rowsFor(type).length === 0">
                                    <td :colspan="type.key === 'vlan' ? 5 : 4" class="px-6 py-8 text-center text-sm text-gray-500">
                                        Belum ada profile. Klik Sync Dari OLT untuk mengambil katalog real.
                                    </td>
                                </tr>
                                <tr v-for="profile in rowsFor(type)" :key="profile.id">
                                    <template v-if="editing[profile.id]">
                                        <td class="px-6 py-4">
                                            <TextInput v-model="editing[profile.id].name" class="block w-48" required />
                                            <InputError class="mt-2" :message="editing[profile.id].errors.name" />
                                        </td>
                                        <td v-if="type.key === 'vlan'" class="px-6 py-4">
                                            <TextInput v-model="editing[profile.id].vlan" type="number" class="block w-28" required />
                                            <InputError class="mt-2" :message="editing[profile.id].errors.vlan" />
                                        </td>
                                        <td class="px-6 py-4">
                                            <div v-if="type.key === 'tcont'" class="grid gap-2 md:grid-cols-2">
                                                <TextInput v-model="editing[profile.id].params.type" type="number" class="block w-full" />
                                                <TextInput v-model="editing[profile.id].params.maximum" type="number" class="block w-full" />
                                            </div>
                                            <TextInput v-else-if="type.key === 'ip'" v-model="editing[profile.id].params.gateway" class="block w-full" />
                                            <TextInput v-else v-model="editing[profile.id].notes" class="block w-72" />
                                        </td>
                                        <td class="px-6 py-4">
                                            <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                                <input v-model="editing[profile.id].is_active" type="checkbox" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
                                                Aktif
                                            </label>
                                            <label class="mt-2 inline-flex items-center gap-2 text-sm text-gray-700">
                                                <input v-model="editing[profile.id].execute_cli" type="checkbox" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
                                                Eksekusi CLI
                                            </label>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="flex justify-end gap-1.5">
                                                <IconButton variant="success" title="Simpan" :disabled="editing[profile.id].processing" @click="update(profile)">
                                                    <Check class="h-4 w-4" />
                                                </IconButton>
                                                <IconButton title="Batal" @click="cancelEdit(profile)">
                                                    <X class="h-4 w-4" />
                                                </IconButton>
                                            </div>
                                        </td>
                                    </template>
                                    <template v-else>
                                        <td class="px-6 py-4 font-medium text-gray-900">{{ profile.name }}</td>
                                        <td v-if="type.key === 'vlan'" class="px-6 py-4 text-sm text-gray-700">{{ profile.vlan }}</td>
                                        <td class="px-6 py-4 text-sm text-gray-600">
                                            <span v-if="type.key === 'tcont'">type {{ profile.params?.type ?? '-' }} · max {{ profile.params?.maximum ?? '-' }}</span>
                                            <span v-else-if="type.key === 'ip'">gateway {{ profile.params?.gateway ?? '-' }}</span>
                                            <span v-else>{{ profile.notes || '-' }}</span>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="space-y-1">
                                                <span
                                                    class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium"
                                                    :class="profile.is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-gray-100 text-gray-600'"
                                                >
                                                    {{ profile.is_active ? 'Aktif' : 'Nonaktif' }}
                                                </span>
                                                <div class="text-xs text-gray-500">{{ profile.source || 'manual' }}</div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="flex items-center justify-end gap-1.5">
                                                <IconButton v-if="ownedByCurrentOlt(profile)" title="Edit profile" @click="startEdit(profile)">
                                                    <Pencil class="h-4 w-4" />
                                                </IconButton>
                                                <IconButton v-if="ownedByCurrentOlt(profile)" variant="danger" title="Hapus dari cache lokal" @click="destroyProfile(profile, false)">
                                                    <Trash2 class="h-4 w-4" />
                                                </IconButton>
                                                <IconButton v-if="ownedByCurrentOlt(profile)" variant="danger" title="Hapus dari OLT + cache" @click="destroyProfile(profile, true)">
                                                    <ServerOff class="h-4 w-4" />
                                                </IconButton>
                                                <span v-if="!ownedByCurrentOlt(profile)" class="text-xs text-gray-500">Fallback global</span>
                                            </div>
                                        </td>
                                    </template>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>

        <ConfirmModal :state="confirmState" @confirm="handleConfirm" @cancel="handleCancel" />
    </AuthenticatedLayout>
</template>
