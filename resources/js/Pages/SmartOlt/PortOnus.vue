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
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Pencil, Power, RefreshCw, Router, ToggleLeft, ToggleRight, Wifi } from '@lucide/vue';
import { computed, reactive } from 'vue';

const props = defineProps({
    olt: {
        type: Object,
        required: true,
    },
    slot: {
        type: Number,
        required: true,
    },
    port: {
        type: Number,
        required: true,
    },
    snapshot: {
        type: Object,
        required: true,
    },
});

const page = usePage();
const flash = computed(() => page.props.flash ?? {});
const caps = computed(() => props.olt.capabilities ?? {});
const { confirmState, confirm, handleConfirm, handleCancel } = useConfirm();

const refresh = () => {
    router.post(route('smartolt.port-onus.refresh', [props.olt.id, props.slot, props.port]), {}, {
        preserveScroll: true,
    });
};

const busy = reactive({});
const actionKey = (onu) => `${onu.if_index}-${onu.onu_id}`;

const rebootOnu = async (onu) => {
    const ok = await confirm({
        title: 'Reboot ONU',
        message: `Reboot ${onu.interface}? ONU akan restart 30-60 detik.`,
        confirmLabel: 'Reboot',
    });

    if (!ok) {
        return;
    }

    const key = actionKey(onu);
    busy[key] = true;
    router.post(route('smartolt.onu.reboot', [props.olt.id, props.slot, props.port, onu.onu_id]), {
        if_index: onu.if_index,
    }, {
        preserveScroll: true,
        onFinish: () => { busy[key] = false; },
    });
};

const toggleOnu = async (onu) => {
    const active = onu.admin_state !== 'active';
    const verb = active ? 'enable' : 'disable';
    const ok = await confirm({
        title: active ? 'Enable ONU' : 'Disable ONU',
        message: `Yakin ${verb} ${onu.interface}?`,
        confirmLabel: active ? 'Enable' : 'Disable',
        variant: active ? 'primary' : 'danger',
    });

    if (!ok) {
        return;
    }

    const key = actionKey(onu);
    busy[key] = true;
    router.post(route('smartolt.onu.state', [props.olt.id, props.slot, props.port, onu.onu_id]), {
        active,
        if_index: onu.if_index,
    }, {
        preserveScroll: true,
        onFinish: () => { busy[key] = false; },
    });
};

const editForm = useForm({
    onu_id: null,
    if_index: null,
    name: '',
    description: '',
});
const editing = reactive({ open: false, interface: '' });

const openEdit = (onu) => {
    editForm.clearErrors();
    editForm.onu_id = onu.onu_id;
    editForm.if_index = onu.if_index;
    editForm.name = onu.name ?? '';
    editForm.description = onu.description ?? '';
    editing.interface = onu.interface;
    editing.open = true;
};

const submitEdit = () => {
    editForm.post(route('smartolt.onu.info', [props.olt.id, props.slot, props.port, editForm.onu_id]), {
        preserveScroll: true,
        onSuccess: () => { editing.open = false; },
    });
};

const formatDate = (value) => {
    if (!value) {
        return '-';
    }

    return new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
};

const rxClass = (value) => {
    if (value === null || value === undefined) {
        return 'text-gray-400';
    }

    if (value <= -28 || value >= -8) {
        return 'text-red-700';
    }

    if (value <= -25 || value >= -10) {
        return 'text-amber-700';
    }

    return 'text-emerald-700';
};
</script>

<template>
    <Head :title="`ONU ${olt.name} ${slot}/${port}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="text-xl font-semibold leading-tight text-gray-800">
                        ONU Slot {{ slot }} Port {{ port }}
                    </h2>
                    <p class="mt-1 text-sm text-gray-500">
                        {{ olt.name }} · {{ olt.ip }}
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <Link :href="route('smartolt.gpon-ports', olt.id)">
                        <SecondaryButton type="button">
                            <ArrowLeft class="mr-2 h-4 w-4" />
                            GPON Port & ONU
                        </SecondaryButton>
                    </Link>
                    <PrimaryButton type="button" @click="refresh">
                        <RefreshCw class="mr-2 h-4 w-4" />
                        Refresh ONU
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

                <div class="grid gap-4 md:grid-cols-4">
                    <div class="rounded-lg bg-white p-5 shadow-sm">
                        <div class="text-sm font-medium text-gray-500">Data</div>
                        <div
                            class="mt-2 text-2xl font-semibold"
                            :class="snapshot.ok ? 'text-emerald-700' : 'text-gray-900'"
                        >
                            {{ snapshot.ok ? 'Tersedia' : 'Kosong' }}
                        </div>
                    </div>
                    <div class="rounded-lg bg-white p-5 shadow-sm">
                        <div class="text-sm font-medium text-gray-500">Total ONU</div>
                        <div class="mt-2 text-2xl font-semibold text-gray-900">
                            {{ snapshot.count }}
                        </div>
                    </div>
                    <div class="rounded-lg bg-white p-5 shadow-sm">
                        <div class="text-sm font-medium text-gray-500">Online</div>
                        <div class="mt-2 text-2xl font-semibold text-gray-900">
                            {{ snapshot.onus.filter((onu) => onu.online).length }}
                        </div>
                    </div>
                    <div class="rounded-lg bg-white p-5 shadow-sm">
                        <div class="text-sm font-medium text-gray-500">Refresh Terakhir</div>
                        <div class="mt-2 text-sm font-semibold text-gray-900">
                            {{ formatDate(snapshot.refreshed_at) }}
                        </div>
                    </div>
                </div>

                <div class="rounded-lg bg-white shadow-sm">
                    <div class="flex items-center gap-3 border-b border-gray-200 px-6 py-4">
                        <Router class="h-5 w-5 text-gray-500" />
                        <div>
                            <h3 class="text-base font-semibold text-gray-900">
                                Registered ONU
                            </h3>
                            <p v-if="snapshot.rx_power?.error" class="mt-1 text-xs text-red-600">
                                RX gagal dibaca: {{ snapshot.rx_power.error }}
                            </p>
                        </div>
                    </div>

                    <div v-if="snapshot.onus.length === 0" class="px-6 py-10 text-center">
                        <Wifi class="mx-auto h-10 w-10 text-gray-300" />
                        <h3 class="mt-3 text-sm font-semibold text-gray-900">
                            Belum ada data ONU
                        </h3>
                        <p class="mt-1 text-sm text-gray-500">
                            Jalankan Refresh ONU untuk membaca ONU terdaftar di port ini.
                        </p>
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">ONU</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Serial</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Type</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">ONU RX</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Phase</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Admin</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Last Down</th>
                                    <th class="px-6 py-3 text-center text-xs font-semibold uppercase tracking-wide text-gray-600">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <tr v-for="onu in snapshot.onus" :key="`${onu.if_index}-${onu.onu_id}`">
                                    <td class="px-6 py-4">
                                        <div class="text-sm font-semibold text-gray-900">
                                            {{ onu.interface }}
                                        </div>
                                        <div class="text-xs text-gray-500">
                                            {{ onu.name || onu.description || '-' }}
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-700">
                                        {{ onu.serial_number || '-' }}
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-700">
                                        {{ onu.type_name || '-' }}
                                    </td>
                                    <td class="px-6 py-4 text-sm font-semibold" :class="rxClass(onu.rx_power_dbm)">
                                        {{ onu.rx_power_label || '-' }}
                                    </td>
                                    <td class="px-6 py-4">
                                        <span
                                            class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium"
                                            :class="onu.online
                                                ? 'bg-emerald-100 text-emerald-800'
                                                : 'bg-gray-100 text-gray-700'"
                                        >
                                            {{ onu.phase_state }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-700">
                                        {{ onu.admin_state }}
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-700">
                                        {{ onu.last_down_cause }}
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="flex items-center justify-center gap-1.5">
                                            <IconButton
                                                v-if="caps.supports_onu_info_write"
                                                title="Edit info ONU"
                                                @click="openEdit(onu)"
                                            >
                                                <Pencil class="h-4 w-4" />
                                            </IconButton>
                                            <IconButton
                                                v-if="caps.supports_onu_toggle"
                                                :variant="onu.admin_state === 'active' ? 'warning' : 'success'"
                                                :disabled="busy[actionKey(onu)]"
                                                :title="onu.admin_state === 'active' ? 'Disable ONU' : 'Enable ONU'"
                                                @click="toggleOnu(onu)"
                                            >
                                                <ToggleRight v-if="onu.admin_state === 'active'" class="h-4 w-4" />
                                                <ToggleLeft v-else class="h-4 w-4" />
                                            </IconButton>
                                            <IconButton
                                                v-if="caps.supports_reboot"
                                                variant="danger"
                                                :disabled="busy[actionKey(onu)]"
                                                title="Reboot ONU"
                                                @click="rebootOnu(onu)"
                                            >
                                                <Power class="h-4 w-4" />
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

        <Modal :show="editing.open" @close="editing.open = false">
            <form class="p-6" @submit.prevent="submitEdit">
                <h3 class="text-base font-semibold text-gray-900">
                    Edit Info ONU
                </h3>
                <p class="mt-1 text-sm text-gray-500">
                    {{ editing.interface }} · ditulis via SNMP SET.
                </p>

                <div class="mt-4 space-y-4">
                    <div>
                        <InputLabel for="onu_name" value="Nama ONU" />
                        <TextInput id="onu_name" v-model="editForm.name" type="text" class="mt-1 block w-full" maxlength="191" />
                        <InputError :message="editForm.errors.name" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel for="onu_description" value="Deskripsi" />
                        <TextInput id="onu_description" v-model="editForm.description" type="text" class="mt-1 block w-full" maxlength="191" />
                        <InputError :message="editForm.errors.description" class="mt-1" />
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <SecondaryButton type="button" @click="editing.open = false">
                        Batal
                    </SecondaryButton>
                    <PrimaryButton type="submit" :disabled="editForm.processing">
                        Simpan
                    </PrimaryButton>
                </div>
            </form>
        </Modal>

        <ConfirmModal :state="confirmState" @confirm="handleConfirm" @cancel="handleCancel" />
    </AuthenticatedLayout>
</template>
