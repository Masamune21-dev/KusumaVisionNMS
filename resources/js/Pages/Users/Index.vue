<script setup>
import ConfirmModal from '@/Components/ConfirmModal.vue';
import DangerButton from '@/Components/DangerButton.vue';
import IconButton from '@/Components/IconButton.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2, Users } from '@lucide/vue';
import { computed, ref } from 'vue';

defineProps({
    users: {
        type: Array,
        required: true,
    },
});

const page = usePage();
const flash = computed(() => page.props.flash ?? {});
const { confirmState, confirm, handleConfirm, handleCancel } = useConfirm();

const showModal = ref(false);
const editingUser = ref(null);

const form = useForm({
    name: '',
    email: '',
    password: '',
});

const openCreate = () => {
    editingUser.value = null;
    form.reset();
    form.clearErrors();
    showModal.value = true;
};

const openEdit = (user) => {
    editingUser.value = user;
    form.name = user.name;
    form.email = user.email;
    form.password = '';
    form.clearErrors();
    showModal.value = true;
};

const closeModal = () => {
    showModal.value = false;
    form.reset();
    form.clearErrors();
};

const submit = () => {
    if (editingUser.value) {
        form.put(route('users.update', editingUser.value.id), {
            preserveScroll: true,
            onSuccess: () => closeModal(),
        });
    } else {
        form.post(route('users.store'), {
            preserveScroll: true,
            onSuccess: () => closeModal(),
        });
    }
};

const deleteUser = async (user) => {
    const ok = await confirm({
        title: 'Hapus User',
        message: `Hapus user "${user.name}" (${user.email})? Tindakan ini permanen.`,
        confirmLabel: 'Hapus',
        variant: 'danger',
    });

    if (!ok) return;

    router.delete(route('users.destroy', user.id), { preserveScroll: true });
};

const formatDate = (value) => {
    if (!value) return '-';
    return new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'medium',
    }).format(new Date(value));
};
</script>

<template>
    <Head title="Manajemen User" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    Manajemen User
                </h2>
                <PrimaryButton @click="openCreate">
                    <Plus class="mr-2 h-4 w-4" />
                    Tambah User
                </PrimaryButton>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div
                    v-if="flash.success"
                    class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800"
                >
                    {{ flash.success }}
                </div>
                <div
                    v-if="flash.error"
                    class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"
                >
                    {{ flash.error }}
                </div>

                <div class="overflow-hidden rounded-lg bg-white shadow-sm">
                    <div class="border-b border-gray-200 px-6 py-4">
                        <div class="flex items-center gap-3">
                            <Users class="h-5 w-5 text-gray-500" />
                            <div>
                                <h3 class="text-base font-semibold text-gray-900">
                                    Daftar User
                                </h3>
                                <p class="text-sm text-gray-500">
                                    Kelola akun pengguna sistem KusumaVision NMS.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div v-if="users.length === 0" class="px-6 py-12 text-center">
                        <Users class="mx-auto h-10 w-10 text-gray-300" />
                        <h3 class="mt-3 text-sm font-semibold text-gray-900">Belum ada user</h3>
                        <p class="mt-1 text-sm text-gray-500">Tambahkan user pertama untuk memulai.</p>
                        <div class="mt-5">
                            <PrimaryButton @click="openCreate">
                                <Plus class="mr-2 h-4 w-4" />
                                Tambah User
                            </PrimaryButton>
                        </div>
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">
                                        Nama
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">
                                        Email
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">
                                        Terdaftar
                                    </th>
                                    <th class="px-6 py-3 text-center text-xs font-semibold uppercase tracking-wide text-gray-600">
                                        Aksi
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white">
                                <tr v-for="user in users" :key="user.id">
                                    <td class="px-6 py-4">
                                        <div class="flex items-center gap-3">
                                            <div class="flex h-8 w-8 items-center justify-center rounded-full bg-indigo-100 text-sm font-semibold text-indigo-700">
                                                {{ user.name.charAt(0).toUpperCase() }}
                                            </div>
                                            <div>
                                                <div class="font-medium text-gray-900">{{ user.name }}</div>
                                                <div v-if="user.id === $page.props.auth.user.id" class="text-xs text-indigo-600">
                                                    (Anda)
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-700">
                                        {{ user.email }}
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-500">
                                        {{ formatDate(user.created_at) }}
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="flex justify-center gap-1.5">
                                            <IconButton title="Edit User" @click="openEdit(user)">
                                                <Pencil class="h-4 w-4" />
                                            </IconButton>
                                            <IconButton
                                                variant="danger"
                                                title="Hapus User"
                                                :disabled="user.id === $page.props.auth.user.id"
                                                @click="deleteUser(user)"
                                            >
                                                <Trash2 class="h-4 w-4" />
                                            </IconButton>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal Tambah / Edit User -->
        <Modal :show="showModal" max-width="md" @close="closeModal">
            <form @submit.prevent="submit" class="p-6">
                <h3 class="text-base font-semibold text-gray-900">
                    {{ editingUser ? 'Edit User' : 'Tambah User' }}
                </h3>
                <p class="mt-1 text-sm text-gray-500">
                    {{ editingUser ? 'Perbarui informasi akun user.' : 'Isi data untuk membuat akun baru.' }}
                </p>

                <div class="mt-5 space-y-4">
                    <div>
                        <InputLabel for="name" value="Nama" />
                        <TextInput
                            id="name"
                            v-model="form.name"
                            type="text"
                            class="mt-1 block w-full"
                            placeholder="Nama lengkap"
                            autofocus
                            autocomplete="name"
                        />
                        <InputError :message="form.errors.name" class="mt-1" />
                    </div>

                    <div>
                        <InputLabel for="email" value="Email" />
                        <TextInput
                            id="email"
                            v-model="form.email"
                            type="email"
                            class="mt-1 block w-full"
                            placeholder="email@contoh.com"
                            autocomplete="email"
                        />
                        <InputError :message="form.errors.email" class="mt-1" />
                    </div>

                    <div>
                        <InputLabel
                            for="password"
                            :value="editingUser ? 'Password Baru (kosongkan jika tidak diganti)' : 'Password'"
                        />
                        <TextInput
                            id="password"
                            v-model="form.password"
                            type="password"
                            class="mt-1 block w-full"
                            placeholder="••••••••"
                            autocomplete="new-password"
                        />
                        <InputError :message="form.errors.password" class="mt-1" />
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <SecondaryButton type="button" @click="closeModal">
                        Batal
                    </SecondaryButton>
                    <PrimaryButton type="submit" :disabled="form.processing">
                        {{ editingUser ? 'Simpan Perubahan' : 'Buat User' }}
                    </PrimaryButton>
                </div>
            </form>
        </Modal>

        <ConfirmModal :state="confirmState" @confirm="handleConfirm" @cancel="handleCancel" />
    </AuthenticatedLayout>
</template>
