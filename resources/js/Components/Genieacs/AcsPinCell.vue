<script setup>
import OnuAcsBadge from '@/Components/OnuAcsBadge.vue';
import { usePage } from '@inertiajs/vue3';
import { Link2 } from '@lucide/vue';
import { computed } from 'vue';

/**
 * Isi kolom ACS: penanda terhubung + pintu masuk penyematan manual.
 *
 * Tombolnya muncul di SEMUA baris, bukan hanya yang belum berpasangan — baris
 * yang pasangannya meleset justru yang paling perlu dibetulkan, dan itu hanya
 * bisa dilihat operator dari halaman ini.
 */
const props = defineProps({
    acs: { type: Object, default: null },
    canManage: { type: Boolean, default: false },
});

const page = usePage();
// Katalog ACS milik staf Pusat dan hanya untuk OLT global non-demo (server:
// User::canUseAcsCatalogOn). Halaman yang mengirim `olt.can` memakai flag per-OLT itu;
// selain itu jatuh ke `auth.can.manage_acs` dan server tetap menolak OLT yang tak layak.
const canPin = computed(() => {
    if (!props.canManage) return false;
    const perOlt = page.props.olt?.can?.use_acs_catalog;
    return perOlt === undefined ? Boolean(page.props.auth?.can?.manage_acs) : Boolean(perOlt);
});

const emit = defineEmits(['pin']);

// IP datang dari perangkat pelanggan lewat ACS — hanya IPv4 polos yang dijadikan
// tautan, supaya nilai aneh tak pernah berubah jadi URL ke tempat lain.
const IPV4 = /^(25[0-5]|2[0-4]\d|1?\d?\d)(\.(25[0-5]|2[0-4]\d|1?\d?\d)){3}$/;
const ipHref = (ip) => (IPV4.test(ip ?? '') ? `http://${ip}` : null);
</script>

<template>
    <div class="flex flex-col items-start gap-1">
        <span class="inline-flex items-center gap-1.5">
            <OnuAcsBadge :acs="acs" />

            <!-- Katalog ACS milik staf Pusat: tertutup untuk partner & demo (lihat canPin). -->
            <button
                v-if="canPin"
                type="button"
                class="rounded-md p-1 text-slate-500 transition hover:bg-white/10 hover:text-cyan-300"
                :title="acs ? $t('acspin.action_edit') : $t('acspin.action_link')"
                @click.stop="emit('pin')"
            >
                <Link2 class="h-3.5 w-3.5" />
            </button>
        </span>

        <!-- Identitas dari ACS (secret PPPoE & IP), disegarkan sinkronisasi 15 menit. -->
        <dl v-if="acs && (acs.pppoe_username || acs.ip)" class="space-y-0.5 font-mono text-xs leading-tight">
            <div v-if="acs.pppoe_username" class="flex gap-1.5" :title="$t('acspin.pppoe_title')">
                <dt class="text-slate-500">PPPoE</dt>
                <dd class="break-all text-slate-200">{{ acs.pppoe_username }}</dd>
            </div>
            <div v-if="acs.ip" class="flex gap-1.5" :title="$t('acspin.ip_title')">
                <dt class="text-slate-500">IP</dt>
                <dd>
                    <a
                        v-if="ipHref(acs.ip)"
                        :href="ipHref(acs.ip)"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="rounded text-cyan-300 underline decoration-cyan-500/40 underline-offset-2 hover:text-cyan-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cyan-400/60"
                        :title="$t('acspin.ip_open')"
                        @click.stop
                    >{{ acs.ip }}</a>
                    <span v-else class="text-slate-300">{{ acs.ip }}</span>
                </dd>
            </div>
        </dl>
    </div>
</template>
