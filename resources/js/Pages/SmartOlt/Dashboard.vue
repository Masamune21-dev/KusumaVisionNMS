<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, CheckCircle2, Layers, Network, RefreshCw, Signal, Wifi, XCircle } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import VueApexCharts from 'vue3-apexcharts';

const props = defineProps({
    olt: { type: Object, required: true },
    cards: { type: Array, default: () => [] },
    uplink_interfaces: { type: Array, default: () => [] },
    vlans_by_interface: { type: Object, default: () => ({}) },
});

const page = usePage();
const flash = computed(() => page.props.flash ?? {});
const toast = reactive({ show: false, ok: true, message: '' });

// ── Interface selector ──────────────────────────────────────────────────
const selectedInterface = ref(props.uplink_interfaces[0]?.interface ?? '');

// ── Traffic chart ───────────────────────────────────────────────────────
const MAX_POINTS = 30;
const trafficHistory = reactive({ labels: [], input: [], output: [] });
const uplinkInfo = reactive({ line_status: null, input_bps: 0, output_bps: 0, input_pps: 0, output_pps: 0 });
const trafficError = ref(null);
let pollTimer = null;

const chartOptions = computed(() => ({
    chart: { type: 'line', animations: { enabled: true, easing: 'linear', dynamicAnimation: { speed: 800 } }, toolbar: { show: false }, zoom: { enabled: false } },
    stroke: { curve: 'smooth', width: 2 },
    colors: ['#3b82f6', '#10b981'],
    xaxis: { categories: trafficHistory.labels, labels: { show: false }, axisTicks: { show: false } },
    yaxis: {
        labels: {
            formatter: (v) => {
                if (v >= 1e6) return (v / 1e6).toFixed(1) + ' MB/s';
                if (v >= 1e3) return (v / 1e3).toFixed(0) + ' KB/s';
                return v + ' B/s';
            },
        },
    },
    tooltip: {
        y: {
            formatter: (v) => {
                if (v >= 1e6) return (v / 1e6).toFixed(2) + ' MB/s';
                if (v >= 1e3) return (v / 1e3).toFixed(1) + ' KB/s';
                return v + ' B/s';
            },
        },
    },
    legend: { position: 'top', horizontalAlign: 'left' },
    grid: { strokeDashArray: 4, borderColor: '#e5e7eb' },
}));

const chartSeries = computed(() => [
    { name: 'Input (RX)', data: [...trafficHistory.input] },
    { name: 'Output (TX)', data: [...trafficHistory.output] },
]);

const fetchTraffic = async () => {
    if (!selectedInterface.value) return;

    try {
        const res = await fetch(route('smartolt.dashboard.traffic', props.olt.id) + '?interface=' + encodeURIComponent(selectedInterface.value), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });

        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const data = await res.json();

        if (data.error) throw new Error(data.error);

        uplinkInfo.line_status = data.line_status;
        uplinkInfo.input_bps = data.input_bps;
        uplinkInfo.output_bps = data.output_bps;
        uplinkInfo.input_pps = data.input_pps;
        uplinkInfo.output_pps = data.output_pps;
        trafficError.value = null;

        const time = new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        trafficHistory.labels.push(time);
        trafficHistory.input.push(data.input_bps);
        trafficHistory.output.push(data.output_bps);

        if (trafficHistory.labels.length > MAX_POINTS) {
            trafficHistory.labels.shift();
            trafficHistory.input.shift();
            trafficHistory.output.shift();
        }
    } catch (e) {
        trafficError.value = e.message;
    }
};

const startPolling = () => {
    clearInterval(pollTimer);
    trafficHistory.labels = [];
    trafficHistory.input = [];
    trafficHistory.output = [];
    uplinkInfo.line_status = null;
    fetchTraffic();
    pollTimer = setInterval(fetchTraffic, 10000);
};

watch(selectedInterface, () => startPolling());

onMounted(() => {
    if (selectedInterface.value) startPolling();
});

onBeforeUnmount(() => clearInterval(pollTimer));

// ── VLAN state (reactive, updated after add) ────────────────────────────
const vlansByInterface = reactive({ ...props.vlans_by_interface });

const currentVlans = computed(() => vlansByInterface[selectedInterface.value] ?? []);

// ── Add VLAN form ───────────────────────────────────────────────────────
const vlanForm = reactive({ interface: selectedInterface.value, vlan_id: '', submitting: false });

watch(selectedInterface, (val) => { vlanForm.interface = val; });

const submitVlan = async () => {
    const vlanId = parseInt(vlanForm.vlan_id);
    if (!vlanForm.interface || isNaN(vlanId) || vlanId < 1 || vlanId > 4094) return;

    vlanForm.submitting = true;
    toast.show = false;

    try {
        const res = await fetch(route('smartolt.dashboard.vlan', props.olt.id), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify({ interface: vlanForm.interface, vlan_id: vlanId }),
        });

        const data = await res.json();
        toast.ok = data.ok;
        toast.message = data.message;
        toast.show = true;

        if (data.ok) {
            vlanForm.vlan_id = '';
            // Refresh VLAN list from server
            const vlanRes = await fetch(route('smartolt.dashboard', props.olt.id), {
                headers: { 'X-Inertia': 'true', 'X-Requested-With': 'XMLHttpRequest' },
            });
            // Simpler: re-fetch via Inertia visit which reloads props
            router.reload({ only: ['vlans_by_interface'], onSuccess: (page) => {
                Object.assign(vlansByInterface, page.props.vlans_by_interface ?? {});
            }});
        }

        setTimeout(() => { toast.show = false; }, 5000);
    } catch (e) {
        toast.ok = false;
        toast.message = 'Request gagal: ' + e.message;
        toast.show = true;
    } finally {
        vlanForm.submitting = false;
    }
};

// ── Refresh page ────────────────────────────────────────────────────────
const refreshing = ref(false);
const doRefresh = () => {
    refreshing.value = true;
    router.post(route('smartolt.dashboard.refresh', props.olt.id), {}, {
        onFinish: () => { refreshing.value = false; },
    });
};

// ── Helpers ─────────────────────────────────────────────────────────────
const statusColor = (status) => {
    const s = String(status ?? '').toUpperCase();
    if (s === 'INSERVICE') return 'text-green-600 bg-green-50';
    if (s === 'STANDBY') return 'text-yellow-600 bg-yellow-50';
    return 'text-red-600 bg-red-50';
};

const formatBps = (bps) => {
    if (bps >= 1e9) return (bps / 1e9).toFixed(2) + ' GB/s';
    if (bps >= 1e6) return (bps / 1e6).toFixed(2) + ' MB/s';
    if (bps >= 1e3) return (bps / 1e3).toFixed(1) + ' KB/s';
    return bps + ' B/s';
};

const vlanBadgeColor = (range) => {
    const n = parseInt(range);
    const colors = [
        'bg-blue-100 text-blue-700',
        'bg-purple-100 text-purple-700',
        'bg-pink-100 text-pink-700',
        'bg-indigo-100 text-indigo-700',
        'bg-teal-100 text-teal-700',
        'bg-orange-100 text-orange-700',
        'bg-cyan-100 text-cyan-700',
        'bg-emerald-100 text-emerald-700',
    ];
    return colors[n % colors.length];
};

const uplinkCardType = (iface) => {
    const found = props.uplink_interfaces.find((u) => u.interface === iface);
    return found ? found.card_type : '';
};
</script>

<template>
    <Head :title="`Dashboard ${olt.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="text-xl font-semibold leading-tight text-gray-800">
                        Dashboard — {{ olt.name }}
                    </h2>
                    <p class="mt-1 text-sm text-gray-500">
                        {{ olt.ip }}:{{ olt.snmp_port }} · {{ olt.capabilities.vendor_family }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Link :href="route('smartolt.detail', olt.id)">
                        <SecondaryButton type="button">
                            <ArrowLeft class="mr-2 h-4 w-4" />
                            Detail OLT
                        </SecondaryButton>
                    </Link>
                    <PrimaryButton type="button" :disabled="refreshing" @click="doRefresh">
                        <RefreshCw class="mr-2 h-4 w-4" :class="{ 'animate-spin': refreshing }" />
                        Refresh Data
                    </PrimaryButton>
                </div>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">

                <!-- Flash messages -->
                <div v-if="flash.success" class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                    {{ flash.success }}
                </div>
                <div v-if="flash.error" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                    {{ flash.error }}
                </div>

                <!-- ══════════════════════════════════════
                     SECTION 1: Panel Status Card
                     ══════════════════════════════════════ -->
                <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                    <div class="flex items-center gap-2 border-b border-gray-100 px-5 py-4">
                        <Layers class="h-5 w-5 text-gray-500" />
                        <h3 class="font-semibold text-gray-800">Status Card / Hardware</h3>
                    </div>

                    <div v-if="cards.length === 0" class="px-5 py-10 text-center text-sm text-gray-400">
                        Tidak ada data card. Klik <strong>Refresh Data</strong> untuk memuat dari OLT.
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-100 bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                                    <th class="px-4 py-3">Rack/Shelf/Slot</th>
                                    <th class="px-4 py-3">Tipe Konfigurasi</th>
                                    <th class="px-4 py-3">Tipe Real</th>
                                    <th class="px-4 py-3">Port</th>
                                    <th class="px-4 py-3">HW Ver</th>
                                    <th class="px-4 py-3">SW Ver</th>
                                    <th class="px-4 py-3">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="card in cards" :key="`${card.rack}-${card.shelf}-${card.slot}`"
                                    class="transition-colors hover:bg-gray-50">
                                    <td class="px-4 py-3 font-mono text-gray-700">
                                        {{ card.rack }}/{{ card.shelf }}/{{ card.slot }}
                                    </td>
                                    <td class="px-4 py-3 font-semibold text-gray-800">{{ card.cfg_type }}</td>
                                    <td class="px-4 py-3 text-gray-600">{{ card.real_type || '—' }}</td>
                                    <td class="px-4 py-3 text-gray-600">{{ card.port_count }}</td>
                                    <td class="px-4 py-3 font-mono text-xs text-gray-500">{{ card.hard_ver || '—' }}</td>
                                    <td class="px-4 py-3 font-mono text-xs text-gray-500">{{ card.soft_ver || '—' }}</td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                              :class="statusColor(card.status)">
                                            {{ card.status }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- ══════════════════════════════════════
                     SECTION 2: Uplink Interface & Trafik
                     ══════════════════════════════════════ -->
                <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                    <div class="flex flex-col gap-3 border-b border-gray-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex items-center gap-2">
                            <Signal class="h-5 w-5 text-gray-500" />
                            <h3 class="font-semibold text-gray-800">Trafik Interface Uplink</h3>
                        </div>

                        <div v-if="uplink_interfaces.length > 0" class="flex items-center gap-2">
                            <label class="text-xs font-medium text-gray-500">Interface:</label>
                            <select v-model="selectedInterface"
                                    class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm shadow-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                <option v-for="iface in uplink_interfaces" :key="iface.interface" :value="iface.interface">
                                    {{ iface.interface }} ({{ iface.card_type }})
                                </option>
                            </select>
                        </div>
                    </div>

                    <div v-if="uplink_interfaces.length === 0" class="px-5 py-10 text-center text-sm text-gray-400">
                        Tidak ada interface uplink terdeteksi. Pastikan card HUVQ/SMXA terpasang dan klik <strong>Refresh Data</strong>.
                    </div>

                    <div v-else class="p-5">
                        <!-- Status indicator -->
                        <div class="mb-5 flex flex-wrap items-center gap-4">
                            <div v-if="uplinkInfo.line_status === null"
                                 class="flex items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-4 py-2">
                                <div class="h-2.5 w-2.5 animate-pulse rounded-full bg-gray-400"></div>
                                <span class="text-sm text-gray-500">Memuat status…</span>
                            </div>

                            <div v-else-if="uplinkInfo.line_status === 'up'"
                                 class="flex items-center gap-2 rounded-lg border border-green-200 bg-green-50 px-4 py-2">
                                <CheckCircle2 class="h-5 w-5 text-green-600" />
                                <span class="font-semibold text-green-700">UP</span>
                            </div>

                            <div v-else-if="uplinkInfo.line_status === 'admin-down'"
                                 class="flex items-center gap-2 rounded-lg border border-yellow-200 bg-yellow-50 px-4 py-2">
                                <XCircle class="h-5 w-5 text-yellow-600" />
                                <span class="font-semibold text-yellow-700">ADMIN DOWN</span>
                            </div>

                            <div v-else
                                 class="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-2">
                                <XCircle class="h-5 w-5 text-red-600" />
                                <span class="font-semibold text-red-700">DOWN</span>
                            </div>

                            <!-- Live stats pills -->
                            <div v-if="uplinkInfo.line_status !== null && uplinkInfo.line_status !== 'admin-down' && uplinkInfo.line_status !== 'down'"
                                 class="flex flex-wrap gap-2">
                                <span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-medium text-blue-700">
                                    ↓ RX {{ formatBps(uplinkInfo.input_bps) }}
                                </span>
                                <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-medium text-emerald-700">
                                    ↑ TX {{ formatBps(uplinkInfo.output_bps) }}
                                </span>
                                <span class="rounded-full bg-gray-100 px-3 py-1 text-xs text-gray-500">
                                    {{ uplinkInfo.input_pps.toLocaleString('id-ID') }} pps in /
                                    {{ uplinkInfo.output_pps.toLocaleString('id-ID') }} pps out
                                </span>
                            </div>
                        </div>

                        <!-- Error banner -->
                        <div v-if="trafficError" class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-xs text-red-700">
                            Gagal ambil data trafik: {{ trafficError }}
                        </div>

                        <!-- Chart -->
                        <VueApexCharts
                            type="line"
                            height="220"
                            :options="chartOptions"
                            :series="chartSeries"
                        />
                        <p class="mt-1 text-right text-xs text-gray-400">Auto-refresh setiap 10 detik · 20-second average</p>
                    </div>
                </div>

                <!-- ══════════════════════════════════════
                     SECTION 3: VLAN Mapping
                     ══════════════════════════════════════ -->
                <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                    <div class="flex items-center gap-2 border-b border-gray-100 px-5 py-4">
                        <Network class="h-5 w-5 text-gray-500" />
                        <h3 class="font-semibold text-gray-800">VLAN Tagged — {{ selectedInterface || '—' }}</h3>
                    </div>

                    <div class="px-5 py-4">
                        <div v-if="!selectedInterface" class="text-sm text-gray-400">
                            Pilih interface uplink terlebih dahulu.
                        </div>
                        <div v-else-if="currentVlans.length === 0" class="text-sm text-gray-400">
                            Tidak ada VLAN tagged pada interface ini, atau klik <strong>Refresh Data</strong> untuk memuat ulang.
                        </div>
                        <div v-else class="flex flex-wrap gap-2">
                            <span
                                v-for="vlan in currentVlans"
                                :key="vlan"
                                class="inline-flex cursor-default items-center rounded-md px-2.5 py-1 text-xs font-semibold"
                                :class="vlanBadgeColor(vlan)"
                            >
                                VLAN {{ vlan }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- ══════════════════════════════════════
                     SECTION 4: Form Tambah & Tag VLAN
                     ══════════════════════════════════════ -->
                <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                    <div class="flex items-center gap-2 border-b border-gray-100 px-5 py-4">
                        <Wifi class="h-5 w-5 text-gray-500" />
                        <h3 class="font-semibold text-gray-800">Tambah & Tag VLAN</h3>
                    </div>

                    <div class="p-5">
                        <!-- Toast notification -->
                        <Transition enter-active-class="transition duration-200 ease-out" enter-from-class="opacity-0 -translate-y-1"
                                    leave-active-class="transition duration-150 ease-in" leave-to-class="opacity-0 -translate-y-1">
                            <div v-if="toast.show"
                                 class="mb-5 flex items-start gap-3 rounded-lg border px-4 py-3 text-sm"
                                 :class="toast.ok
                                     ? 'border-green-200 bg-green-50 text-green-800'
                                     : 'border-red-200 bg-red-50 text-red-800'">
                                <CheckCircle2 v-if="toast.ok" class="mt-0.5 h-4 w-4 shrink-0 text-green-600" />
                                <XCircle v-else class="mt-0.5 h-4 w-4 shrink-0 text-red-600" />
                                {{ toast.message }}
                            </div>
                        </Transition>

                        <div v-if="uplink_interfaces.length === 0" class="text-sm text-gray-400">
                            Tidak ada interface uplink terdeteksi.
                        </div>

                        <div v-else class="flex flex-col gap-4 sm:flex-row sm:items-end">
                            <!-- Interface dropdown -->
                            <div class="flex-1">
                                <label class="mb-1.5 block text-xs font-medium text-gray-600">Port Uplink</label>
                                <select v-model="vlanForm.interface"
                                        class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                    <option v-for="iface in uplink_interfaces" :key="iface.interface" :value="iface.interface">
                                        {{ iface.interface }} ({{ iface.card_type }})
                                    </option>
                                </select>
                            </div>

                            <!-- VLAN ID input -->
                            <div class="w-full sm:w-40">
                                <label class="mb-1.5 block text-xs font-medium text-gray-600">Nomor VLAN (1–4094)</label>
                                <input
                                    v-model.number="vlanForm.vlan_id"
                                    type="number"
                                    min="1"
                                    max="4094"
                                    placeholder="contoh: 500"
                                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-200"
                                    @keydown.enter="submitVlan"
                                />
                            </div>

                            <!-- Submit -->
                            <div class="shrink-0">
                                <PrimaryButton
                                    type="button"
                                    :disabled="vlanForm.submitting || !vlanForm.interface || !vlanForm.vlan_id"
                                    @click="submitVlan"
                                >
                                    <RefreshCw v-if="vlanForm.submitting" class="mr-2 h-4 w-4 animate-spin" />
                                    {{ vlanForm.submitting ? 'Menerapkan…' : 'Terapkan' }}
                                </PrimaryButton>
                            </div>
                        </div>

                        <p class="mt-3 text-xs text-gray-400">
                            Script yang dijalankan: <code class="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-gray-600">configure terminal → vlan {id} → exit → interface {port} → switchport vlan {id} tag → end → write</code>
                        </p>
                    </div>
                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>
