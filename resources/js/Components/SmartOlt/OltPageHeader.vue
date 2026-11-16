<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import {
    Archive, Cable, ChevronRight, ClipboardList, Database, LayoutDashboard, Network, Settings2, WifiOff,
} from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * Kepala halaman bersama untuk semua halaman di dalam satu OLT, semua family:
 * jejak (SmartOLT › OLT › port › ONU), judul + subjudul, slot aksi di kanan, lalu bilah tab
 * yang SAMA di setiap halaman family itu — supaya urutan menu tidak lagi berbeda per halaman.
 *
 * `family`: zte (rute `smartolt.*`) · cdata (`cdata-olt.*`) · hioso (`hioso-olt.*`) · hsgq (`hsgq-olt.*`).
 * `crumbs`: tingkat di bawah OLT, mis. [{ label: 'Slot 1 / Port 3', href }, { label: 'ONU 12' }].
 * Tab disembunyikan bila kapabilitas/izin OLT tidak mendukung (nilai yang tak dikirim = tampil).
 */
const props = defineProps({
    olt: { type: Object, required: true },
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },
    crumbs: { type: Array, default: () => [] },
    family: { type: String, default: 'zte' },
});

const { t } = useI18n();
const page = usePage();

const caps = computed(() => props.olt.capabilities ?? {});
const can = computed(() => props.olt.can ?? {});
const provisioning = computed(() => caps.value.supports_provisioning !== false);
// Serializer non-ZTE tak membawa `olt.can`; halaman lamanya menggerbang Edit dengan auth.can.manage_olt.
const canManageOlt = computed(() => page?.props?.auth?.can?.manage_olt !== false);

const PREFIX = { zte: 'smartolt', cdata: 'cdata-olt', hioso: 'hioso-olt', hsgq: 'hsgq-olt' };
const prefix = computed(() => PREFIX[props.family] ?? 'smartolt');
// Tab daftar OLT (SmartOlt/Index.vue `?tab=`) tempat OLT family ini berada.
const indexHref = computed(() => (props.family === 'zte'
    ? route('smartolt.index')
    : route('smartolt.index', { tab: props.family })));
const detailRoute = computed(() => `${prefix.value}.detail`);

const zteTabs = () => [
    { key: 'overview', label: t('oltnav.overview'), icon: LayoutDashboard, route: 'smartolt.detail', match: ['smartolt.detail'] },
    {
        key: 'ports', label: t('oltnav.ports'), icon: Cable, route: 'smartolt.gpon-ports',
        match: ['smartolt.gpon-ports', 'smartolt.port.detail', 'smartolt.port-onus', 'smartolt.onu.detail', 'smartolt.onu.configure'],
    },
    { key: 'unconfigured', label: t('oltnav.unconfigured'), icon: WifiOff, route: 'smartolt.unconfigured', match: ['smartolt.unconfigured', 'smartolt.register'], show: provisioning.value },
    { key: 'registrations', label: t('oltnav.registrations'), icon: ClipboardList, route: 'smartolt.registrations', match: ['smartolt.registrations'], show: provisioning.value },
    { key: 'profiles', label: t('oltnav.profiles'), icon: Database, route: 'smartolt.profiles.index', match: ['smartolt.profiles.index'], show: provisioning.value },
    { key: 'backups', label: t('oltnav.backups'), icon: Archive, route: 'smartolt.config-backups.index', match: ['smartolt.config-backups.index'], show: can.value.read_backups !== false },
    { key: 'settings', label: t('oltnav.settings'), icon: Settings2, route: 'smartolt.edit', match: ['smartolt.edit'] },
];

// C-Data / HiOSO / HSGQ: tanpa provisioning, profil, maupun backup config; VLAN hanya C-Data CLI V3.
const nonZteTabs = (p) => [
    { key: 'overview', label: t('oltnav.overview'), icon: LayoutDashboard, route: `${p}.detail`, match: [`${p}.detail`] },
    {
        key: 'ports', label: t('oltnav.ports_generic', { label: caps.value.pon_label ?? 'PON' }), icon: Cable, route: `${p}.pon-ports`,
        match: [`${p}.pon-ports`, `${p}.port-onus`, `${p}.port.detail`],
    },
    { key: 'vlans', label: t('oltnav.vlans'), icon: Network, route: 'cdata-olt.vlans', match: ['cdata-olt.vlans'], show: p === 'cdata-olt' && !!caps.value.supports_cli_vlan },
    { key: 'settings', label: t('oltnav.settings'), icon: Settings2, route: `${p}.edit`, match: [`${p}.edit`], show: canManageOlt.value },
];

const tabs = computed(() => (props.family === 'zte' ? zteTabs() : nonZteTabs(prefix.value))
    .filter((tab) => tab.show !== false));

// Ziggy `route().current()`; dilindungi supaya tiruan `route` di test (tanpa `.current`) tak memecah render.
const isCurrent = (name) => {
    try { return Boolean(route().current(name)); } catch { return false; }
};
const isActive = (tab) => tab.match.some(isCurrent);

const reachable = computed(() => props.olt.last_test_result?.ok);
</script>

<template>
    <div class="space-y-3">
        <nav :aria-label="$t('oltnav.breadcrumb')" class="flex min-w-0 flex-wrap items-center gap-1 text-xs text-slate-500">
            <Link :href="indexHref" class="rounded hover:text-cyan-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-cyan-400/60">
                {{ $t('nav.smartolt') }}
            </Link>
            <ChevronRight class="h-3.5 w-3.5 shrink-0 text-slate-600" aria-hidden="true" />
            <Link
                :href="route(detailRoute, olt.id)"
                class="inline-flex min-w-0 items-center gap-1.5 rounded hover:text-cyan-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-cyan-400/60"
                :class="crumbs.length ? '' : 'text-slate-300'"
            >
                <span
                    v-if="reachable !== undefined"
                    class="h-1.5 w-1.5 shrink-0 rounded-full"
                    :class="reachable ? 'bg-emerald-400' : 'bg-rose-400'"
                    :title="reachable ? $t('common.online') : $t('common.offline')"
                ></span>
                <span class="truncate">{{ olt.name }}</span>
            </Link>
            <template v-for="(crumb, i) in crumbs" :key="i">
                <ChevronRight class="h-3.5 w-3.5 shrink-0 text-slate-600" aria-hidden="true" />
                <Link
                    v-if="crumb.href && i < crumbs.length - 1"
                    :href="crumb.href"
                    class="rounded hover:text-cyan-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-cyan-400/60"
                >{{ crumb.label }}</Link>
                <span v-else class="text-slate-300" :aria-current="i === crumbs.length - 1 ? 'page' : undefined">{{ crumb.label }}</span>
            </template>
        </nav>

        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div class="min-w-0">
                <h1 class="flex flex-wrap items-center gap-2 text-xl font-semibold text-slate-100">
                    {{ title }}
                    <slot name="badges" />
                </h1>
                <p v-if="subtitle || $slots.subtitle" class="mt-1 text-sm text-slate-400">
                    <slot name="subtitle">{{ subtitle }}</slot>
                </p>
            </div>
            <div
                v-if="$slots.actions"
                class="grid gap-2 [&>a>button]:w-full [&>button]:w-full sm:flex sm:flex-wrap sm:items-center sm:justify-end sm:[&>a>button]:w-auto sm:[&>button]:w-auto"
            >
                <slot name="actions" />
            </div>
        </div>

        <nav
            :aria-label="$t('oltnav.sections')"
            class="-mx-4 -mb-3 flex gap-1 overflow-x-auto px-4 sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8"
        >
            <Link
                v-for="tab in tabs"
                :key="tab.key"
                :href="route(tab.route, olt.id)"
                :aria-current="isActive(tab) ? 'page' : undefined"
                class="inline-flex min-h-11 shrink-0 items-center gap-2 border-b-2 px-3 text-sm font-medium transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-cyan-400/60"
                :class="isActive(tab)
                    ? 'border-cyan-400 text-cyan-200'
                    : 'border-transparent text-slate-400 hover:border-slate-500/60 hover:text-slate-200'"
            >
                <component :is="tab.icon" class="h-4 w-4" />
                {{ tab.label }}
            </Link>
        </nav>
    </div>
</template>
