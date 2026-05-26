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
        return 'text-slate-400';
    }

    if (value <= -28 || value >= -8) {
        return 'text-red-700';
    }

    if (value <= -25 || value >= -10) {
        return 'text-amber-700';
    }

    return 'text-emerald-700';
};

const rxBadgeClass = (value) => {
    if (value === null || value === undefined) return 'bg-slate-100 text-slate-500 ring-1 ring-slate-200';
    if (value <= -28 || value >= -8) return 'bg-red-50 text-red-700 ring-1 ring-red-200';
    if (value <= -25 || value >= -10) return 'bg-amber-50 text-amber-700 ring-1 ring-amber-200';
    return 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200';
};
</script>

<template>
    <Head :title="`ONU ${olt.name} ${slot}/${port}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="text-lg font-semibold leading-tight sm:text-xl text-slate-800">
                        ONU Slot {{ slot }} Port {{ port }}
                    </h2>
                    <p class="mt-1 text-sm text-slate-500">
                        {{ olt.name }} · {{ olt.ip }}
                    </p>
                </div>
                <div class="grid gap-2 [&>a>button]:w-full [&>button]:w-full sm:flex sm:flex-wrap sm:[&>a>button]:w-auto sm:[&>button]:w-auto">
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

        <div class="min-h-[60vh] pt-5 pb-16 sm:pt-8">
            <div class="w-full space-y-5 px-4 sm:px-6 lg:px-8">
                <!-- Flash messages -->
                <div
                    v-if="flash.success"
                    class="mb-5 flex items-center gap-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700"
                >
                    <span class="h-2 w-2 flex-shrink-0 rounded-full bg-emerald-500"></span>
                    {{ flash.success }}
                </div>
                <div
                    v-if="flash.error"
                    class="mb-5 flex items-center gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
                >
                    <span class="h-2 w-2 flex-shrink-0 rounded-full bg-red-500"></span>
                    {{ flash.error }}
                </div>

                <!-- Stat cards -->
                <div class="grid gap-4 sm:grid-cols-2 md:grid-cols-4">
                    <!-- Data status -->
                    <div class="rounded-lg border border-sky-200 bg-white p-5 shadow-sm shadow-sky-100/60">
                        <div class="flex items-center justify-between">
                            <p class="text-xs font-medium uppercase tracking-wider text-slate-500">Status</p>
                            <span
                                class="h-2 w-2 rounded-full"
                                :class="snapshot.ok ? 'bg-emerald-500' : 'bg-slate-300'"
                            ></span>
                        </div>
                        <p
                            class="mt-3 text-2xl font-bold"
                            :class="snapshot.ok ? 'text-emerald-600' : 'text-slate-400'"
                        >
                            {{ snapshot.ok ? 'Tersedia' : 'Kosong' }}
                        </p>
                    </div>
                    <!-- Total ONU -->
                    <div class="rounded-lg border border-sky-200 bg-white p-5 shadow-sm shadow-sky-100/60">
                        <p class="text-xs font-medium uppercase tracking-wider text-slate-500">Total ONU</p>
                        <p class="mt-3 text-2xl font-bold text-slate-900">{{ snapshot.count }}</p>
                    </div>
                    <!-- Online -->
                    <div class="rounded-lg border border-sky-200 bg-white p-5 shadow-sm shadow-sky-100/60">
                        <p class="text-xs font-medium uppercase tracking-wider text-slate-500">Online</p>
                        <div class="mt-3 flex items-end gap-2">
                            <p class="text-2xl font-bold text-emerald-600">
                                {{ snapshot.onus.filter((o) => o.online).length }}
                            </p>
                            <p class="mb-0.5 text-sm text-slate-400">/ {{ snapshot.count }}</p>
                        </div>
                    </div>
                    <!-- Refresh terakhir -->
                    <div class="rounded-lg border border-sky-200 bg-white p-5 shadow-sm shadow-sky-100/60">
                        <p class="text-xs font-medium uppercase tracking-wider text-slate-500">Refresh Terakhir</p>
                        <p class="mt-3 text-sm font-semibold text-slate-900">{{ formatDate(snapshot.refreshed_at) }}</p>
                    </div>
                </div>

                <!-- ONU table card -->
                <div class="overflow-hidden rounded-lg border border-sky-200 bg-white shadow-sm shadow-sky-100/60">
                    <div class="flex flex-col gap-3 border-b border-slate-100 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                        <div class="flex items-center gap-3">
                            <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-sky-100 ring-1 ring-sky-200">
                                <Router class="h-5 w-5 text-sky-600" />
                            </div>
                            <div>
                                <h3 class="text-base font-semibold text-slate-900">Registered ONU</h3>
                                <p v-if="snapshot.rx_power?.error" class="mt-0.5 text-xs text-red-600">
                                    RX gagal dibaca: {{ snapshot.rx_power.error }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Empty state -->
                    <div v-if="snapshot.onus.length === 0" class="px-6 py-14 text-center">
                        <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-slate-100 ring-1 ring-slate-200">
                            <Wifi class="h-7 w-7 text-slate-400" />
                        </div>
                        <h3 class="text-sm font-semibold text-slate-700">Belum ada data ONU</h3>
                        <p class="mt-1 text-sm text-slate-500">
                            Jalankan Refresh ONU untuk membaca ONU terdaftar di port ini.
                        </p>
                    </div>

                    <!-- Table -->
                    <div v-else class="overflow-x-auto">
                        <table class="min-w-[720px] w-full">
                            <thead>
                                <tr class="border-b border-slate-100 bg-slate-50">
                                    <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">ONU</th>
                                    <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Serial</th>
                                    <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Type</th>
                                    <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">ONU RX</th>
                                    <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Phase</th>
                                    <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Admin</th>
                                    <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Last Down</th>
                                    <th class="px-6 py-3.5 text-center text-xs font-semibold uppercase tracking-wider text-slate-500">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr
                                    v-for="onu in snapshot.onus"
                                    :key="`${onu.if_index}-${onu.onu_id}`"
                                    class="transition-colors duration-150 hover:bg-slate-50"
                                >
                                    <td class="px-6 py-4">
                                        <div class="font-semibold text-slate-900">{{ onu.interface }}</div>
                                        <div class="mt-0.5 text-xs text-slate-500">{{ onu.name || onu.description || '—' }}</div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <span class="font-mono text-sm text-slate-700">{{ onu.serial_number || '—' }}</span>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-slate-700">
                                        {{ onu.type_name || '—' }}
                                    </td>
                                    <td class="px-6 py-4">
                                        <span
                                            class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                            :class="rxBadgeClass(onu.rx_power_dbm)"
                                        >
                                            {{ onu.rx_power_label || '—' }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="flex items-center gap-1.5">
                                            <span
                                                class="h-1.5 w-1.5 flex-shrink-0 rounded-full"
                                                :class="onu.online ? 'bg-emerald-500' : 'bg-slate-300'"
                                            ></span>
                                            <span
                                                class="text-sm"
                                                :class="onu.online ? 'text-emerald-600' : 'text-slate-500'"
                                            >
                                                {{ onu.phase_state }}
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <span
                                            class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium ring-1"
                                            :class="onu.admin_state === 'active'
                                                ? 'bg-sky-50 text-sky-700 ring-sky-200'
                                                : 'bg-slate-100 text-slate-500 ring-slate-200'"
                                        >
                                            {{ onu.admin_state }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-slate-500">
                                        {{ onu.last_down_cause || '—' }}
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
                <h3 class="text-base font-semibold text-slate-900">Edit Info ONU</h3>
                <p class="mt-1 text-sm text-slate-500">{{ editing.interface }} · ditulis via SNMP SET.</p>
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
                <div class="mt-6 grid gap-2 sm:flex sm:justify-end">
                    <SecondaryButton type="button" @click="editing.open = false">Batal</SecondaryButton>
                    <PrimaryButton type="submit" :disabled="editForm.processing">Simpan</PrimaryButton>
                </div>
            </form>
        </Modal>

        <ConfirmModal :state="confirmState" @confirm="handleConfirm" @cancel="handleCancel" />
    </AuthenticatedLayout>
</template>
