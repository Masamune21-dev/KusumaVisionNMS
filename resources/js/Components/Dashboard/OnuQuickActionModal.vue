<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import axios from 'axios';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { AlertTriangle, Loader2, Search, WifiOff, X } from '@lucide/vue';

const { t } = useI18n({ useScope: 'global' });

const props = defineProps({
    open: { type: Boolean, default: false },
    action: { type: String, default: null },
});
const emit = defineEmits(['update:open', 'success', 'error']);

const actionMeta = computed(() => ({
    reboot:  { title: t('dashboard.modal.reboot_title'),  confirm: t('dashboard.modal.confirm_reboot'),  destructive: true,  available: true,  body: t('dashboard.modal.reboot_body') },
    reset:   { title: t('dashboard.modal.reset_title'),   confirm: t('dashboard.modal.confirm_reset'),   destructive: true,  available: false, body: t('dashboard.modal.reset_body') },
    upgrade: { title: t('dashboard.modal.upgrade_title'), confirm: t('dashboard.modal.confirm_upgrade'), destructive: false, available: false, body: t('dashboard.modal.upgrade_body') },
    enable:  { title: t('dashboard.modal.enable_title'),  confirm: t('dashboard.modal.confirm_enable'),  destructive: false, available: true,  body: t('dashboard.modal.enable_body') },
    disable: { title: t('dashboard.modal.disable_title'), confirm: t('dashboard.modal.confirm_disable'), destructive: true,  available: true,  body: t('dashboard.modal.disable_body') },
    log:     { title: t('dashboard.modal.log_title'),     confirm: t('dashboard.modal.confirm_open'),    destructive: false, available: false, body: t('dashboard.modal.log_body') },
}));

const meta = computed(() => actionMeta.value[props.action] ?? null);

const query = ref('');
const results = ref([]);
const loading = ref(false);
const selected = ref(null);
const submitting = ref(false);
const inputRef = ref(null);
let debounce = null;

const close = () => {
    emit('update:open', false);
    setTimeout(() => {
        query.value = '';
        results.value = [];
        selected.value = null;
    }, 200);
};

watch(() => props.open, async (val) => {
    if (val) {
        await nextTick();
        inputRef.value?.focus();
    }
});

watch(query, (q) => {
    if (debounce) clearTimeout(debounce);
    if (!q || q.length < 2) {
        results.value = [];
        return;
    }
    loading.value = true;
    debounce = setTimeout(async () => {
        try {
            const { data } = await axios.get(route('dashboard.search'), { params: { q } });
            results.value = (data.results ?? []).filter((r) => r.type === 'onu');
        } catch (e) {
            results.value = [];
        } finally {
            loading.value = false;
        }
    }, 200);
});

const parseOnuId = (id) => {
    const parts = String(id).split('-');
    if (parts.length < 4) return null;
    return { oltId: parts[0], slot: parts[1], port: parts[2], onuId: parts[3] };
};

const submit = () => {
    if (!selected.value || !meta.value?.available) return;
    const ids = parseOnuId(selected.value.id);
    if (!ids) return;

    // Family ONU (ZTE / C-Data / HiOSO) menentukan prefix route: smartolt / cdata-olt / hioso-olt.
    const prefix = selected.value.route_prefix ?? 'smartolt';
    const params = { olt: ids.oltId, slot: ids.slot, port: ids.port, onuId: ids.onuId };

    submitting.value = true;

    const onSuccess = () => {
        emit('success', { action: props.action, target: selected.value });
        close();
    };
    const onError = () => {
        emit('error', { action: props.action });
    };
    const finish = () => { submitting.value = false; };

    if (props.action === 'reboot') {
        router.post(
            route(`${prefix}.onu.reboot`, params),
            {},
            { preserveScroll: true, onSuccess, onError, onFinish: finish },
        );
    } else if (props.action === 'enable' || props.action === 'disable') {
        router.post(
            route(`${prefix}.onu.state`, params),
            { active: props.action === 'enable' },
            { preserveScroll: true, onSuccess, onError, onFinish: finish },
        );
    } else {
        finish();
    }
};
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-150 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-100 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="open && meta"
                class="fixed inset-0 z-[110] flex items-start justify-center bg-black/70 px-4 pt-[10vh] backdrop-blur-sm"
                @click.self="close"
            >
                <div class="w-full max-w-md overflow-hidden rounded-2xl border border-white/10 bg-slate-900/95 shadow-2xl shadow-black/60 backdrop-blur-xl">
                    <div class="flex items-start justify-between border-b border-white/10 px-5 py-4">
                        <div>
                            <h3 class="text-base font-semibold text-white">{{ meta.title }}</h3>
                            <p class="mt-0.5 text-xs text-slate-400">{{ meta.body }}</p>
                        </div>
                        <button
                            type="button"
                            class="flex h-7 w-7 items-center justify-center rounded-md text-slate-400 transition-colors hover:bg-white/10 hover:text-white"
                            @click="close"
                        >
                            <X class="h-4 w-4" />
                        </button>
                    </div>

                    <div v-if="!meta.available" class="px-5 py-5">
                        <div class="kv-alert-danger !mb-0">
                            <AlertTriangle class="h-5 w-5 flex-shrink-0" />
                            <span>{{ $t('dashboard.modal.not_implemented') }}</span>
                        </div>
                    </div>

                    <template v-else>
                        <div class="px-5 py-4">
                            <label class="mb-1.5 block text-xs font-medium uppercase tracking-wider text-slate-500">{{ $t('dashboard.modal.search_onu') }}</label>
                            <div class="relative">
                                <Search class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" />
                                <input
                                    ref="inputRef"
                                    v-model="query"
                                    type="text"
                                    :placeholder="$t('dashboard.modal.search_placeholder')"
                                    class="kv-input block w-full pl-9"
                                />
                                <Loader2 v-if="loading" class="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 animate-spin text-cyan-400" />
                            </div>

                            <ul v-if="results.length > 0" class="mt-2 max-h-64 overflow-y-auto rounded-xl border border-white/10 bg-slate-950/40">
                                <li
                                    v-for="item in results"
                                    :key="item.id"
                                    class="flex cursor-pointer items-center gap-3 border-b border-white/5 px-3 py-2.5 transition-colors last:border-0"
                                    :class="selected?.id === item.id ? 'bg-cyan-500/10 text-white' : 'text-slate-300 hover:bg-white/5'"
                                    @click="selected = item"
                                >
                                    <WifiOff class="h-4 w-4 text-slate-500" />
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-medium">{{ item.label }}</p>
                                        <p class="truncate text-xs text-slate-500">{{ item.sublabel }}</p>
                                    </div>
                                </li>
                            </ul>
                            <p v-else-if="query.length >= 2 && !loading" class="mt-3 text-center text-xs text-slate-500">{{ $t('dashboard.modal.no_onu_found') }}</p>
                        </div>

                        <div class="flex items-center justify-end gap-2 border-t border-white/10 bg-slate-950/40 px-5 py-3">
                            <button
                                type="button"
                                class="rounded-lg border border-white/10 bg-slate-900/60 px-4 py-2 text-sm font-medium text-slate-300 transition-colors hover:border-white/20 hover:text-white"
                                @click="close"
                            >
                                {{ $t('dashboard.modal.cancel') }}
                            </button>
                            <button
                                type="button"
                                :disabled="!selected || submitting"
                                class="rounded-lg px-4 py-2 text-sm font-semibold text-white transition-all disabled:cursor-not-allowed disabled:opacity-50"
                                :class="meta.destructive ? 'bg-gradient-to-r from-red-500 to-rose-600 shadow-lg shadow-red-500/30 hover:shadow-red-500/50' : 'bg-gradient-to-r from-cyan-500 to-sky-600 shadow-lg shadow-cyan-500/30 hover:shadow-cyan-500/50'"
                                @click="submit"
                            >
                                <Loader2 v-if="submitting" class="mr-1 inline h-4 w-4 animate-spin" />
                                {{ meta.confirm }}
                            </button>
                        </div>
                    </template>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
