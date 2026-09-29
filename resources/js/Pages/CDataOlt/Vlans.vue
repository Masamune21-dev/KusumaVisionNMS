<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useConfirm } from '@/Composables/useConfirm';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { splitPorts, vlanTypeKey } from '@/lib/cdataPorts';
import { formatDateTime } from '@/lib/datetime';
import { Deferred, Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Network, Plus, RefreshCw, Save, Search, Tags } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n({ useScope: 'global' });
const { confirmState, confirm, handleConfirm, handleCancel } = useConfirm();

const props = defineProps({
    olt: { type: Object, required: true },
    // Port yang boleh di-tag saat membuat VLAN: uplink GE/XGE (+ port PON EPON).
    tag_ports: { type: Array, default: () => [] },
    can_write: { type: Boolean, default: false },
    // Deferred: undefined sampai CLI selesai dibaca.
    vlan_data: { type: Object, default: null },
});

const canSaveConfig = computed(() => props.can_write && Boolean(props.olt.capabilities?.supports_config_save));
const canOpenPort = computed(() => Boolean(props.olt.capabilities?.supports_cli_port_detail));
const portHref = (p) => route('cdata-olt.port.detail', [props.olt.id, p.kind, p.slot, p.port]);
const ponLabel = computed(() => props.olt.capabilities?.pon_label ?? 'PON');
// EPON: port PON Hybrid & TIDAK otomatis ikut VLAN baru → ikut ditawarkan untuk di-tag.
const isEpon = computed(() => ponLabel.value === 'EPON');
const uplinkChoices = computed(() => props.tag_ports.filter((p) => !p.startsWith('epon')));
const ponChoices = computed(() => props.tag_ports.filter((p) => p.startsWith('epon')));

// ── Data VLAN ──────────────────────────────────────────────────────────
const rows = computed(() => (props.vlan_data?.vlans ?? []).map((v) => {
    const tagged = splitPorts(v.tagged);
    const untagged = splitPorts(v.untagged);
    return {
        ...v,
        taggedUplinks: tagged.uplinks,
        untaggedUplinks: untagged.uplinks,
        ponLabel: tagged.ponLabel || untagged.ponLabel,
        onUplink: tagged.uplinks.length > 0,
        isDefault: v.id === 1,
        typeKey: vlanTypeKey(v.type),
    };
}));

const stats = computed(() => {
    const nonDefault = rows.value.filter((r) => !r.isDefault);
    return {
        total: rows.value.length,
        uplink: nonDefault.filter((r) => r.onUplink).length,
        notUplink: nonDefault.filter((r) => !r.onUplink).length,
        l3: rows.value.filter((r) => r.typeKey === 'cdatavlan.type_l3').length,
    };
});

const search = ref('');
const statusFilter = ref('all');
const statusOptions = computed(() => [
    { value: 'all', label: t('cdatavlan.filter_all') },
    { value: 'uplink', label: t('cdatavlan.filter_uplink') },
    { value: 'none', label: t('cdatavlan.filter_not_uplink') },
]);

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    return rows.value.filter((r) => {
        if (statusFilter.value === 'uplink' && !r.onUplink) return false;
        if (statusFilter.value === 'none' && (r.onUplink || r.isDefault)) return false;
        if (!q) return true;
        return String(r.id) === q || String(r.id).startsWith(q) || (r.description ?? '').toLowerCase().includes(q);
    });
});

// ── Muat ulang dari OLT ───────────────────────────────────────────────
const reloading = ref(false);
const reload = () => router.reload({
    only: ['vlan_data'],
    onStart: () => { reloading.value = true; },
    onFinish: () => { reloading.value = false; },
});

// ── Simpan config ─────────────────────────────────────────────────────
const saving = ref(false);
const saveConfig = async () => {
    const ok = await confirm({
        title: t('smartolt.confirm_save_title'),
        message: t('smartolt.confirm_save_msg', { name: props.olt.name }),
        confirmLabel: t('common.save'),
        variant: 'info',
    });
    if (!ok) return;
    router.post(route('cdata-olt.config.save', props.olt.id), {}, {
        preserveScroll: true,
        onStart: () => { saving.value = true; },
        onFinish: () => { saving.value = false; },
    });
};

// ── Tambah VLAN ───────────────────────────────────────────────────────
const form = useForm({ vlan_id: '', description: '', ports: [] });

const allPon = computed({
    get: () => ponChoices.value.length > 0 && ponChoices.value.every((p) => form.ports.includes(p)),
    set: (on) => {
        const rest = form.ports.filter((p) => !ponChoices.value.includes(p));
        form.ports = on ? [...rest, ...ponChoices.value] : rest;
    },
});

// Error validasi per elemen (`ports.0`) ikut ditampilkan di bawah grup port.
const portsError = computed(() => form.errors.ports
    ?? Object.entries(form.errors).find(([key]) => key.startsWith('ports.'))?.[1]);

const submit = async () => {
    if (form.ports.length) {
        const ok = await confirm({
            title: t('cdatavlan.confirm_title', { vlan: form.vlan_id, count: form.ports.length }),
            message: t('cdatavlan.confirm_msg', { vlan: form.vlan_id, ports: form.ports.join(', ') }),
            confirmLabel: t('cdatavlan.confirm_label'),
            variant: 'warning',
        });
        if (!ok) return;
    }
    form.post(route('cdata-olt.vlans.store', props.olt.id), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
};

const fmt = (v) => formatDateTime(v);
</script>

<template>
    <Head :title="$t('cdatavlan.title', { name: olt.name })" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex items-center gap-3">
                    <span class="kv-circle-cyan !h-10 !w-10 flex-shrink-0"><Tags class="h-5 w-5" /></span>
                    <div>
                        <h1 class="text-xl font-semibold text-slate-100">{{ $t('cdatavlan.title', { name: olt.name }) }}</h1>
                        <p class="mt-1 text-sm text-slate-400">{{ $t('cdatavlan.subtitle', { ip: olt.ip }) }}</p>
                    </div>
                </div>

                <div class="grid gap-2 [&>a>button]:w-full [&>button]:w-full sm:flex sm:flex-wrap sm:[&>a>button]:w-auto sm:[&>button]:w-auto">
                    <Link :href="route('cdata-olt.detail', olt.id)">
                        <SecondaryButton type="button">
                            <ArrowLeft class="mr-2 h-4 w-4" />
                            {{ $t('common.back') }}
                        </SecondaryButton>
                    </Link>
                    <SecondaryButton v-if="canSaveConfig" type="button" :disabled="saving" @click="saveConfig">
                        <Save class="mr-2 h-4 w-4" :class="{ 'animate-pulse': saving }" />
                        {{ $t('cdatavlan.save_config') }}
                    </SecondaryButton>
                    <PrimaryButton type="button" :disabled="reloading" @click="reload">
                        <RefreshCw class="mr-2 h-4 w-4" :class="{ 'animate-spin': reloading }" />
                        {{ $t('cdatavlan.reload') }}
                    </PrimaryButton>
                </div>
            </div>
        </template>

        <div class="min-h-[60vh] pt-5 pb-16 sm:pt-8">
            <div class="w-full space-y-5 px-4 sm:px-6 lg:px-8">
                <Deferred data="vlan_data">
                    <template #fallback>
                        <div class="kv-glass-panel flex items-center justify-center gap-3 px-6 py-16 text-sm text-slate-400">
                            <RefreshCw class="h-4 w-4 animate-spin text-cyan-400" />
                            {{ $t('cdatavlan.loading') }}
                        </div>
                    </template>

                    <div class="space-y-5">
                        <div
                            v-if="vlan_data && !vlan_data.ok"
                            class="rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-200"
                            role="alert"
                        >
                            <p>{{ $t('cdatavlan.error_live', { error: vlan_data.error }) }}</p>
                            <p v-if="vlan_data.stale" class="mt-1 text-xs text-rose-200/80">{{ $t('cdatavlan.stale_note', { date: fmt(vlan_data.fetched_at) }) }}</p>
                        </div>

                        <!-- Ringkasan -->
                        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                            <div class="kv-stat">
                                <p class="text-xs uppercase tracking-wider text-slate-500">{{ $t('cdatavlan.stat_total') }}</p>
                                <p class="mt-1 text-2xl font-semibold tabular-nums text-slate-100">{{ stats.total }}</p>
                            </div>
                            <div class="kv-stat">
                                <p class="text-xs uppercase tracking-wider text-slate-500">{{ $t('cdatavlan.stat_uplink') }}</p>
                                <p class="mt-1 text-2xl font-semibold tabular-nums text-emerald-300">{{ stats.uplink }}</p>
                            </div>
                            <div class="kv-stat">
                                <p class="text-xs uppercase tracking-wider text-slate-500">{{ $t('cdatavlan.stat_not_uplink') }}</p>
                                <p class="mt-1 text-2xl font-semibold tabular-nums" :class="stats.notUplink > 0 ? 'text-amber-300' : 'text-slate-300'">{{ stats.notUplink }}</p>
                            </div>
                            <div class="kv-stat">
                                <p class="text-xs uppercase tracking-wider text-slate-500">{{ $t('cdatavlan.stat_l3') }}</p>
                                <p class="mt-1 text-2xl font-semibold tabular-nums text-slate-100">{{ stats.l3 }}</p>
                            </div>
                        </div>

                        <!-- Tambah VLAN -->
                        <div class="kv-glass-panel">
                            <div class="flex items-center gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                                <span class="kv-circle-emerald !h-10 !w-10 flex-shrink-0"><Plus class="h-5 w-5" /></span>
                                <div>
                                    <h3 class="text-base font-semibold text-slate-100">{{ $t('cdatavlan.add_title') }}</h3>
                                    <p class="text-xs text-slate-400">{{ isEpon ? $t('cdatavlan.add_sub_epon') : $t('cdatavlan.add_sub') }}</p>
                                </div>
                            </div>
                            <form v-if="can_write" class="space-y-5 p-4 sm:p-6" @submit.prevent="submit">
                                <div class="grid gap-4 md:grid-cols-[10rem_1fr_auto] md:items-start">
                                    <div>
                                        <InputLabel for="vlan-id" :value="$t('cdatavlan.field_id')" />
                                        <TextInput id="vlan-id" v-model="form.vlan_id" type="number" min="1" max="4094" required class="mt-1 block w-full tabular-nums" placeholder="1-4094" />
                                        <InputError class="mt-1" :message="form.errors.vlan_id" />
                                    </div>
                                    <div>
                                        <InputLabel for="vlan-desc" :value="$t('cdatavlan.field_description')" />
                                        <TextInput id="vlan-desc" v-model="form.description" type="text" maxlength="64" pattern="[A-Za-z0-9._\-]+" class="mt-1 block w-full" />
                                        <p v-if="!form.errors.description" class="mt-1 text-xs text-slate-500">{{ $t('cdatavlan.field_description_hint') }}</p>
                                        <InputError class="mt-1" :message="form.errors.description" />
                                    </div>
                                    <div class="md:pt-6">
                                        <PrimaryButton type="submit" class="w-full justify-center md:w-auto" :disabled="form.processing || !form.vlan_id">
                                            <Plus class="mr-2 h-4 w-4" />
                                            {{ form.processing ? $t('cdatavlan.submitting') : $t('cdatavlan.submit') }}
                                        </PrimaryButton>
                                    </div>
                                </div>

                                <fieldset v-if="tag_ports.length" class="rounded-lg border border-white/10 bg-canvas-3/30 p-4">
                                    <legend class="px-1 text-sm font-medium text-slate-300">{{ $t('cdatavlan.field_ports') }}</legend>
                                    <p class="text-xs text-slate-500">{{ $t('cdatavlan.field_ports_hint') }}</p>

                                    <p class="mt-3 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $t('cdatavlan.group_uplink') }}</p>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        <label
                                            v-for="p in uplinkChoices"
                                            :key="p"
                                            class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-lg border border-white/10 px-3 font-mono text-sm text-slate-200 hover:bg-white/5"
                                        >
                                            <Checkbox v-model:checked="form.ports" :value="p" class="h-4 w-4" />
                                            {{ p }}
                                        </label>
                                    </div>

                                    <template v-if="ponChoices.length">
                                        <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
                                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $t('cdatavlan.group_pon', { label: ponLabel }) }}</p>
                                            <label class="inline-flex min-h-11 cursor-pointer items-center gap-2 text-sm text-slate-300">
                                                <Checkbox v-model:checked="allPon" class="h-4 w-4" />
                                                {{ $t('cdatavlan.all_pon', { label: ponLabel }) }}
                                            </label>
                                        </div>
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            <label
                                                v-for="p in ponChoices"
                                                :key="p"
                                                class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-lg border border-white/10 px-3 font-mono text-sm text-slate-200 hover:bg-white/5"
                                            >
                                                <Checkbox v-model:checked="form.ports" :value="p" class="h-4 w-4" />
                                                {{ p }}
                                            </label>
                                        </div>
                                    </template>
                                    <InputError class="mt-2" :message="portsError" />
                                </fieldset>
                            </form>
                            <p v-else class="px-4 py-4 text-sm text-slate-400 sm:px-6">{{ $t('cdatavlan.read_only_note') }}</p>
                        </div>

                        <!-- Daftar VLAN -->
                        <div class="kv-table-card">
                            <div class="flex flex-col gap-3 border-b border-white/10 px-4 py-4 sm:px-6 lg:flex-row lg:items-center lg:justify-between">
                                <div class="flex items-center gap-3">
                                    <span class="kv-circle-sky !h-10 !w-10 flex-shrink-0"><Network class="h-5 w-5" /></span>
                                    <div>
                                        <h3 class="text-base font-semibold text-slate-100">{{ $t('cdatavlan.list_title') }}</h3>
                                        <p class="text-xs text-slate-400">
                                            {{ $t('cdatavlan.list_sub', { count: filtered.length }) }}
                                            <template v-if="vlan_data?.fetched_at"> · {{ $t('cdatavlan.fetched', { date: fmt(vlan_data.fetched_at) }) }}</template>
                                        </p>
                                    </div>
                                </div>
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                                    <div class="relative w-full sm:w-64">
                                        <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" />
                                        <input v-model="search" type="search" class="kv-filter-control w-full pl-9" :placeholder="$t('cdatavlan.search')" :aria-label="$t('cdatavlan.search')" />
                                    </div>
                                    <select v-model="statusFilter" class="kv-filter-control w-full sm:w-auto" :aria-label="$t('cdatavlan.col_status')">
                                        <option v-for="o in statusOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                                    </select>
                                </div>
                            </div>

                            <p v-if="!filtered.length" class="px-6 py-12 text-center text-sm text-slate-500">{{ $t('cdatavlan.empty') }}</p>

                            <template v-else>
                                <!-- HP -->
                                <div class="kv-mobile-list">
                                    <article v-for="v in filtered" :key="v.id" class="kv-mobile-card">
                                        <div class="kv-mobile-card-header">
                                            <div class="min-w-0">
                                                <h4 class="kv-mobile-card-title font-mono tabular-nums">VLAN {{ v.id }}</h4>
                                                <p class="kv-mobile-card-subtitle">{{ v.description || '—' }} · {{ v.typeKey ? $t(v.typeKey) : v.type }}</p>
                                            </div>
                                            <span v-if="v.isDefault" class="kv-pill-muted">{{ $t('cdatavlan.status_default') }}</span>
                                            <span v-else-if="v.onUplink" class="kv-pill-success">{{ $t('cdatavlan.status_uplink') }}</span>
                                            <span v-else class="kv-pill-warning">{{ $t('cdatavlan.status_not_uplink') }}</span>
                                        </div>
                                        <div class="kv-mobile-field">
                                            <span class="kv-mobile-label">{{ $t('cdatavlan.col_uplink') }}</span>
                                            <span class="kv-mobile-value flex flex-wrap justify-end gap-1">
                                                <template v-if="v.taggedUplinks.length">
                                                    <component
                                                        :is="canOpenPort ? Link : 'span'"
                                                        v-for="p in v.taggedUplinks"
                                                        :key="p.name"
                                                        :href="canOpenPort ? portHref(p) : undefined"
                                                        class="kv-pill-info font-mono"
                                                    >{{ p.name }}</component>
                                                </template>
                                                <template v-else>—</template>
                                            </span>
                                        </div>
                                        <div v-if="v.untaggedUplinks.length" class="kv-mobile-field">
                                            <span class="kv-mobile-label">{{ $t('cdatavlan.col_untagged') }}</span>
                                            <span class="kv-mobile-value font-mono text-xs">{{ v.untaggedUplinks.map((p) => p.name).join(', ') }}</span>
                                        </div>
                                        <div class="kv-mobile-field">
                                            <span class="kv-mobile-label">{{ $t('cdatavlan.col_pon') }}</span>
                                            <span class="kv-mobile-value tabular-nums">{{ v.ponLabel ? `${ponLabel} ${v.ponLabel}` : '—' }}</span>
                                        </div>
                                    </article>
                                </div>

                                <!-- Desktop -->
                                <div class="kv-table-desktop">
                                    <table class="w-full min-w-[860px] text-xs">
                                        <thead>
                                            <tr class="border-b border-white/10 bg-canvas-3/40">
                                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('cdatavlan.col_id') }}</th>
                                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('cdatavlan.col_description') }}</th>
                                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('cdatavlan.col_type') }}</th>
                                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('cdatavlan.col_uplink') }}</th>
                                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('cdatavlan.col_untagged') }}</th>
                                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('cdatavlan.col_pon') }}</th>
                                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('cdatavlan.col_status') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-white/5">
                                            <tr v-for="v in filtered" :key="v.id" class="transition-colors duration-150 hover:bg-white/[0.03]">
                                                <td class="px-4 py-3 font-mono text-sm font-semibold tabular-nums text-slate-100">{{ v.id }}</td>
                                                <td class="px-4 py-3 text-slate-200">{{ v.description || '—' }}</td>
                                                <td class="px-4 py-3">
                                                    <span :class="v.typeKey === 'cdatavlan.type_l3' ? 'kv-pill-info' : 'kv-pill-muted'">{{ v.typeKey ? $t(v.typeKey) : v.type || '—' }}</span>
                                                </td>
                                                <td class="px-4 py-3">
                                                    <div v-if="v.taggedUplinks.length" class="flex flex-wrap gap-1">
                                                        <component
                                                            :is="canOpenPort ? Link : 'span'"
                                                            v-for="p in v.taggedUplinks"
                                                            :key="p.name"
                                                            :href="canOpenPort ? portHref(p) : undefined"
                                                            :title="canOpenPort ? $t('cdatavlan.open_port', { port: p.name }) : undefined"
                                                            class="kv-pill-info font-mono"
                                                        >{{ p.name }}</component>
                                                    </div>
                                                    <span v-else class="text-slate-500">—</span>
                                                </td>
                                                <td class="px-4 py-3 font-mono text-slate-300">{{ v.untaggedUplinks.map((p) => p.name).join(', ') || '—' }}</td>
                                                <td class="px-4 py-3 tabular-nums text-slate-300">{{ v.ponLabel ? `${ponLabel} ${v.ponLabel}` : '—' }}</td>
                                                <td class="px-4 py-3">
                                                    <span v-if="v.isDefault" class="kv-pill-muted">{{ $t('cdatavlan.status_default') }}</span>
                                                    <span v-else-if="v.onUplink" class="kv-pill-success">{{ $t('cdatavlan.status_uplink') }}</span>
                                                    <span v-else class="kv-pill-warning">{{ $t('cdatavlan.status_not_uplink') }}</span>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </template>
                        </div>
                    </div>
                </Deferred>
            </div>
        </div>

        <ConfirmModal :state="confirmState" @confirm="handleConfirm" @cancel="handleCancel" />
    </AuthenticatedLayout>
</template>
