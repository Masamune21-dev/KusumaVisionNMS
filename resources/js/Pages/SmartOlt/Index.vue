<script setup>
import ConfirmModal from '@/Components/ConfirmModal.vue';
import IconButton from '@/Components/IconButton.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { formatDateTime } from '@/lib/datetime';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { BellOff, BellRing, Cable, Database, Eye, Pencil, Plus, RadioTower, RefreshCw, Save, Server, Terminal, Trash2 } from '@lucide/vue';
import { computed, defineAsyncComponent, ref } from 'vue';

const { t } = useI18n({ useScope: 'global' });

// Lazy-loaded so the heavy xterm bundle only loads when a telnet session opens.
const TelnetWindow = defineAsyncComponent(() => import('@/Components/Shell/TelnetWindow.vue'));

const props = defineProps({
    olts: {
        type: Array,
        required: true,
    },
    cdataOlts: {
        type: Array,
        default: () => [],
    },
    hiosoOlts: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const flash = computed(() => page.props.flash ?? {});
const canManageOlt = computed(() => Boolean(page.props.auth?.can?.manage_olt));
// Hapus device OLT global hanya admin+operator. Aksi lain (edit/telnet) pakai canManageOlt.
const canManageInventory = computed(() => Boolean(page.props.auth?.can?.manage_olt_inventory));
// Tambah OLT: admin/operator (global) ATAU partner (OLT privat miliknya sendiri).
const canAddOlt = computed(() => Boolean(page.props.auth?.can?.add_olt));
// Boleh hapus OLT ini: admin/operator utk OLT global, partner hanya utk OLT miliknya.
const canDeleteOlt = (olt) => canManageInventory.value || Boolean(olt.owned);
const { confirmState, confirm, handleConfirm, handleCancel } = useConfirm();

/* ------------------------------------------------------------------ */
/* Tab: OLT ZTE / OLT C-Data / OLT HiOSO — state disinkronkan ke ?tab  */
/* agar bertahan saat reload / redirect back dari aksi test/refresh.   */
/* ------------------------------------------------------------------ */
const tabs = [
    { key: 'zte', label: 'OLT ZTE', icon: Cable },
    { key: 'cdata', label: 'OLT C-Data', icon: Server },
    { key: 'hioso', label: 'OLT HiOSO', icon: RadioTower },
];
const initialTab = new URLSearchParams(window.location.search).get('tab');
const activeTab = ref(['cdata', 'hioso'].includes(initialTab) ? initialTab : 'zte');
const setTab = (key) => {
    activeTab.value = key;
    const url = new URL(window.location.href);
    if (key === 'zte') {
        url.searchParams.delete('tab');
    } else {
        url.searchParams.set('tab', key);
    }
    window.history.replaceState(window.history.state, '', url);
};

/* Tab non-ZTE (C-Data & HiOSO) berbagi satu body tabel; datanya di-switch per tab aktif. */
const isNonZteTab = computed(() => activeTab.value === 'cdata' || activeTab.value === 'hioso');
const isHiosoTab = computed(() => activeTab.value === 'hioso');
const nonZteOlts = computed(() => (isHiosoTab.value ? props.hiosoOlts : props.cdataOlts));
const nonZteHeader = computed(() =>
    isHiosoTab.value
        ? { title: t('smartolt.hioso_inventory_title'), subtitle: t('smartolt.hioso_inventory_subtitle') }
        : { title: t('smartolt.cdata_inventory_title'), subtitle: t('smartolt.cdata_inventory_subtitle') },
);
const nonZteEmpty = computed(() =>
    isHiosoTab.value
        ? { title: t('smartolt.empty_hioso_title'), subtitle: t('smartolt.empty_hioso_subtitle') }
        : { title: t('smartolt.empty_cdata_title'), subtitle: t('smartolt.empty_cdata_subtitle') },
);

// Tab non-ZTE berbagi body tabel tapi memakai controller berbeda: HiOSO → hioso-olt.*, C-Data → cdata-olt.*.
const nonZtePrefix = computed(() => (isHiosoTab.value ? 'hioso-olt' : 'cdata-olt'));
const nonZteRoute = (name, params) => route(`${nonZtePrefix.value}.${name}`, params);

const createHref = computed(() => {
    if (isNonZteTab.value) {
        return nonZteRoute('create');
    }
    return route('smartolt.create');
});

/* ------------------------------------------------------------------ */
/* Telnet                                                              */
/* ------------------------------------------------------------------ */
const telnetOlt = ref(null);
const openTelnet = (olt) => {
    telnetOlt.value = { id: olt.id, name: olt.name, ip: olt.ip };
};

/* ------------------------------------------------------------------ */
/* Toggle alarm per-OLT (mute) — satu route untuk semua family.        */
/* ------------------------------------------------------------------ */
const toggleAlarms = (olt) => {
    router.post(route('smartolt.alarms.toggle', olt.id), {}, {
        preserveScroll: true,
    });
};
// Partner mengatur saklar webhook-nya sendiri; admin/operator mengatur saklar OLT.
const isPartnerViewer = computed(() => Boolean(page.props.auth?.can?.is_partner));
const alarmTitle = (olt) => {
    if (isPartnerViewer.value) {
        return olt.alarms_enabled
            ? t('smartolt.alarm_on_partner')
            : t('smartolt.alarm_off_partner');
    }
    return olt.alarms_enabled
        ? t('smartolt.alarm_on_admin')
        : t('smartolt.alarm_off_admin');
};

/* ------------------------------------------------------------------ */
/* Aksi OLT ZTE                                                        */
/* ------------------------------------------------------------------ */
const destroyOlt = async (olt) => {
    const ok = await confirm({
        title: t('smartolt.confirm_delete_title'),
        message: t('smartolt.confirm_delete_msg', { name: olt.name }),
        confirmLabel: t('common.delete'),
    });

    if (!ok) {
        return;
    }

    router.delete(route('smartolt.destroy', olt.id), {
        preserveScroll: true,
    });
};

const testOlt = (olt) => {
    router.post(route('smartolt.test', olt.id), {}, {
        preserveScroll: true,
    });
};

/* ------------------------------------------------------------------ */
/* Aksi OLT non-ZTE (C-Data & HiOSO — route prefix ikut tab aktif)     */
/* ------------------------------------------------------------------ */
const destroyCdataOlt = async (olt) => {
    const ok = await confirm({
        title: isHiosoTab.value ? t('smartolt.confirm_delete_hioso_title') : t('smartolt.confirm_delete_cdata_title'),
        message: t('smartolt.confirm_delete_msg', { name: olt.name }),
        confirmLabel: t('common.delete'),
    });

    if (!ok) {
        return;
    }

    router.delete(nonZteRoute('destroy', olt.id), {
        preserveScroll: true,
    });
};

const testCdataOlt = (olt) => {
    router.post(nonZteRoute('test', olt.id), {}, {
        preserveScroll: true,
    });
};

/* ------------------------------------------------------------------ */
/* Simpan konfigurasi OLT ke memori (write) — semua family.            */
/* ZTE `write` (~30 detik di C300), C-Data `config`→`save`, HiOSO `write`. */
/* Route dipilih per-driver agar konsisten di tab ZTE maupun non-ZTE.  */
/* ------------------------------------------------------------------ */
const savingId = ref(null);
const saveConfigRoute = (olt) => {
    if (olt.driver === 'zte') {
        return route('smartolt.config.save', olt.id);
    }
    return route(`${olt.driver.startsWith('hioso') ? 'hioso-olt' : 'cdata-olt'}.config.save`, olt.id);
};
const saveConfig = async (olt) => {
    const ok = await confirm({
        title: t('smartolt.confirm_save_title'),
        message: t('smartolt.confirm_save_msg', { name: olt.name }),
        confirmLabel: t('common.save'),
    });

    if (!ok) {
        return;
    }

    router.post(saveConfigRoute(olt), {}, {
        preserveScroll: true,
        onStart: () => { savingId.value = olt.id; },
        onFinish: () => { savingId.value = null; },
    });
};

const firmwareBadge = (olt) => (olt.last_test_result?.cdata?.firmware_v3 ? 'FlashV3.x' : null);

const formatDate = (value) => formatDateTime(value);
</script>

<template>
    <Head title="SmartOLT" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <h2 class="text-lg font-semibold leading-tight text-white sm:text-xl">
                    SmartOLT
                </h2>
                <Link v-if="canAddOlt" :href="createHref" class="sm:w-auto">
                    <PrimaryButton class="w-full sm:w-auto">
                        <Plus class="mr-2 h-4 w-4" />
                        {{ $t('smartolt.add_olt') }}
                    </PrimaryButton>
                </Link>
            </div>
        </template>

        <div class="min-h-[60vh] pt-5 pb-16 sm:pt-8">
            <div class="w-full space-y-6 px-4 sm:px-6 lg:px-8">

                <!-- Tab bar -->
                <div class="flex flex-wrap gap-1 rounded-xl border border-white/10 bg-slate-900/40 p-1 backdrop-blur-xl">
                    <button
                        v-for="tab in tabs"
                        :key="tab.key"
                        type="button"
                        class="flex flex-1 items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium transition-colors sm:flex-none"
                        :class="activeTab === tab.key
                            ? 'bg-cyan-500/20 text-cyan-200 ring-1 ring-cyan-500/40'
                            : 'text-slate-400 hover:bg-white/5 hover:text-slate-200'"
                        @click="setTab(tab.key)"
                    >
                        <component :is="tab.icon" class="h-4 w-4" />
                        {{ tab.label }}
                    </button>
                </div>

                <!-- ============================ TAB: OLT ZTE ============================ -->
                <div v-show="activeTab === 'zte'" class="kv-glass-panel">
                    <!-- Card header -->
                    <div class="flex items-center gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                        <span class="kv-circle-sky !h-10 !w-10">
                            <Cable class="h-5 w-5" />
                        </span>
                        <div>
                            <h3 class="text-base font-semibold text-white">{{ $t('smartolt.zte_inventory_title') }}</h3>
                            <p class="text-xs text-slate-400">{{ $t('smartolt.zte_inventory_subtitle') }}</p>
                        </div>
                    </div>

                    <!-- Empty state -->
                    <div v-if="olts.length === 0" class="px-6 py-16 text-center">
                        <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-slate-800/60 ring-1 ring-white/10">
                            <Cable class="h-7 w-7 text-slate-500" />
                        </div>
                        <h3 class="text-sm font-semibold text-slate-200">{{ $t('smartolt.empty_zte_title') }}</h3>
                        <p class="mt-1 text-sm text-slate-500">{{ $t('smartolt.empty_zte_subtitle') }}</p>
                        <div v-if="canAddOlt" class="mt-5">
                            <Link :href="route('smartolt.create')">
                                <PrimaryButton>
                                    <Plus class="mr-2 h-4 w-4" />
                                    {{ $t('smartolt.add_olt') }}
                                </PrimaryButton>
                            </Link>
                        </div>
                    </div>

                    <!-- Table / mobile cards -->
                    <template v-else>
                        <div class="kv-mobile-list">
                            <article v-for="olt in olts" :key="olt.id" class="kv-mobile-card">
                                <div class="kv-mobile-card-header">
                                    <div class="min-w-0">
                                        <h4 class="kv-mobile-card-title">
                                            {{ olt.name }}
                                            <span v-if="olt.is_private" class="ml-1.5 inline-flex items-center rounded-full bg-fuchsia-500/15 px-2 py-0.5 text-[10px] font-medium text-fuchsia-200 ring-1 ring-fuchsia-400/30" :title="$t('common.private_hint')">{{ $t('common.private') }}</span>
                                        </h4>
                                        <p class="kv-mobile-card-subtitle">{{ olt.vendor || $t('smartolt.vendor_empty') }}</p>
                                    </div>
                                    <span :class="olt.driver === 'zte' ? 'kv-pill-info' : 'kv-pill-muted'">
                                        {{ olt.capabilities.vendor_family }}
                                    </span>
                                </div>

                                <div class="kv-mobile-fields">
                                    <div class="kv-mobile-field">
                                        <span class="kv-mobile-label">{{ $t('smartolt.col_snmp') }}</span>
                                        <span class="kv-mobile-value font-mono text-xs">{{ olt.ip }}:{{ olt.snmp_port }}</span>
                                    </div>
                                    <div class="kv-mobile-field">
                                        <span class="kv-mobile-label">{{ $t('smartolt.col_version') }}</span>
                                        <span class="kv-mobile-value uppercase tracking-widest">{{ olt.snmp_version }}</span>
                                    </div>
                                    <div class="kv-mobile-field">
                                        <span class="kv-mobile-label">{{ $t('smartolt.col_autopoll') }}</span>
                                        <span class="kv-mobile-value" :class="olt.polling_enabled ? 'text-emerald-400' : 'text-slate-500'">
                                            {{ olt.polling_enabled ? $t('common.on') : $t('common.off') }}
                                        </span>
                                    </div>
                                    <div class="kv-mobile-field">
                                        <span class="kv-mobile-label">{{ $t('smartolt.col_alarm') }}</span>
                                        <span class="kv-mobile-value" :class="olt.alarms_enabled ? 'text-emerald-400' : 'text-amber-400'">
                                            {{ olt.alarms_enabled ? $t('common.on') : $t('common.off') }}
                                        </span>
                                    </div>
                                    <div class="kv-mobile-field">
                                        <span class="kv-mobile-label">{{ $t('smartolt.col_test') }}</span>
                                        <span
                                            class="kv-mobile-value font-semibold"
                                            :class="olt.last_test_result?.ok
                                                ? 'text-emerald-300'
                                                : olt.last_test_result
                                                    ? 'text-red-300'
                                                    : 'text-slate-500'"
                                        >
                                            {{ olt.last_test_result?.ok ? $t('common.ok') : (olt.last_test_result ? $t('common.failed') : $t('common.not_tested')) }}
                                        </span>
                                    </div>
                                    <div class="kv-mobile-field">
                                        <span class="kv-mobile-label">{{ $t('smartolt.col_last') }}</span>
                                        <span class="kv-mobile-value">{{ formatDate(olt.last_tested_at) }}</span>
                                    </div>
                                </div>

                                <div class="mt-4 flex flex-wrap gap-2">
                                    <IconButton :href="route('smartolt.detail', olt.id)" :title="$t('common.detail')">
                                        <Eye class="h-4 w-4" />
                                    </IconButton>
                                    <IconButton
                                        v-if="canManageOlt"
                                        :variant="olt.alarms_enabled ? 'success' : 'warning'"
                                        :title="alarmTitle(olt)"
                                        @click="toggleAlarms(olt)"
                                    >
                                        <component :is="olt.alarms_enabled ? BellRing : BellOff" class="h-4 w-4" />
                                    </IconButton>
                                    <IconButton :title="$t('common.test_snmp')" @click="testOlt(olt)">
                                        <RefreshCw class="h-4 w-4" />
                                    </IconButton>
                                    <IconButton :href="route('smartolt.edit', olt.id)" :title="$t('common.edit')">
                                        <Pencil class="h-4 w-4" />
                                    </IconButton>
                                    <IconButton :href="route('smartolt.profiles.index', olt.id)" :title="$t('smartolt.title_profile')">
                                        <Database class="h-4 w-4" />
                                    </IconButton>
                                    <IconButton
                                        v-if="canManageOlt && olt.cli_transport === 'telnet' && olt.capabilities.supports_config_save"
                                        :title="$t('smartolt.title_save_config')"
                                        :disabled="savingId === olt.id"
                                        @click="saveConfig(olt)"
                                    >
                                        <Save class="h-4 w-4" :class="{ 'animate-pulse': savingId === olt.id }" />
                                    </IconButton>
                                    <IconButton
                                        v-if="canManageOlt && olt.cli_transport === 'telnet'"
                                        variant="primary"
                                        :title="$t('common.telnet_to_olt')"
                                        @click="openTelnet(olt)"
                                    >
                                        <Terminal class="h-4 w-4" />
                                    </IconButton>
                                    <IconButton v-if="canDeleteOlt(olt)" variant="danger" :title="$t('smartolt.confirm_delete_title')" @click="destroyOlt(olt)">
                                        <Trash2 class="h-4 w-4" />
                                    </IconButton>
                                </div>
                            </article>
                        </div>

                        <div class="kv-table-desktop">
                        <table class="w-full min-w-[720px]">
                            <thead>
                                <tr class="border-b border-white/10 bg-slate-950/40">
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $t('smartolt.th_olt') }}</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $t('smartolt.col_snmp') }}</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $t('smartolt.th_driver') }}</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $t('smartolt.th_last_test') }}</th>
                                    <th class="px-4 py-3.5 text-center text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $t('smartolt.th_actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/5">
                                <tr
                                    v-for="olt in olts"
                                    :key="olt.id"
                                    class="transition-colors duration-150 hover:bg-white/[0.03]"
                                >
                                    <td class="px-4 py-4">
                                        <div class="flex items-center gap-1.5 font-medium text-white">
                                            {{ olt.name }}
                                            <span v-if="olt.is_private" class="inline-flex items-center rounded-full bg-fuchsia-500/15 px-2 py-0.5 text-[10px] font-medium text-fuchsia-200 ring-1 ring-fuchsia-400/30" :title="$t('common.private_hint')">{{ $t('common.private') }}</span>
                                        </div>
                                        <div class="mt-0.5 text-xs text-slate-500">{{ olt.vendor || $t('smartolt.vendor_empty') }}</div>
                                    </td>
                                    <td class="px-4 py-4">
                                        <div class="font-mono text-xs text-slate-300">{{ olt.ip }}:{{ olt.snmp_port }}</div>
                                        <div class="mt-0.5 text-xs uppercase tracking-widest text-slate-500">{{ olt.snmp_version }}</div>
                                    </td>
                                    <td class="px-4 py-4">
                                        <div class="space-y-1.5">
                                            <span
                                                :class="olt.driver === 'zte' ? 'kv-pill-info' : 'kv-pill-muted'"
                                            >
                                                {{ olt.capabilities.vendor_family }}
                                            </span>
                                            <div
                                                class="flex items-center gap-1.5 text-xs"
                                                :class="olt.polling_enabled ? 'text-emerald-400' : 'text-slate-500'"
                                            >
                                                <span
                                                    class="h-1.5 w-1.5 rounded-full"
                                                    :class="olt.polling_enabled ? 'bg-emerald-400' : 'bg-slate-600'"
                                                ></span>
                                                {{ $t('smartolt.col_autopoll') }}: {{ olt.polling_enabled ? $t('common.on') : $t('common.off') }}
                                            </div>
                                            <div
                                                class="flex items-center gap-1.5 text-xs"
                                                :class="olt.alarms_enabled ? 'text-emerald-400' : 'text-amber-400'"
                                            >
                                                <span
                                                    class="h-1.5 w-1.5 rounded-full"
                                                    :class="olt.alarms_enabled ? 'bg-emerald-400' : 'bg-amber-400'"
                                                ></span>
                                                {{ $t('smartolt.col_alarm') }}: {{ olt.alarms_enabled ? $t('common.on') : $t('common.off') }}
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-4">
                                        <div class="flex items-center gap-2">
                                            <span
                                                class="h-2 w-2 rounded-full"
                                                :class="olt.last_test_result?.ok
                                                    ? 'bg-emerald-400'
                                                    : olt.last_test_result
                                                        ? 'bg-red-400'
                                                        : 'bg-slate-600'"
                                            ></span>
                                            <span
                                                class="text-sm font-semibold"
                                                :class="olt.last_test_result?.ok
                                                    ? 'text-emerald-300'
                                                    : olt.last_test_result
                                                        ? 'text-red-300'
                                                        : 'text-slate-500'"
                                            >
                                                {{ olt.last_test_result?.ok ? $t('common.ok') : (olt.last_test_result ? $t('common.failed') : $t('common.not_tested')) }}
                                            </span>
                                        </div>
                                        <div class="mt-1 text-xs text-slate-500">{{ formatDate(olt.last_tested_at) }}</div>
                                    </td>
                                    <td class="px-4 py-4">
                                        <div class="flex justify-center gap-1.5">
                                            <IconButton :href="route('smartolt.detail', olt.id)" :title="$t('common.detail')">
                                                <Eye class="h-4 w-4" />
                                            </IconButton>
                                            <IconButton
                                                v-if="canManageOlt"
                                                :variant="olt.alarms_enabled ? 'success' : 'warning'"
                                                :title="alarmTitle(olt)"
                                                @click="toggleAlarms(olt)"
                                            >
                                                <component :is="olt.alarms_enabled ? BellRing : BellOff" class="h-4 w-4" />
                                            </IconButton>
                                            <IconButton :title="$t('common.test_snmp')" @click="testOlt(olt)">
                                                <RefreshCw class="h-4 w-4" />
                                            </IconButton>
                                            <IconButton :href="route('smartolt.edit', olt.id)" :title="$t('common.edit')">
                                                <Pencil class="h-4 w-4" />
                                            </IconButton>
                                            <IconButton :href="route('smartolt.profiles.index', olt.id)" :title="$t('smartolt.title_profile')">
                                                <Database class="h-4 w-4" />
                                            </IconButton>
                                            <IconButton
                                                v-if="canManageOlt && olt.cli_transport === 'telnet' && olt.capabilities.supports_config_save"
                                                :title="$t('smartolt.title_save_config')"
                                                :disabled="savingId === olt.id"
                                                @click="saveConfig(olt)"
                                            >
                                                <Save class="h-4 w-4" :class="{ 'animate-pulse': savingId === olt.id }" />
                                            </IconButton>
                                            <IconButton
                                                v-if="canManageOlt && olt.cli_transport === 'telnet'"
                                                variant="primary"
                                                :title="$t('common.telnet_to_olt')"
                                                @click="openTelnet(olt)"
                                            >
                                                <Terminal class="h-4 w-4" />
                                            </IconButton>
                                            <IconButton v-if="canDeleteOlt(olt)" variant="danger" :title="$t('smartolt.confirm_delete_title')" @click="destroyOlt(olt)">
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

                <!-- ==================== TAB: OLT C-Data / OLT HiOSO ===================== -->
                <div v-show="isNonZteTab" class="kv-glass-panel">
                    <!-- Card header -->
                    <div class="flex items-center gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                        <span class="kv-circle-sky !h-10 !w-10">
                            <component :is="isHiosoTab ? RadioTower : Server" class="h-5 w-5" />
                        </span>
                        <div>
                            <h3 class="text-base font-semibold text-white">{{ nonZteHeader.title }}</h3>
                            <p class="text-xs text-slate-400">{{ nonZteHeader.subtitle }}</p>
                        </div>
                    </div>

                    <!-- Empty state -->
                    <div v-if="nonZteOlts.length === 0" class="px-6 py-16 text-center">
                        <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-slate-800/60 ring-1 ring-white/10">
                            <component :is="isHiosoTab ? RadioTower : Server" class="h-7 w-7 text-slate-500" />
                        </div>
                        <h3 class="text-sm font-semibold text-slate-200">{{ nonZteEmpty.title }}</h3>
                        <p class="mt-1 text-sm text-slate-500">{{ nonZteEmpty.subtitle }}</p>
                        <div v-if="canAddOlt" class="mt-5">
                            <Link :href="createHref">
                                <PrimaryButton>
                                    <Plus class="mr-2 h-4 w-4" />
                                    {{ $t('smartolt.add_olt') }}
                                </PrimaryButton>
                            </Link>
                        </div>
                    </div>

                    <!-- Table / mobile cards -->
                    <template v-else>
                        <div class="kv-mobile-list">
                            <article v-for="olt in nonZteOlts" :key="olt.id" class="kv-mobile-card">
                                <div class="kv-mobile-card-header">
                                    <div class="min-w-0">
                                        <h4 class="kv-mobile-card-title">
                                            {{ olt.name }}
                                            <span v-if="olt.is_private" class="ml-1.5 inline-flex items-center rounded-full bg-fuchsia-500/15 px-2 py-0.5 text-[10px] font-medium text-fuchsia-200 ring-1 ring-fuchsia-400/30" :title="$t('common.private_hint')">{{ $t('common.private') }}</span>
                                        </h4>
                                        <p class="kv-mobile-card-subtitle">{{ olt.vendor || $t('smartolt.family_empty') }}</p>
                                    </div>
                                    <div class="flex flex-col items-end gap-1">
                                        <span class="kv-pill-info">{{ olt.capabilities.vendor_family }}</span>
                                        <span v-if="firmwareBadge(olt)" class="kv-pill-muted">{{ firmwareBadge(olt) }}</span>
                                    </div>
                                </div>

                                <div class="kv-mobile-fields">
                                    <div class="kv-mobile-field">
                                        <span class="kv-mobile-label">{{ $t('smartolt.col_snmp') }}</span>
                                        <span class="kv-mobile-value font-mono text-xs">{{ olt.ip }}:{{ olt.snmp_port }}</span>
                                    </div>
                                    <div class="kv-mobile-field">
                                        <span class="kv-mobile-label">{{ $t('smartolt.col_version') }}</span>
                                        <span class="kv-mobile-value uppercase tracking-widest">{{ olt.snmp_version }}</span>
                                    </div>
                                    <div class="kv-mobile-field">
                                        <span class="kv-mobile-label">{{ $t('smartolt.col_test') }}</span>
                                        <span
                                            class="kv-mobile-value font-semibold"
                                            :class="olt.last_test_result?.ok
                                                ? 'text-emerald-300'
                                                : olt.last_test_result
                                                    ? 'text-red-300'
                                                    : 'text-slate-500'"
                                        >
                                            {{ olt.last_test_result?.ok ? $t('common.ok') : (olt.last_test_result ? $t('common.failed') : $t('common.not_tested')) }}
                                        </span>
                                    </div>
                                    <div class="kv-mobile-field">
                                        <span class="kv-mobile-label">{{ $t('smartolt.col_last') }}</span>
                                        <span class="kv-mobile-value">{{ formatDate(olt.last_tested_at) }}</span>
                                    </div>
                                    <div class="kv-mobile-field">
                                        <span class="kv-mobile-label">{{ $t('smartolt.col_autopoll') }}</span>
                                        <span class="kv-mobile-value" :class="olt.polling_enabled ? 'text-emerald-400' : 'text-slate-500'">
                                            {{ olt.polling_enabled ? `${$t('common.on')} · ${olt.poll_interval_minutes}m` : $t('common.off') }}
                                        </span>
                                    </div>
                                    <div class="kv-mobile-field">
                                        <span class="kv-mobile-label">{{ $t('smartolt.col_alarm') }}</span>
                                        <span class="kv-mobile-value" :class="olt.alarms_enabled ? 'text-emerald-400' : 'text-amber-400'">
                                            {{ olt.alarms_enabled ? $t('common.on') : $t('common.off') }}
                                        </span>
                                    </div>
                                </div>

                                <div class="mt-4 flex flex-wrap gap-2">
                                    <IconButton :href="nonZteRoute('detail', olt.id)" :title="$t('common.detail')">
                                        <Eye class="h-4 w-4" />
                                    </IconButton>
                                    <IconButton
                                        v-if="canManageOlt"
                                        :variant="olt.alarms_enabled ? 'success' : 'warning'"
                                        :title="alarmTitle(olt)"
                                        @click="toggleAlarms(olt)"
                                    >
                                        <component :is="olt.alarms_enabled ? BellRing : BellOff" class="h-4 w-4" />
                                    </IconButton>
                                    <IconButton :title="$t('common.test_snmp')" @click="testCdataOlt(olt)">
                                        <RefreshCw class="h-4 w-4" />
                                    </IconButton>
                                    <IconButton :href="nonZteRoute('edit', olt.id)" :title="$t('common.edit')">
                                        <Pencil class="h-4 w-4" />
                                    </IconButton>
                                    <IconButton
                                        v-if="canManageOlt && olt.cli_transport === 'telnet' && olt.capabilities.supports_config_save"
                                        :title="$t('smartolt.title_save_config_short')"
                                        :disabled="savingId === olt.id"
                                        @click="saveConfig(olt)"
                                    >
                                        <Save class="h-4 w-4" :class="{ 'animate-pulse': savingId === olt.id }" />
                                    </IconButton>
                                    <IconButton
                                        v-if="canManageOlt && olt.cli_transport === 'telnet'"
                                        variant="primary"
                                        :title="$t('common.telnet_to_olt')"
                                        @click="openTelnet(olt)"
                                    >
                                        <Terminal class="h-4 w-4" />
                                    </IconButton>
                                    <IconButton v-if="canDeleteOlt(olt)" variant="danger" :title="$t('smartolt.confirm_delete_title')" @click="destroyCdataOlt(olt)">
                                        <Trash2 class="h-4 w-4" />
                                    </IconButton>
                                </div>
                            </article>
                        </div>

                        <div class="kv-table-desktop">
                        <table class="w-full min-w-[720px]">
                            <thead>
                                <tr class="border-b border-white/10 bg-slate-950/40">
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $t('smartolt.th_olt') }}</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $t('smartolt.col_snmp') }}</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $t('smartolt.th_family') }}</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $t('smartolt.th_last_test') }}</th>
                                    <th class="px-4 py-3.5 text-center text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $t('smartolt.th_actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/5">
                                <tr
                                    v-for="olt in nonZteOlts"
                                    :key="olt.id"
                                    class="transition-colors duration-150 hover:bg-white/[0.03]"
                                >
                                    <td class="px-4 py-4">
                                        <div class="flex items-center gap-1.5 font-medium text-white">
                                            {{ olt.name }}
                                            <span v-if="olt.is_private" class="inline-flex items-center rounded-full bg-fuchsia-500/15 px-2 py-0.5 text-[10px] font-medium text-fuchsia-200 ring-1 ring-fuchsia-400/30" :title="$t('common.private_hint')">{{ $t('common.private') }}</span>
                                        </div>
                                        <div class="mt-0.5 text-xs text-slate-500">{{ olt.vendor || $t('smartolt.family_empty') }}</div>
                                    </td>
                                    <td class="px-4 py-4">
                                        <div class="font-mono text-xs text-slate-300">{{ olt.ip }}:{{ olt.snmp_port }}</div>
                                        <div class="mt-0.5 text-xs uppercase tracking-widest text-slate-500">{{ olt.snmp_version }}</div>
                                    </td>
                                    <td class="px-4 py-4">
                                        <div class="space-y-1.5">
                                            <div class="flex flex-wrap items-center gap-1.5">
                                                <span class="kv-pill-info">{{ olt.capabilities.vendor_family }}</span>
                                                <span v-if="firmwareBadge(olt)" class="kv-pill-muted">{{ firmwareBadge(olt) }}</span>
                                            </div>
                                            <div
                                                class="flex items-center gap-1.5 text-xs"
                                                :class="olt.polling_enabled ? 'text-emerald-400' : 'text-slate-500'"
                                            >
                                                <span
                                                    class="h-1.5 w-1.5 rounded-full"
                                                    :class="olt.polling_enabled ? 'bg-emerald-400' : 'bg-slate-600'"
                                                ></span>
                                                {{ $t('smartolt.col_autopoll') }}: {{ olt.polling_enabled ? `${$t('common.on')} · ${olt.poll_interval_minutes}m` : $t('common.off') }}
                                            </div>
                                            <div
                                                class="flex items-center gap-1.5 text-xs"
                                                :class="olt.alarms_enabled ? 'text-emerald-400' : 'text-amber-400'"
                                            >
                                                <span
                                                    class="h-1.5 w-1.5 rounded-full"
                                                    :class="olt.alarms_enabled ? 'bg-emerald-400' : 'bg-amber-400'"
                                                ></span>
                                                {{ $t('smartolt.col_alarm') }}: {{ olt.alarms_enabled ? $t('common.on') : $t('common.off') }}
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-4">
                                        <div class="flex items-center gap-2">
                                            <span
                                                class="h-2 w-2 rounded-full"
                                                :class="olt.last_test_result?.ok
                                                    ? 'bg-emerald-400'
                                                    : olt.last_test_result
                                                        ? 'bg-red-400'
                                                        : 'bg-slate-600'"
                                            ></span>
                                            <span
                                                class="text-sm font-semibold"
                                                :class="olt.last_test_result?.ok
                                                    ? 'text-emerald-300'
                                                    : olt.last_test_result
                                                        ? 'text-red-300'
                                                        : 'text-slate-500'"
                                            >
                                                {{ olt.last_test_result?.ok ? $t('common.ok') : (olt.last_test_result ? $t('common.failed') : $t('common.not_tested')) }}
                                            </span>
                                        </div>
                                        <div class="mt-1 text-xs text-slate-500">{{ formatDate(olt.last_tested_at) }}</div>
                                    </td>
                                    <td class="px-4 py-4">
                                        <div class="flex justify-center gap-1.5">
                                            <IconButton :href="nonZteRoute('detail', olt.id)" :title="$t('common.detail')">
                                                <Eye class="h-4 w-4" />
                                            </IconButton>
                                            <IconButton
                                                v-if="canManageOlt"
                                                :variant="olt.alarms_enabled ? 'success' : 'warning'"
                                                :title="alarmTitle(olt)"
                                                @click="toggleAlarms(olt)"
                                            >
                                                <component :is="olt.alarms_enabled ? BellRing : BellOff" class="h-4 w-4" />
                                            </IconButton>
                                            <IconButton :title="$t('common.test_snmp')" @click="testCdataOlt(olt)">
                                                <RefreshCw class="h-4 w-4" />
                                            </IconButton>
                                            <IconButton :href="nonZteRoute('edit', olt.id)" :title="$t('common.edit')">
                                                <Pencil class="h-4 w-4" />
                                            </IconButton>
                                            <IconButton
                                                v-if="canManageOlt && olt.cli_transport === 'telnet' && olt.capabilities.supports_config_save"
                                                :title="$t('smartolt.title_save_config_short')"
                                                :disabled="savingId === olt.id"
                                                @click="saveConfig(olt)"
                                            >
                                                <Save class="h-4 w-4" :class="{ 'animate-pulse': savingId === olt.id }" />
                                            </IconButton>
                                            <IconButton
                                                v-if="canManageOlt && olt.cli_transport === 'telnet'"
                                                variant="primary"
                                                :title="$t('common.telnet_to_olt')"
                                                @click="openTelnet(olt)"
                                            >
                                                <Terminal class="h-4 w-4" />
                                            </IconButton>
                                            <IconButton v-if="canDeleteOlt(olt)" variant="danger" :title="$t('smartolt.confirm_delete_title')" @click="destroyCdataOlt(olt)">
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

        <ConfirmModal :state="confirmState" @confirm="handleConfirm" @cancel="handleCancel" />
        <TelnetWindow v-if="telnetOlt" :olt="telnetOlt" @close="telnetOlt = null" />
    </AuthenticatedLayout>
</template>
