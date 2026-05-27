<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Cpu, Globe, LayoutList, Settings, User } from '@lucide/vue';
import { computed, watch } from 'vue';

const props = defineProps({
    olt: {
        type: Object,
        required: true,
    },
    defaults: {
        type: Object,
        required: true,
    },
    profiles: {
        type: Object,
        required: true,
    },
});

const form = useForm({ ...props.defaults });
const onuTypeProfiles = computed(() => props.profiles.onu_type ?? []);
const tcontProfiles = computed(() => props.profiles.tcont ?? []);
const vlanProfiles = computed(() => props.profiles.vlan ?? []);
const ipProfiles = computed(() => props.profiles.ip ?? []);

watch(() => form.vlan_profile, (name) => {
    const profile = vlanProfiles.value.find((item) => item.name === name);
    if (!profile) {
        return;
    }

    form.vlan = profile.vlan;
    form.service_name = profile.name;
});

const submit = () => {
    form.post(route('smartolt.register.store', props.olt.id), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Register ONU" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h2 class="text-lg font-semibold leading-tight sm:text-xl text-white">Register ONU</h2>
                <p class="mt-1 text-sm text-slate-500">{{ olt.name }} · generate provisioning script</p>
            </div>
        </template>

        <div class="min-h-[60vh] pt-5 pb-16 sm:pt-8">
            <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                <form class="space-y-5" @submit.prevent="submit">

                    <!-- Section 1: Identitas ONU -->
                    <div class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                        <div class="flex items-center gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                            <div class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-sky-500/15 ring-1 ring-cyan-500/30">
                                <User class="h-4 w-4 text-cyan-400" />
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-white">Identitas ONU</h3>
                                <p class="text-xs text-slate-500">Serial number, posisi port, dan data pelanggan</p>
                            </div>
                        </div>
                        <div class="grid gap-5 p-6 md:grid-cols-3">
                            <div class="md:col-span-3">
                                <InputLabel for="serial_number" value="Serial Number" />
                                <TextInput id="serial_number" v-model="form.serial_number" class="mt-1 block w-full font-mono" required />
                                <InputError class="mt-1.5" :message="form.errors.serial_number" />
                            </div>
                            <div>
                                <InputLabel for="slot" value="Slot" />
                                <TextInput id="slot" v-model="form.slot" type="number" class="mt-1 block w-full" required />
                                <InputError class="mt-1.5" :message="form.errors.slot" />
                            </div>
                            <div>
                                <InputLabel for="port" value="Port" />
                                <TextInput id="port" v-model="form.port" type="number" class="mt-1 block w-full" required />
                                <InputError class="mt-1.5" :message="form.errors.port" />
                            </div>
                            <div>
                                <InputLabel for="onu_id" value="ONU ID" />
                                <TextInput id="onu_id" v-model="form.onu_id" type="number" class="mt-1 block w-full" required />
                                <InputError class="mt-1.5" :message="form.errors.onu_id" />
                            </div>
                            <div class="md:col-span-3">
                                <InputLabel for="customer_name" value="Nama Pelanggan" />
                                <TextInput id="customer_name" v-model="form.customer_name" class="mt-1 block w-full" required />
                                <InputError class="mt-1.5" :message="form.errors.customer_name" />
                            </div>
                        </div>
                    </div>

                    <!-- Section 2: Konfigurasi GPON -->
                    <div class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                        <div class="flex items-center gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                            <div class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-sky-500/15 ring-1 ring-cyan-500/30">
                                <Cpu class="h-4 w-4 text-cyan-400" />
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-white">Konfigurasi GPON</h3>
                                <p class="text-xs text-slate-500">ONU type, TCONT, VLAN, dan service name</p>
                            </div>
                        </div>
                        <div class="grid gap-5 p-6 md:grid-cols-3">
                            <div>
                                <InputLabel for="onu_type" value="ONU Type" />
                                <select
                                    id="onu_type"
                                    v-model="form.onu_type"
                                    class="mt-1 block w-full rounded-md border-white/10 shadow-sm focus:border-cyan-500 focus:ring-cyan-500"
                                    required
                                >
                                    <option v-for="profile in onuTypeProfiles" :key="profile.id" :value="profile.name">
                                        {{ profile.name }}
                                    </option>
                                </select>
                                <InputError class="mt-1.5" :message="form.errors.onu_type" />
                            </div>
                            <div>
                                <InputLabel for="tcont_profile" value="TCONT Profile" />
                                <select
                                    id="tcont_profile"
                                    v-model="form.tcont_profile"
                                    class="mt-1 block w-full rounded-md border-white/10 shadow-sm focus:border-cyan-500 focus:ring-cyan-500"
                                    required
                                >
                                    <option v-for="profile in tcontProfiles" :key="profile.id" :value="profile.name">
                                        {{ profile.name }}
                                    </option>
                                </select>
                                <InputError class="mt-1.5" :message="form.errors.tcont_profile" />
                            </div>
                            <div>
                                <InputLabel for="vlan" value="VLAN ID" />
                                <TextInput id="vlan" v-model="form.vlan" type="number" class="mt-1 block w-full" required />
                                <InputError class="mt-1.5" :message="form.errors.vlan" />
                            </div>
                            <div>
                                <InputLabel for="vlan_profile" value="VLAN Profile" />
                                <select
                                    id="vlan_profile"
                                    v-model="form.vlan_profile"
                                    class="mt-1 block w-full rounded-md border-white/10 shadow-sm focus:border-cyan-500 focus:ring-cyan-500"
                                >
                                    <option value="">Tanpa profile</option>
                                    <option v-for="profile in vlanProfiles" :key="profile.id" :value="profile.name">
                                        {{ profile.name }} · VLAN {{ profile.vlan }}
                                    </option>
                                </select>
                                <InputError class="mt-1.5" :message="form.errors.vlan_profile" />
                            </div>
                            <div class="md:col-span-2">
                                <InputLabel for="service_name" value="Service Name" />
                                <TextInput id="service_name" v-model="form.service_name" class="mt-1 block w-full" required />
                                <InputError class="mt-1.5" :message="form.errors.service_name" />
                            </div>
                        </div>
                    </div>

                    <!-- Section 3: WAN Mode -->
                    <div class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                        <div class="flex items-center gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                            <div class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-sky-500/15 ring-1 ring-cyan-500/30">
                                <Globe class="h-4 w-4 text-cyan-400" />
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-white">WAN Mode</h3>
                                <p class="text-xs text-slate-500">Metode koneksi internet pelanggan</p>
                            </div>
                        </div>
                        <div class="p-6 space-y-5">
                            <!-- WAN mode selector buttons -->
                            <div>
                                <InputLabel value="Mode" />
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <button
                                        v-for="mode in ['pppoe', 'dhcp', 'static']"
                                        :key="mode"
                                        type="button"
                                        class="rounded-lg border px-5 py-2 text-sm font-medium transition-all duration-150 focus:outline-none focus:ring-2 focus:ring-cyan-400 focus:ring-offset-1"
                                        :class="form.wan_mode === mode
                                            ? 'bg-cyan-500 text-white border-cyan-500'
                                            : 'bg-slate-900/40 backdrop-blur-xl border border-white/10 text-slate-200 hover:border-cyan-500/40'"
                                        @click="form.wan_mode = mode"
                                    >
                                        {{ mode.toUpperCase() }}
                                    </button>
                                </div>
                                <InputError class="mt-1.5" :message="form.errors.wan_mode" />
                            </div>

                            <!-- PPPoE fields -->
                            <div v-if="form.wan_mode === 'pppoe'" class="grid gap-5 md:grid-cols-2">
                                <div>
                                    <InputLabel for="pppoe_username" value="PPPoE Username" />
                                    <TextInput id="pppoe_username" v-model="form.pppoe_username" class="mt-1 block w-full" />
                                    <InputError class="mt-1.5" :message="form.errors.pppoe_username" />
                                </div>
                                <div>
                                    <InputLabel for="pppoe_password" value="PPPoE Password" />
                                    <TextInput id="pppoe_password" v-model="form.pppoe_password" class="mt-1 block w-full" type="password" />
                                    <InputError class="mt-1.5" :message="form.errors.pppoe_password" />
                                </div>
                            </div>

                            <!-- DHCP: no extra fields -->
                            <div v-if="form.wan_mode === 'dhcp'" class="rounded-lg border border-white/10 bg-sky-500/15 px-4 py-3 text-sm text-cyan-300">
                                DHCP mode — IP otomatis dari server, tidak ada field tambahan.
                            </div>

                            <!-- Static fields -->
                            <div v-if="form.wan_mode === 'static'" class="grid gap-5 md:grid-cols-3">
                                <div>
                                    <InputLabel for="ip_profile" value="IP Profile" />
                                    <select
                                        id="ip_profile"
                                        v-model="form.ip_profile"
                                        class="mt-1 block w-full rounded-md border-white/10 shadow-sm focus:border-cyan-500 focus:ring-cyan-500"
                                        required
                                    >
                                        <option v-for="profile in ipProfiles" :key="profile.id" :value="profile.name">
                                            {{ profile.name }}
                                        </option>
                                    </select>
                                    <InputError class="mt-1.5" :message="form.errors.ip_profile" />
                                </div>
                                <div>
                                    <InputLabel for="static_ip" value="Static IP" />
                                    <TextInput id="static_ip" v-model="form.static_ip" class="mt-1 block w-full font-mono" />
                                    <InputError class="mt-1.5" :message="form.errors.static_ip" />
                                </div>
                                <div>
                                    <InputLabel for="static_netmask" value="Subnet Prefix (/)" />
                                    <TextInput id="static_netmask" v-model="form.static_netmask" type="number" min="1" max="32" class="mt-1 block w-full" />
                                    <InputError class="mt-1.5" :message="form.errors.static_netmask" />
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Section 4: Fitur Tambahan -->
                    <div class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                        <div class="flex items-center gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                            <div class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-sky-500/15 ring-1 ring-cyan-500/30">
                                <Settings class="h-4 w-4 text-cyan-400" />
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-white">Fitur Tambahan</h3>
                                <p class="text-xs text-slate-500">TR069 remote management dan Remote ONT (opsional)</p>
                            </div>
                        </div>
                        <div class="grid gap-5 p-6 md:grid-cols-2">
                            <!-- TR069 -->
                            <div class="rounded-lg border border-cyan-500/30 bg-slate-900/40 backdrop-blur-xl p-4">
                                <label class="inline-flex cursor-pointer items-center gap-2.5">
                                    <input v-model="form.tr069_enabled" type="checkbox" class="h-4 w-4 rounded border-white/10 text-cyan-400 shadow-sm focus:ring-cyan-500" />
                                    <span class="text-sm font-semibold text-slate-200">TR069</span>
                                </label>
                                <div v-if="form.tr069_enabled" class="mt-4 space-y-4">
                                    <div>
                                        <InputLabel for="acs_url" value="ACS URL" />
                                        <TextInput id="acs_url" v-model="form.acs_url" class="mt-1 block w-full" />
                                        <InputError class="mt-1.5" :message="form.errors.acs_url" />
                                    </div>
                                    <div class="grid gap-4 sm:grid-cols-2">
                                        <div>
                                            <InputLabel for="acs_username" value="Username" />
                                            <TextInput id="acs_username" v-model="form.acs_username" class="mt-1 block w-full" />
                                            <InputError class="mt-1.5" :message="form.errors.acs_username" />
                                        </div>
                                        <div>
                                            <InputLabel for="acs_password" value="Password" />
                                            <TextInput id="acs_password" v-model="form.acs_password" type="password" class="mt-1 block w-full" />
                                            <InputError class="mt-1.5" :message="form.errors.acs_password" />
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Remote ONT -->
                            <div class="rounded-lg border border-cyan-500/30 bg-slate-900/40 backdrop-blur-xl p-4">
                                <label class="inline-flex cursor-pointer items-center gap-2.5">
                                    <input v-model="form.remote_ont_enabled" type="checkbox" class="h-4 w-4 rounded border-white/10 text-cyan-400 shadow-sm focus:ring-cyan-500" />
                                    <span class="text-sm font-semibold text-slate-200">Remote ONT</span>
                                </label>
                                <div v-if="form.remote_ont_enabled" class="mt-4 grid gap-4 sm:grid-cols-3">
                                    <div>
                                        <InputLabel for="remote_ont_id" value="ID" />
                                        <TextInput id="remote_ont_id" v-model="form.remote_ont_id" type="number" min="1" max="16" class="mt-1 block w-full" />
                                        <InputError class="mt-1.5" :message="form.errors.remote_ont_id" />
                                    </div>
                                    <div>
                                        <InputLabel for="remote_ont_mode" value="Mode" />
                                        <select id="remote_ont_mode" v-model="form.remote_ont_mode" class="mt-1 block w-full rounded-md border-white/10 shadow-sm focus:border-cyan-500 focus:ring-cyan-500">
                                            <option value="forward">Forward</option>
                                            <option value="discard">Discard</option>
                                        </select>
                                        <InputError class="mt-1.5" :message="form.errors.remote_ont_mode" />
                                    </div>
                                    <div>
                                        <InputLabel for="remote_ont_protocol" value="Protocol" />
                                        <select id="remote_ont_protocol" v-model="form.remote_ont_protocol" class="mt-1 block w-full rounded-md border-white/10 shadow-sm focus:border-cyan-500 focus:ring-cyan-500">
                                            <option value="web">Web</option>
                                            <option value="telnet">Telnet</option>
                                            <option value="ssh">SSH</option>
                                            <option value="ftp">FTP</option>
                                            <option value="tftp">TFTP</option>
                                            <option value="snmp">SNMP</option>
                                        </select>
                                        <InputError class="mt-1.5" :message="form.errors.remote_ont_protocol" />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <input v-model="form.oid_index" type="hidden" />

                    <!-- Submit bar -->
                    <div class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl px-4 py-4 sm:px-6 grid gap-2 sm:flex sm:items-center sm:justify-end sm:gap-3">
                        <Link :href="route('smartolt.unconfigured-all', { olt_id: olt.id })">
                            <SecondaryButton type="button">Batal</SecondaryButton>
                        </Link>
                        <PrimaryButton :disabled="form.processing">
                            <LayoutList class="mr-2 h-4 w-4" />
                            Generate Script
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
