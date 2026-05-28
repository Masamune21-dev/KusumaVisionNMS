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
                <h2 class="text-lg font-semibold leading-tight sm:text-xl text-white">
                    Manajemen User
                </h2>
                <PrimaryButton class="w-full sm:w-auto" @click="openCreate">
                    <Plus class="mr-2 h-4 w-4" />
                    Tambah User
                </PrimaryButton>
            </div>
        </template>

        <div class="min-h-[60vh] pt-5 pb-16 sm:pt-8">
            <div class="w-full px-4 sm:px-6 lg:px-8">
                <div v-if="flash.success" class="mb-5 flex items-center gap-3 rounded-lg border border-emerald-500/30 bg-emerald-500/15 px-4 py-3 text-sm text-emerald-300">
                    <span class="h-2 w-2 flex-shrink-0 rounded-full bg-emerald-500"></span>
                    {{ flash.success }}
                </div>
                <div v-if="flash.error" class="mb-5 flex items-center gap-3 rounded-lg border border-red-500/30 bg-red-500/15 px-4 py-3 text-sm text-red-300">
                    <span class="h-2 w-2 flex-shrink-0 rounded-full bg-red-500"></span>
                    {{ flash.error }}
                </div>

                <div class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                    <div class="flex items-center gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                        <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-sky-500/15 ring-1 ring-cyan-500/30">
                            <Users class="h-5 w-5 text-cyan-400" />
                        </div>
                        <div>
                            <h3 class="text-base font-semibold text-white">
                                Daftar User
                            </h3>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Kelola akun pengguna sistem KusumaVision NMS.
                            </p>
                        </div>
                    </div>

                    <div v-if="users.length === 0" class="px-6 py-12 text-center">
                        <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-slate-800/60 ring-1 ring-slate-500/30">
                            <Users class="h-7 w-7 text-slate-400" />
                        </div>
                        <h3 class="text-sm font-semibold text-white">Belum ada user</h3>
                        <p class="mt-1 text-sm text-slate-500">Tambahkan user pertama untuk memulai.</p>
                        <div class="mt-5">
                            <PrimaryButton @click="openCreate">
                                <Plus class="mr-2 h-4 w-4" />
                                Tambah User
                            </PrimaryButton>
                        </div>
                    </div>

                    <template v-else>
                        <div class="kv-mobile-list">
                            <article v-for="user in users" :key="user.id" class="kv-mobile-card">
                                <div class="kv-mobile-card-header">
                                    <div class="flex min-w-0 items-center gap-3">
                                        <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full bg-sky-500/15 text-sm font-semibold text-cyan-300 ring-1 ring-cyan-500/30">
                                            {{ user.name.charAt(0).toUpperCase() }}
                                        </div>
                                        <div class="min-w-0">
                                            <h4 class="kv-mobile-card-title">
                                                {{ user.name }}
                                                <span v-if="user.id === $page.props.auth.user.id" class="text-cyan-400">(Anda)</span>
                                            </h4>
                                            <p class="kv-mobile-card-subtitle">{{ user.email }}</p>
                                        </div>
                                    </div>
                                    <div class="flex flex-shrink-0 gap-2">
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
                                </div>
                                <div class="kv-mobile-fields">
                                    <div class="kv-mobile-field">
                                        <span class="kv-mobile-label">Terdaftar</span>
                                        <span class="kv-mobile-value">{{ formatDate(user.created_at) }}</span>
                                    </div>
                                </div>
                            </article>
                        </div>

                        <div class="kv-table-desktop">
                        <table class="min-w-[720px] w-full">
                            <thead>
                                <tr class="border-b border-white/10 bg-slate-950/40">
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                                        Nama
                                    </th>
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                                        Email
                                    </th>
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                                        Terdaftar
                                    </th>
                                    <th class="px-4 py-3.5 text-center text-xs font-semibold uppercase tracking-wider text-slate-500">
                                        Aksi
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/5">
                                <tr v-for="user in users" :key="user.id" class="transition-colors duration-150 hover:bg-white/[0.03]">
                                    <td class="px-4 py-4">
                                        <div class="flex items-center gap-3">
                                            <div class="flex h-8 w-8 items-center justify-center rounded-full bg-sky-500/15 text-sm font-semibold text-cyan-300 ring-1 ring-cyan-500/30">
                                                {{ user.name.charAt(0).toUpperCase() }}
                                            </div>
                                            <div>
                                                <div class="font-medium text-white">{{ user.name }}</div>
                                                <div v-if="user.id === $page.props.auth.user.id" class="text-xs text-cyan-400">
                                                    (Anda)
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-4 text-sm text-slate-200">
                                        {{ user.email }}
                                    </td>
                                    <td class="px-4 py-4 text-sm text-slate-500">
                                        {{ formatDate(user.created_at) }}
                                    </td>
                                    <td class="px-4 py-4">
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
                    </template>
                </div>
            </div>
        </div>

        <!-- Modal Tambah / Edit User -->
        <Modal :show="showModal" max-width="md" @close="closeModal">
            <form @submit.prevent="submit" class="p-6">
                <h3 class="text-base font-semibold text-white">
                    {{ editingUser ? 'Edit User' : 'Tambah User' }}
                </h3>
                <p class="mt-1 text-sm text-slate-500">
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

                <div class="mt-6 grid gap-2 sm:flex sm:justify-end">
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
