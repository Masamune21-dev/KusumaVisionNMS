<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { AlertTriangle, BellRing, Check, CheckCheck, Loader2 } from '@lucide/vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n({ useScope: 'global' });

const page = usePage();
const open = ref(false);
const dropdownRef = ref(null);

// Id de la alarma que se está abriendo (bloquea doble clic y muestra spinner en su fila).
const openingId = ref(null);
// Aviso cuando el servidor no pudo resolver un destino (ONU borrada, posición reusada, etc.).
const notice = ref(null);
// Ids marcados como leídos LOCALMENTE mientras el servidor confirma. Se revierten si el
// POST falla: en enlaces lentos (típico de campo) el operador necesita respuesta inmediata,
// pero nunca debe quedarse creyendo que se guardó algo que falló.
const optimisticRead = ref(new Set());

const serverItems = computed(() => page.props.notifications?.items ?? []);

const notifications = computed(() => serverItems.value.filter(
    (n) => !optimisticRead.value.has(n.id),
));

const unreadCount = computed(() => {
    const base = page.props.notifications?.unread_count ?? 0;
    // Descontar los que marcamos localmente y el servidor todavía reportaba sin leer.
    const pending = serverItems.value.filter(
        (n) => !n.is_read && optimisticRead.value.has(n.id),
    ).length;

    return Math.max(0, base - pending);
});

const setOptimistic = (id, on) => {
    const next = new Set(optimisticRead.value);
    on ? next.add(id) : next.delete(id);
    optimisticRead.value = next;
};

const severityClass = (severity) => ({
    critical: 'kv-pill-critical',
    major: 'kv-pill-major',
    minor: 'kv-pill-minor',
    warning: 'kv-pill-warning',
}[severity] ?? 'kv-pill-muted');

const formatRelative = (iso) => {
    if (!iso) return '';
    const diff = Math.floor((Date.now() - new Date(iso).getTime()) / 1000);
    if (diff < 60) return t('shell.just_now');
    if (diff < 3600) return t('shell.minutes_ago', { n: Math.floor(diff / 60) });
    if (diff < 86400) return t('shell.hours_ago', { n: Math.floor(diff / 3600) });
    return t('shell.days_ago', { n: Math.floor(diff / 86400) });
};

const refreshBell = () => router.reload({ only: ['notifications'] });

/**
 * El DESTINO lo decide el servidor: comprueba permiso en este instante, sigue la ONU si
 * cambió de puerto y se niega a abrir otra ONU que haya reutilizado slot/port/onu_id.
 * Aquí nunca se construye una ruta a partir del texto del mensaje.
 */
const openNotification = async (notif) => {
    if (openingId.value !== null) return;

    openingId.value = notif.id;
    notice.value = null;

    try {
        const { data } = await window.axios.post(
            route('notifications.alarms.open', notif.alarm_id ?? notif.id),
        );

        const target = data?.data?.target_url;

        if (target) {
            open.value = false;
            router.visit(target);
            return;
        }

        // Sin destino: dejamos el panel abierto con el motivo, en lugar de llevar al
        // operador a una pantalla que no esperaba sin explicación.
        notice.value = {
            message: data?.data?.message ?? t('shell.notif_target_unavailable'),
            fallback: data?.data?.fallback_url ?? null,
        };
        refreshBell();
    } catch (error) {
        // 403/404 (permiso revocado entre el render y el clic, alarma borrada): igual hay que
        // dejar una salida — la lista de alarmas — en lugar de un mensaje sin acción.
        notice.value = {
            message: error?.response?.data?.message ?? t('shell.notif_target_unavailable'),
            fallback: route('alarms.index'),
        };
    } finally {
        openingId.value = null;
    }
};

const markRead = async (notif) => {
    if (notif.is_read) return;

    setOptimistic(notif.id, true);
    notice.value = null;

    try {
        await window.axios.post(route('notifications.alarms.read', notif.alarm_id ?? notif.id));
        // Limpiar el override solo DESPUÉS de que lleguen las props nuevas, para que la fila
        // no parpadee de leída a no-leída y otra vez.
        router.reload({
            only: ['notifications'],
            onSuccess: () => setOptimistic(notif.id, false),
        });
    } catch (error) {
        setOptimistic(notif.id, false); // revertir: no se guardó
        notice.value = {
            message: error?.response?.data?.message ?? t('shell.mark_read_failed'),
            fallback: null,
        };
    }
};

const markAllRead = () => {
    router.post(route('notifications.read-all'), {}, {
        preserveScroll: true,
        only: ['notifications'],
        onSuccess: () => (open.value = false),
    });
};

const goFallback = (url) => {
    open.value = false;
    notice.value = null;
    router.visit(url);
};

const onClickOutside = (e) => {
    if (open.value && dropdownRef.value && !dropdownRef.value.contains(e.target)) {
        open.value = false;
        notice.value = null;
    }
};
onMounted(() => document.addEventListener('click', onClickOutside));
onUnmounted(() => document.removeEventListener('click', onClickOutside));
</script>

<template>
    <div ref="dropdownRef" class="relative">
        <button
            type="button"
            class="relative flex h-11 w-11 items-center justify-center rounded-xl border border-white/10 bg-slate-900/60 text-slate-300 transition-colors hover:border-cyan-500/30 hover:bg-slate-900/80 hover:text-white"
            :aria-label="$t('shell.notifications_title')"
            @click.stop="open = !open"
        >
            <BellRing class="h-5 w-5" />
            <span
                v-if="unreadCount > 0"
                class="absolute -right-1 -top-1 flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white ring-2 ring-slate-950"
            >
                {{ unreadCount > 99 ? '99+' : unreadCount }}
            </span>
        </button>

        <Transition
            enter-active-class="transition duration-150 ease-out"
            enter-from-class="opacity-0 translate-y-1"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition duration-100 ease-in"
            leave-from-class="opacity-100 translate-y-0"
            leave-to-class="opacity-0 translate-y-1"
        >
            <div
                v-if="open"
                class="absolute right-0 z-50 mt-2 w-96 max-w-[calc(100vw-2rem)] origin-top-right rounded-2xl border border-white/10 bg-slate-900/95 shadow-2xl shadow-black/60 backdrop-blur-xl"
            >
                <div class="flex items-center justify-between border-b border-white/10 px-4 py-3">
                    <h3 class="text-sm font-semibold text-white">{{ $t('shell.notifications_title') }}</h3>
                    <button
                        v-if="unreadCount > 0"
                        type="button"
                        class="flex items-center gap-1.5 text-xs font-medium text-cyan-400 transition-colors hover:text-cyan-300"
                        @click="markAllRead"
                    >
                        <CheckCheck class="h-3.5 w-3.5" />
                        {{ $t('shell.mark_all_read') }}
                    </button>
                </div>

                <div
                    v-if="notice"
                    class="flex items-start gap-2 border-b border-amber-500/20 bg-amber-500/10 px-4 py-3 text-xs text-amber-200"
                >
                    <AlertTriangle class="mt-0.5 h-4 w-4 flex-shrink-0" />
                    <div class="min-w-0 flex-1">
                        <p>{{ notice.message }}</p>
                        <button
                            v-if="notice.fallback"
                            type="button"
                            class="mt-1.5 font-medium text-cyan-300 underline hover:text-cyan-200"
                            @click="goFallback(notice.fallback)"
                        >
                            {{ $t('shell.view_all_alarms') }} &rarr;
                        </button>
                    </div>
                </div>

                <div class="max-h-[400px] overflow-y-auto">
                    <ul v-if="notifications.length > 0" class="divide-y divide-white/5">
                        <li
                            v-for="notif in notifications"
                            :key="notif.id"
                            class="group relative transition-colors hover:bg-white/5"
                            :class="{ 'bg-cyan-500/5': !notif.is_read }"
                        >
                            <!-- Toda la tarjeta es la superficie de navegación. El control
                                 secundario es un botón HERMANO (nunca anidado). -->
                            <button
                                type="button"
                                class="w-full px-4 py-3 pr-11 text-left disabled:opacity-60"
                                :disabled="openingId !== null"
                                :aria-label="$t('shell.open_notification')"
                                @click="openNotification(notif)"
                            >
                                <div class="flex items-start gap-3">
                                    <span :class="severityClass(notif.severity)">{{ notif.severity }}</span>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm text-slate-100">{{ notif.message }}</p>
                                        <p class="mt-0.5 text-xs text-slate-500">
                                            {{ notif.olt_name }} &middot; {{ formatRelative(notif.created_at) }}
                                        </p>
                                    </div>
                                </div>
                            </button>

                            <span
                                v-if="openingId === notif.id"
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-cyan-400"
                            >
                                <Loader2 class="h-4 w-4 animate-spin" />
                            </span>
                            <button
                                v-else-if="!notif.is_read"
                                type="button"
                                class="absolute right-3 top-1/2 -translate-y-1/2 rounded-md p-1 text-slate-500 transition hover:bg-white/10 hover:text-cyan-300"
                                :title="$t('shell.mark_read')"
                                :aria-label="$t('shell.mark_read')"
                                @click.stop="markRead(notif)"
                            >
                                <Check class="h-3.5 w-3.5" />
                            </button>
                        </li>
                    </ul>
                    <div v-else class="px-4 py-10 text-center text-sm text-slate-500">
                        {{ $t('shell.no_notifications') }}
                    </div>
                </div>

                <div class="border-t border-white/10 px-4 py-2.5">
                    <Link
                        :href="route('alarms.index')"
                        class="block text-center text-xs font-medium text-cyan-400 hover:text-cyan-300"
                        @click="open = false"
                    >
                        {{ $t('shell.view_all_alarms') }} &rarr;
                    </Link>
                </div>
            </div>
        </Transition>
    </div>
</template>
