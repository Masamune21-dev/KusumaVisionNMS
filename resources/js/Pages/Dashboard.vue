<script setup>
import { computed, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import HeroBanner from '@/Components/Dashboard/HeroBanner.vue';
import StatCard from '@/Components/Dashboard/StatCard.vue';
import PollingTrendCard from '@/Components/Dashboard/PollingTrendCard.vue';
import OnuStatusDonut from '@/Components/Dashboard/OnuStatusDonut.vue';
import OltInventoryList from '@/Components/Dashboard/OltInventoryList.vue';
import RecentAlarmsTable from '@/Components/Dashboard/RecentAlarmsTable.vue';
import ProvisioningTimeline from '@/Components/Dashboard/ProvisioningTimeline.vue';
import RemoteActionsGrid from '@/Components/Dashboard/RemoteActionsGrid.vue';
import OnuQuickActionModal from '@/Components/Dashboard/OnuQuickActionModal.vue';
import { BellRing, Server, Wifi } from '@lucide/vue';

const props = defineProps({
    cards: { type: Object, required: true },
    polling_trend: { type: Object, required: true },
    olt_inventory: { type: Array, default: () => [] },
    olts: { type: Array, default: () => [] },
    recent_alarms: { type: Array, default: () => [] },
    provisioning: { type: Array, default: () => [] },
    range: { type: String, default: '24h' },
});

const page = usePage();

const heroCards = computed(() => [
    {
        label: 'Total OLT',
        value: props.cards.olt.total.toLocaleString('id-ID'),
        icon: Server,
        accent: 'sky',
        sparkline: props.cards.olt.history,
        sublabels: [
            { label: 'Online', value: props.cards.olt.online, color: '#10b981' },
            { label: 'Offline', value: props.cards.olt.offline, color: '#94a3b8' },
        ],
    },
    {
        label: 'Total ONU',
        value: props.cards.onu.total.toLocaleString('id-ID'),
        icon: Wifi,
        accent: 'cyan',
        sparkline: props.cards.onu.history,
        sublabels: [
            { label: 'Online', value: props.cards.onu.online, color: '#10b981' },
            { label: 'Offline', value: props.cards.onu.offline, color: '#ef4444' },
        ],
    },
    {
        label: 'Online ONU',
        value: props.cards.onu.online.toLocaleString('id-ID'),
        icon: Wifi,
        accent: 'emerald',
        sparkline: props.cards.onu.history,
        sublabels: [
            { label: `${props.cards.online_share}% dari total ONU`, value: '', color: '#10b981' },
        ],
    },
    {
        label: 'Active Alarms',
        value: props.cards.alarms.total.toLocaleString('id-ID'),
        icon: BellRing,
        accent: 'purple',
        sparkline: props.cards.alarms.history,
        sublabels: [
            { label: 'Critical', value: props.cards.alarms.critical, color: '#ef4444' },
            { label: 'Major', value: props.cards.alarms.major, color: '#f97316' },
        ],
    },
]);

const lastUpdated = computed(() => props.olts[0]?.last_polled_at ?? null);

const modalOpen = ref(false);
const modalAction = ref(null);
const onSelectAction = (action) => {
    modalAction.value = action;
    modalOpen.value = true;
};
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <div class="space-y-6 px-4 py-6 sm:px-6 lg:px-8">
            <HeroBanner />

            <!-- Stat cards -->
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard
                    v-for="card in heroCards"
                    :key="card.label"
                    :label="card.label"
                    :value="card.value"
                    :icon="card.icon"
                    :accent="card.accent"
                    :sparkline="card.sparkline"
                    :sublabels="card.sublabels"
                />
            </div>

            <!-- Middle row: polling trend + onu donut + olt inventory -->
            <div class="grid gap-6 lg:grid-cols-12">
                <div class="lg:col-span-6">
                    <PollingTrendCard :trend="polling_trend" :range="range" />
                </div>
                <div class="lg:col-span-3 h-full">
                    <OnuStatusDonut :onu="cards.onu" :last-updated="lastUpdated" />
                </div>
                <div class="lg:col-span-3 h-full">
                    <OltInventoryList :items="olt_inventory" />
                </div>
            </div>

            <!-- Bottom row: alarms table + provisioning + remote actions -->
            <div class="grid gap-6 lg:grid-cols-12">
                <div class="lg:col-span-6 h-full">
                    <RecentAlarmsTable :alarms="recent_alarms" />
                </div>
                <div class="lg:col-span-3 h-full">
                    <ProvisioningTimeline :items="provisioning" />
                </div>
                <div class="lg:col-span-3 h-full">
                    <RemoteActionsGrid @select="onSelectAction" />
                </div>
            </div>
        </div>

        <OnuQuickActionModal v-model:open="modalOpen" :action="modalAction" />
    </AuthenticatedLayout>
</template>
