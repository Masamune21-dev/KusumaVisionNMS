<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Database, Pencil, Plus, Trash2, X } from '@lucide/vue';
import { computed, reactive } from 'vue';

const props = defineProps({
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

const createForms = reactive(Object.fromEntries(
    props.types.map((type) => [
        type.key,
        useForm({
            profile_type: type.key,
            name: '',
            vlan: '',
            notes: '',
            is_active: true,
        }),
    ]),
));

const rowsFor = (type) => props.profiles[type.key] ?? [];

const startEdit = (profile) => {
    editing[profile.id] = {
        profile_type: profile.profile_type,
        name: profile.name,
        vlan: profile.vlan ?? '',
        notes: profile.notes ?? '',
        is_active: profile.is_active,
        errors: {},
        processing: false,
    };
};

const cancelEdit = (profile) => {
    delete editing[profile.id];
};

const store = (type) => {
    const form = createForms[type.key];

    form.post(route('smartolt.profiles.store'), {
        preserveScroll: true,
        onSuccess: () => form.reset('name', 'vlan', 'notes'),
    });
};

const update = (profile) => {
    const form = editing[profile.id];
    form.processing = true;
    form.errors = {};

    router.put(route('smartolt.profiles.update', profile.id), form, {
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

const destroyProfile = (profile) => {
    if (!window.confirm(`Hapus profile ${profile.name}?`)) {
        return;
    }

    router.delete(route('smartolt.profiles.destroy', profile.id), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="SmartOLT Profiles" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h2 class="text-xl font-semibold leading-tight text-gray-800">SmartOLT Profiles</h2>
                <p class="mt-1 text-sm text-gray-500">Master profile untuk provisioning ONU.</p>
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
                                    {{ type.uses_vlan ? 'Profile service dan VLAN ID.' : 'Profile nama yang dipakai pada script provisioning.' }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="border-b border-gray-100 bg-gray-50 px-6 py-4">
                        <form class="grid gap-4 md:grid-cols-12 md:items-end" @submit.prevent="store(type)">
                            <div :class="type.uses_vlan ? 'md:col-span-3' : 'md:col-span-4'">
                                <InputLabel :for="`name-${type.key}`" value="Nama Profile" />
                                <TextInput :id="`name-${type.key}`" v-model="createForms[type.key].name" class="mt-1 block w-full" required />
                                <InputError class="mt-2" :message="createForms[type.key].errors.name" />
                            </div>
                            <div v-if="type.uses_vlan" class="md:col-span-2">
                                <InputLabel :for="`vlan-${type.key}`" value="VLAN" />
                                <TextInput :id="`vlan-${type.key}`" v-model="createForms[type.key].vlan" type="number" class="mt-1 block w-full" required />
                                <InputError class="mt-2" :message="createForms[type.key].errors.vlan" />
                            </div>
                            <div :class="type.uses_vlan ? 'md:col-span-5' : 'md:col-span-6'">
                                <InputLabel :for="`notes-${type.key}`" value="Catatan" />
                                <TextInput :id="`notes-${type.key}`" v-model="createForms[type.key].notes" class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="createForms[type.key].errors.notes" />
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
                                    <th v-if="type.uses_vlan" class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">VLAN</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Catatan</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Status</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-600">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white">
                                <tr v-if="rowsFor(type).length === 0">
                                    <td :colspan="type.uses_vlan ? 5 : 4" class="px-6 py-8 text-center text-sm text-gray-500">
                                        Belum ada profile.
                                    </td>
                                </tr>
                                <tr v-for="profile in rowsFor(type)" :key="profile.id">
                                    <template v-if="editing[profile.id]">
                                        <td class="px-6 py-4">
                                            <TextInput v-model="editing[profile.id].name" class="block w-48" required />
                                            <InputError class="mt-2" :message="editing[profile.id].errors.name" />
                                        </td>
                                        <td v-if="type.uses_vlan" class="px-6 py-4">
                                            <TextInput v-model="editing[profile.id].vlan" type="number" class="block w-28" required />
                                            <InputError class="mt-2" :message="editing[profile.id].errors.vlan" />
                                        </td>
                                        <td class="px-6 py-4">
                                            <TextInput v-model="editing[profile.id].notes" class="block w-72" />
                                            <InputError class="mt-2" :message="editing[profile.id].errors.notes" />
                                        </td>
                                        <td class="px-6 py-4">
                                            <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                                <input v-model="editing[profile.id].is_active" type="checkbox" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
                                                Aktif
                                            </label>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="flex justify-end gap-2">
                                                <PrimaryButton type="button" :disabled="editing[profile.id].processing" @click="update(profile)">
                                                    Simpan
                                                </PrimaryButton>
                                                <SecondaryButton type="button" @click="cancelEdit(profile)">
                                                    <X class="mr-2 h-4 w-4" />
                                                    Batal
                                                </SecondaryButton>
                                            </div>
                                        </td>
                                    </template>
                                    <template v-else>
                                        <td class="px-6 py-4 font-medium text-gray-900">{{ profile.name }}</td>
                                        <td v-if="type.uses_vlan" class="px-6 py-4 text-sm text-gray-700">{{ profile.vlan }}</td>
                                        <td class="px-6 py-4 text-sm text-gray-600">{{ profile.notes || '-' }}</td>
                                        <td class="px-6 py-4">
                                            <span
                                                class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium"
                                                :class="profile.is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-gray-100 text-gray-600'"
                                            >
                                                {{ profile.is_active ? 'Aktif' : 'Nonaktif' }}
                                            </span>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="flex justify-end gap-2">
                                                <SecondaryButton type="button" @click="startEdit(profile)">
                                                    <Pencil class="mr-2 h-4 w-4" />
                                                    Edit
                                                </SecondaryButton>
                                                <button
                                                    type="button"
                                                    class="inline-flex items-center rounded-md border border-red-200 bg-white px-4 py-2 text-xs font-semibold uppercase tracking-widest text-red-700 shadow-sm transition duration-150 ease-in-out hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2"
                                                    @click="destroyProfile(profile)"
                                                >
                                                    <Trash2 class="mr-2 h-4 w-4" />
                                                    Hapus
                                                </button>
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
    </AuthenticatedLayout>
</template>
