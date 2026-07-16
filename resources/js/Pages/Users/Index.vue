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
import { formatDate } from '@/lib/datetime';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2, Users } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n({ useScope: 'global' });

const props = defineProps({
    users: {
        type: Array,
        required: true,
    },
    roleOptions: {
        type: Array,
        default: () => [],
    },
    oltOptions: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const flash = computed(() => page.props.flash ?? {});
const { confirmState, confirm, handleConfirm, handleCancel } = useConfirm();

const showModal = ref(false);
const editingUser = ref(null);

const defaultRole = computed(() => props.roleOptions[0]?.value ?? 'operator');

const form = useForm({
    name: '',
    email: '',
    role: defaultRole.value,
    password: '',
    olt_ids: [],
});

const roleLabel = (value) =>
    props.roleOptions.find((option) => option.value === value)?.label ?? value;

const roleBadgeClass = (value) => {
    switch (value) {
        case 'admin':
            return 'border-cyan-500/30 bg-cyan-500/15 text-cyan-300';
        case 'operator':
            return 'border-emerald-500/30 bg-emerald-500/15 text-emerald-300';
        case 'partner':
            return 'border-violet-500/30 bg-violet-500/15 text-violet-300';
        case 'demo':
            return 'border-amber-500/30 bg-amber-500/15 text-amber-300';
        default:
            return 'border-slate-500/30 bg-slate-500/15 text-slate-300';
    }
};

// Assignment OLT tersedia untuk partner (wajib membatasi) & operator (opsional membatasi).
const showOltAssignment = computed(() => form.role === 'partner' || form.role === 'operator');

const oltAssignmentHint = computed(() =>
    form.role === 'operator'
        ? t('users.assign_hint_operator')
        : t('users.assign_hint_partner'),
);

// Label ringkas cakupan OLT di daftar user. Operator tanpa assignment = akses penuh (tak ditampilkan).
const oltScopeText = (user) => {
    const count = assignedCount(user);
    if (user.role === 'partner') {
        return t('users.olt_assigned', { n: count });
    }
    if (user.role === 'operator' && count > 0) {
        return t('users.olt_assigned', { n: count });
    }
    return null;
};

const toggleOlt = (id) => {
    const idx = form.olt_ids.indexOf(id);
    if (idx === -1) {
        form.olt_ids.push(id);
    } else {
        form.olt_ids.splice(idx, 1);
    }
};

const assignedCount = (user) => (user.assigned_olt_ids ?? []).length;

const openCreate = () => {
    editingUser.value = null;
    form.reset();
    form.role = defaultRole.value;
    form.olt_ids = [];
    form.clearErrors();
    showModal.value = true;
};

const openEdit = (user) => {
    editingUser.value = user;
    form.name = user.name;
    form.email = user.email;
    form.role = user.role ?? defaultRole.value;
    form.password = '';
    form.olt_ids = [...(user.assigned_olt_ids ?? [])];
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
        title: t('users.delete'),
        message: t('users.delete_msg', { name: user.name, email: user.email }),
        confirmLabel: t('common.delete'),
        variant: 'danger',
    });

    if (!ok) return;

    router.delete(route('users.destroy', user.id), { preserveScroll: true });
};

</script>

<template>
    <Head :title="$t('users.title')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <h2 class="text-lg font-semibold leading-tight sm:text-xl text-white">
                    {{ $t('users.title') }}
                </h2>
                <PrimaryButton class="w-full sm:w-auto" @click="openCreate">
                    <Plus class="mr-2 h-4 w-4" />
                    {{ $t('users.add') }}
                </PrimaryButton>
            </div>
        </template>

        <div class="min-h-[60vh] pt-5 pb-16 sm:pt-8">
            <div class="w-full px-4 sm:px-6 lg:px-8">

                <div class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                    <div class="flex items-center gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                        <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-sky-500/15 ring-1 ring-cyan-500/30">
                            <Users class="h-5 w-5 text-cyan-400" />
                        </div>
                        <div>
                            <h3 class="text-base font-semibold text-white">
                                {{ $t('users.list_title') }}
                            </h3>
                            <p class="mt-0.5 text-xs text-slate-500">
                                {{ $t('users.list_sub') }}
                            </p>
                        </div>
                    </div>

                    <div v-if="users.length === 0" class="px-6 py-12 text-center">
                        <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-slate-800/60 ring-1 ring-slate-500/30">
                            <Users class="h-7 w-7 text-slate-400" />
                        </div>
                        <h3 class="text-sm font-semibold text-white">{{ $t('users.empty_title') }}</h3>
                        <p class="mt-1 text-sm text-slate-500">{{ $t('users.empty_sub') }}</p>
                        <div class="mt-5">
                            <PrimaryButton @click="openCreate">
                                <Plus class="mr-2 h-4 w-4" />
                                {{ $t('users.add') }}
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
                                                <span v-if="user.id === $page.props.auth.user.id" class="text-cyan-400">{{ $t('users.you') }}</span>
                                            </h4>
                                            <p class="kv-mobile-card-subtitle">{{ user.email }}</p>
                                        </div>
                                    </div>
                                    <div class="flex flex-shrink-0 gap-2">
                                        <IconButton :title="$t('users.edit')" @click="openEdit(user)">
                                            <Pencil class="h-4 w-4" />
                                        </IconButton>
                                        <IconButton
                                            variant="danger"
                                            :title="$t('users.delete')"
                                            :disabled="user.id === $page.props.auth.user.id"
                                            @click="deleteUser(user)"
                                        >
                                            <Trash2 class="h-4 w-4" />
                                        </IconButton>
                                    </div>
                                </div>
                                <div class="kv-mobile-fields">
                                    <div class="kv-mobile-field">
                                        <span class="kv-mobile-label">Role</span>
                                        <span class="kv-mobile-value">
                                            <span :class="['inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium', roleBadgeClass(user.role)]">
                                                {{ roleLabel(user.role) }}
                                            </span>
                                            <span v-if="oltScopeText(user)" class="ml-2 text-xs text-slate-500">
                                                {{ oltScopeText(user) }}
                                            </span>
                                        </span>
                                    </div>
                                    <div class="kv-mobile-field">
                                        <span class="kv-mobile-label">{{ $t('users.col_registered') }}</span>
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
                                        {{ $t('users.col_name') }}
                                    </th>
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                                        Email
                                    </th>
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                                        Role
                                    </th>
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                                        {{ $t('users.col_registered') }}
                                    </th>
                                    <th class="px-4 py-3.5 text-center text-xs font-semibold uppercase tracking-wider text-slate-500">
                                        {{ $t('common.actions') }}
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
                                                    {{ $t('users.you') }}
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-4 text-sm text-slate-200">
                                        {{ user.email }}
                                    </td>
                                    <td class="px-4 py-4">
                                        <span :class="['inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium', roleBadgeClass(user.role)]">
                                            {{ roleLabel(user.role) }}
                                        </span>
                                        <div v-if="oltScopeText(user)" class="mt-1 text-xs text-slate-500">
                                            {{ oltScopeText(user) }}
                                        </div>
                                    </td>
                                    <td class="px-4 py-4 text-sm text-slate-500">
                                        {{ formatDate(user.created_at) }}
                                    </td>
                                    <td class="px-4 py-4">
                                        <div class="flex justify-center gap-1.5">
                                            <IconButton :title="$t('users.edit')" @click="openEdit(user)">
                                                <Pencil class="h-4 w-4" />
                                            </IconButton>
                                            <IconButton
                                                variant="danger"
                                                :title="$t('users.delete')"
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
                    {{ editingUser ? $t('users.edit') : $t('users.add') }}
                </h3>
                <p class="mt-1 text-sm text-slate-500">
                    {{ editingUser ? $t('users.modal_edit_sub') : $t('users.modal_create_sub') }}
                </p>

                <div class="mt-5 space-y-4">
                    <div>
                        <InputLabel for="name" :value="$t('users.col_name')" />
                        <TextInput
                            id="name"
                            v-model="form.name"
                            type="text"
                            class="mt-1 block w-full"
                            :placeholder="$t('users.name_placeholder')"
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
                        <InputLabel for="role" value="Role" />
                        <select
                            id="role"
                            v-model="form.role"
                            class="mt-1 block w-full min-h-11 rounded-lg border-white/10 bg-slate-900/60 text-slate-100 shadow-inner shadow-black/20 focus:border-cyan-500 focus:ring-cyan-500"
                        >
                            <option v-for="option in roleOptions" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </option>
                        </select>
                        <InputError :message="form.errors.role" class="mt-1" />
                    </div>

                    <!-- OLT yang di-assign (wajib untuk partner, opsional untuk operator) -->
                    <div v-if="showOltAssignment">
                        <InputLabel :value="$t('users.assign_label')" />
                        <p class="mt-0.5 text-xs text-slate-500">
                            {{ oltAssignmentHint }}
                        </p>
                        <div
                            v-if="oltOptions.length"
                            class="mt-2 max-h-48 space-y-1 overflow-y-auto rounded-lg border border-white/10 bg-slate-900/60 p-2 shadow-inner shadow-black/20"
                        >
                            <label
                                v-for="olt in oltOptions"
                                :key="olt.value"
                                class="flex cursor-pointer items-center gap-3 rounded-md px-2 py-1.5 transition-colors hover:bg-white/[0.04]"
                            >
                                <input
                                    type="checkbox"
                                    :checked="form.olt_ids.includes(olt.value)"
                                    class="h-4 w-4 rounded border-white/20 bg-slate-800 text-cyan-500 focus:ring-cyan-500"
                                    @change="toggleOlt(olt.value)"
                                />
                                <span class="min-w-0">
                                    <span class="block truncate text-sm text-slate-100">{{ olt.label }}</span>
                                    <span class="block truncate text-xs text-slate-500">{{ olt.ip }}</span>
                                </span>
                            </label>
                        </div>
                        <p v-else class="mt-2 text-sm text-slate-500">
                            {{ $t('users.no_olt') }}
                        </p>
                        <InputError :message="form.errors.olt_ids" class="mt-1" />
                    </div>

                    <div>
                        <InputLabel
                            for="password"
                            :value="editingUser ? $t('users.password_new') : $t('users.password')"
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
                        {{ $t('common.cancel') }}
                    </SecondaryButton>
                    <PrimaryButton type="submit" :disabled="form.processing">
                        {{ editingUser ? $t('users.save_changes') : $t('users.create') }}
                    </PrimaryButton>
                </div>
            </form>
        </Modal>

        <ConfirmModal :state="confirmState" @confirm="handleConfirm" @cancel="handleCancel" />
    </AuthenticatedLayout>
</template>
