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

        <div class="bg-gradient-to-br from-slate-900 via-slate-800 to-indigo-950 py-8 pb-16 min-h-[60vh]">
            <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                <!-- Flash messages -->
                <div v-if="flash.success" class="mb-5 flex items-center gap-3 rounded-xl border border-emerald-500/25 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-300 backdrop-blur-sm">
                    <span class="h-2 w-2 flex-shrink-0 rounded-full bg-emerald-400 shadow-[0_0_6px_rgba(52,211,153,0.7)]"></span>{{ flash.success }}
                </div>
                <div v-if="flash.error" class="mb-5 flex items-center gap-3 rounded-xl border border-red-500/25 bg-red-500/10 px-4 py-3 text-sm text-red-300 backdrop-blur-sm">
                    <span class="h-2 w-2 flex-shrink-0 rounded-full bg-red-400"></span>{{ flash.error }}
                </div>

                <section
                    v-for="type in types"
                    :key="type.key"
                    class="overflow-hidden rounded-2xl border border-white/10 bg-white/[0.06] shadow-2xl backdrop-blur-xl"
                >
                    <!-- Card header -->
                    <div class="flex items-center gap-3 border-b border-white/10 px-6 py-5">
                        <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-sky-500/20 ring-1 ring-sky-500/30">
                            <Database class="h-5 w-5 text-sky-400" />
                        </div>
                        <div>
                            <h3 class="text-base font-semibold text-white">{{ type.label }}</h3>
                            <p class="text-sm text-slate-400">
                                {{ type.uses_vlan ? 'Profile service dan VLAN ID dari OLT.' : 'Profile CLI yang dipakai pada script provisioning.' }}
                            </p>
                        </div>
                    </div>

                    <!-- Add form row -->
                    <div class="border-b border-white/[0.06] bg-white/[0.03] px-6 py-4">
                        <form class="grid gap-4 md:grid-cols-12 md:items-end" @submit.prevent="store(type)">
                            <div class="md:col-span-3">
                                <InputLabel :for="`name-${type.key}`" value="Nama Profile" class="text-slate-300" />
                                <TextInput :id="`name-${type.key}`" v-model="createForms[type.key].name" class="mt-1 block w-full" required />
                                <InputError class="mt-2" :message="createForms[type.key].errors.name" />
                            </div>
                            <div v-if="type.key === 'vlan'" class="md:col-span-2">
                                <InputLabel :for="`vlan-${type.key}`" value="VLAN" class="text-slate-300" />
                                <TextInput :id="`vlan-${type.key}`" v-model="createForms[type.key].vlan" type="number" class="mt-1 block w-full" required />
                                <InputError class="mt-2" :message="createForms[type.key].errors.vlan" />
                            </div>
                            <div v-if="type.key === 'tcont'" class="md:col-span-2">
                                <InputLabel :for="`tcont-type-${type.key}`" value="Type" class="text-slate-300" />
                                <TextInput :id="`tcont-type-${type.key}`" v-model="createForms[type.key].params.type" type="number" class="mt-1 block w-full" required />
                                <InputError class="mt-2" :message="createForms[type.key].errors['params.type']" />
                            </div>
                            <div v-if="type.key === 'tcont'" class="md:col-span-2">
                                <InputLabel :for="`maximum-${type.key}`" value="Maximum" class="text-slate-300" />
                                <TextInput :id="`maximum-${type.key}`" v-model="createForms[type.key].params.maximum" type="number" class="mt-1 block w-full" required />
                                <InputError class="mt-2" :message="createForms[type.key].errors['params.maximum']" />
                            </div>
                            <div v-if="type.key === 'ip'" class="md:col-span-3">
                                <InputLabel :for="`gateway-${type.key}`" value="Gateway" class="text-slate-300" />
                                <TextInput :id="`gateway-${type.key}`" v-model="createForms[type.key].params.gateway" class="mt-1 block w-full" required />
                                <InputError class="mt-2" :message="createForms[type.key].errors['params.gateway']" />
                            </div>
                            <div class="md:col-span-3">
                                <InputLabel :for="`notes-${type.key}`" value="Catatan" class="text-slate-300" />
                                <TextInput :id="`notes-${type.key}`" v-model="createForms[type.key].notes" class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="createForms[type.key].errors.notes" />
                            </div>
                            <div class="md:col-span-2">
                                <label class="inline-flex items-center gap-2 text-sm text-slate-300">
                                    <input v-model="createForms[type.key].execute_cli" type="checkbox" class="rounded border-slate-600 bg-slate-700 text-indigo-500 shadow-sm focus:ring-indigo-500" />
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

                    <!-- Table -->
                    <div class="overflow-x-auto">
                        <table class="min-w-full">
                            <thead>
                                <tr class="border-b border-white/[0.06] bg-white/[0.03]">
                                    <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">Nama</th>
                                    <th v-if="type.key === 'vlan'" class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">VLAN</th>
                                    <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">Params</th>
                                    <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">Status</th>
                                    <th class="px-6 py-3.5 text-center text-xs font-semibold uppercase tracking-wider text-slate-400">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/[0.05]">
                                <tr v-if="rowsFor(type).length === 0">
                                    <td :colspan="type.key === 'vlan' ? 5 : 4" class="px-6 py-8 text-center text-sm text-slate-500">
                                        Belum ada profile. Klik Sync Dari OLT untuk mengambil katalog real.
                                    </td>
                                </tr>
                                <tr v-for="profile in rowsFor(type)" :key="profile.id" class="transition-colors duration-150 hover:bg-white/[0.04]">
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
                                            <label class="inline-flex items-center gap-2 text-sm text-slate-300">
                                                <input v-model="editing[profile.id].is_active" type="checkbox" class="rounded border-slate-600 bg-slate-700 text-indigo-500 shadow-sm focus:ring-indigo-500" />
                                                Aktif
                                            </label>
                                            <label class="mt-2 inline-flex items-center gap-2 text-sm text-slate-300">
                                                <input v-model="editing[profile.id].execute_cli" type="checkbox" class="rounded border-slate-600 bg-slate-700 text-indigo-500 shadow-sm focus:ring-indigo-500" />
                                                Eksekusi CLI
                                            </label>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="flex justify-center gap-1.5">
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
                                        <td class="px-6 py-4 text-sm font-medium text-white">{{ profile.name }}</td>
                                        <td v-if="type.key === 'vlan'" class="px-6 py-4 text-sm text-slate-300">{{ profile.vlan }}</td>
                                        <td class="px-6 py-4 text-sm text-slate-300">
                                            <span v-if="type.key === 'tcont'">type {{ profile.params?.type ?? '-' }} · max {{ profile.params?.maximum ?? '-' }}</span>
                                            <span v-else-if="type.key === 'ip'">gateway {{ profile.params?.gateway ?? '-' }}</span>
                                            <span v-else>{{ profile.notes || '-' }}</span>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="space-y-1">
                                                <span
                                                    class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1"
                                                    :class="profile.is_active
                                                        ? 'bg-emerald-500/15 text-emerald-300 ring-emerald-500/25'
                                                        : 'bg-slate-500/15 text-slate-400 ring-slate-500/25'"
                                                >
                                                    {{ profile.is_active ? 'Aktif' : 'Nonaktif' }}
                                                </span>
                                                <div class="text-xs text-slate-500">{{ profile.source || 'manual' }}</div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="flex items-center justify-center gap-1.5">
                                                <IconButton v-if="ownedByCurrentOlt(profile)" title="Edit profile" @click="startEdit(profile)">
                                                    <Pencil class="h-4 w-4" />
                                                </IconButton>
                                                <IconButton v-if="ownedByCurrentOlt(profile)" variant="danger" title="Hapus dari cache lokal" @click="destroyProfile(profile, false)">
                                                    <Trash2 class="h-4 w-4" />
                                                </IconButton>
                                                <IconButton v-if="ownedByCurrentOlt(profile)" variant="danger" title="Hapus dari OLT + cache" @click="destroyProfile(profile, true)">
                                                    <ServerOff class="h-4 w-4" />
                                                </IconButton>
                                                <span v-if="!ownedByCurrentOlt(profile)" class="text-xs text-slate-500">Fallback global</span>
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
