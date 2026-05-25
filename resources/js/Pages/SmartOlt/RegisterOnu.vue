<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
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
                <h2 class="text-xl font-semibold leading-tight text-gray-800">Register ONU</h2>
                <p class="mt-1 text-sm text-gray-500">{{ olt.name }} · generate provisioning script</p>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                <form class="space-y-8 rounded-lg bg-white p-6 shadow-sm" @submit.prevent="submit">
                    <div class="grid gap-6 md:grid-cols-3">
                        <div>
                            <InputLabel for="serial_number" value="Serial Number" />
                            <TextInput id="serial_number" v-model="form.serial_number" class="mt-1 block w-full" required />
                            <InputError class="mt-2" :message="form.errors.serial_number" />
                        </div>
                        <div>
                            <InputLabel for="slot" value="Slot" />
                            <TextInput id="slot" v-model="form.slot" type="number" class="mt-1 block w-full" required />
                            <InputError class="mt-2" :message="form.errors.slot" />
                        </div>
                        <div>
                            <InputLabel for="port" value="Port" />
                            <TextInput id="port" v-model="form.port" type="number" class="mt-1 block w-full" required />
                            <InputError class="mt-2" :message="form.errors.port" />
                        </div>
                        <div>
                            <InputLabel for="onu_id" value="ONU ID" />
                            <TextInput id="onu_id" v-model="form.onu_id" type="number" class="mt-1 block w-full" required />
                            <InputError class="mt-2" :message="form.errors.onu_id" />
                        </div>
                        <div class="md:col-span-2">
                            <InputLabel for="customer_name" value="Nama Pelanggan" />
                            <TextInput id="customer_name" v-model="form.customer_name" class="mt-1 block w-full" required />
                            <InputError class="mt-2" :message="form.errors.customer_name" />
                        </div>
                        <div>
                            <InputLabel for="onu_type" value="ONU Type" />
                            <select id="onu_type" v-model="form.onu_type" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" required>
                                <option v-for="profile in onuTypeProfiles" :key="profile.id" :value="profile.name">
                                    {{ profile.name }}
                                </option>
                            </select>
                            <InputError class="mt-2" :message="form.errors.onu_type" />
                        </div>
                        <div>
                            <InputLabel for="tcont_profile" value="TCONT Profile" />
                            <select id="tcont_profile" v-model="form.tcont_profile" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" required>
                                <option v-for="profile in tcontProfiles" :key="profile.id" :value="profile.name">
                                    {{ profile.name }}
                                </option>
                            </select>
                            <InputError class="mt-2" :message="form.errors.tcont_profile" />
                        </div>
                        <div>
                            <InputLabel for="vlan" value="VLAN" />
                            <TextInput id="vlan" v-model="form.vlan" type="number" class="mt-1 block w-full" required />
                            <InputError class="mt-2" :message="form.errors.vlan" />
                        </div>
                        <div>
                            <InputLabel for="vlan_profile" value="VLAN Profile" />
                            <select id="vlan_profile" v-model="form.vlan_profile" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">Tanpa profile</option>
                                <option v-for="profile in vlanProfiles" :key="profile.id" :value="profile.name">
                                    {{ profile.name }} · VLAN {{ profile.vlan }}
                                </option>
                            </select>
                            <InputError class="mt-2" :message="form.errors.vlan_profile" />
                        </div>
                        <div>
                            <InputLabel for="service_name" value="Service Name" />
                            <TextInput id="service_name" v-model="form.service_name" class="mt-1 block w-full" required />
                            <InputError class="mt-2" :message="form.errors.service_name" />
                        </div>
                        <div>
                            <InputLabel for="wan_mode" value="WAN Mode" />
                            <select id="wan_mode" v-model="form.wan_mode" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="pppoe">PPPoE</option>
                                <option value="dhcp">DHCP</option>
                                <option value="static">Static</option>
                            </select>
                            <InputError class="mt-2" :message="form.errors.wan_mode" />
                        </div>
                    </div>

                    <div v-if="form.wan_mode === 'pppoe'" class="grid gap-6 md:grid-cols-2">
                        <div>
                            <InputLabel for="pppoe_username" value="PPPoE Username" />
                            <TextInput id="pppoe_username" v-model="form.pppoe_username" class="mt-1 block w-full" />
                            <InputError class="mt-2" :message="form.errors.pppoe_username" />
                        </div>
                        <div>
                            <InputLabel for="pppoe_password" value="PPPoE Password" />
                            <TextInput id="pppoe_password" v-model="form.pppoe_password" class="mt-1 block w-full" type="password" />
                            <InputError class="mt-2" :message="form.errors.pppoe_password" />
                        </div>
                    </div>

                    <div v-if="form.wan_mode === 'static'" class="grid gap-6 md:grid-cols-3">
                        <div>
                            <InputLabel for="ip_profile" value="IP Profile" />
                            <select id="ip_profile" v-model="form.ip_profile" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" required>
                                <option v-for="profile in ipProfiles" :key="profile.id" :value="profile.name">
                                    {{ profile.name }}
                                </option>
                            </select>
                            <InputError class="mt-2" :message="form.errors.ip_profile" />
                        </div>
                        <div>
                            <InputLabel for="static_ip" value="Static IP" />
                            <TextInput id="static_ip" v-model="form.static_ip" class="mt-1 block w-full" />
                            <InputError class="mt-2" :message="form.errors.static_ip" />
                        </div>
                        <div>
                            <InputLabel for="static_netmask" value="Subnet Prefix" />
                            <TextInput id="static_netmask" v-model="form.static_netmask" type="number" min="1" max="32" class="mt-1 block w-full" />
                            <InputError class="mt-2" :message="form.errors.static_netmask" />
                        </div>
                    </div>

                    <div class="grid gap-6 md:grid-cols-2">
                        <div class="rounded-lg border border-gray-200 p-4">
                            <label class="inline-flex items-center gap-2 text-sm font-medium text-gray-800">
                                <input v-model="form.tr069_enabled" type="checkbox" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
                                TR069
                            </label>

                            <div v-if="form.tr069_enabled" class="mt-4 grid gap-4">
                                <div>
                                    <InputLabel for="acs_url" value="ACS URL" />
                                    <TextInput id="acs_url" v-model="form.acs_url" class="mt-1 block w-full" />
                                    <InputError class="mt-2" :message="form.errors.acs_url" />
                                </div>
                                <div class="grid gap-4 md:grid-cols-2">
                                    <div>
                                        <InputLabel for="acs_username" value="ACS Username" />
                                        <TextInput id="acs_username" v-model="form.acs_username" class="mt-1 block w-full" />
                                        <InputError class="mt-2" :message="form.errors.acs_username" />
                                    </div>
                                    <div>
                                        <InputLabel for="acs_password" value="ACS Password" />
                                        <TextInput id="acs_password" v-model="form.acs_password" type="password" class="mt-1 block w-full" />
                                        <InputError class="mt-2" :message="form.errors.acs_password" />
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="rounded-lg border border-gray-200 p-4">
                            <label class="inline-flex items-center gap-2 text-sm font-medium text-gray-800">
                                <input v-model="form.remote_ont_enabled" type="checkbox" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
                                Remote ONT
                            </label>

                            <div v-if="form.remote_ont_enabled" class="mt-4 grid gap-4 md:grid-cols-3">
                                <div>
                                    <InputLabel for="remote_ont_id" value="ID" />
                                    <TextInput id="remote_ont_id" v-model="form.remote_ont_id" type="number" min="1" max="16" class="mt-1 block w-full" />
                                    <InputError class="mt-2" :message="form.errors.remote_ont_id" />
                                </div>
                                <div>
                                    <InputLabel for="remote_ont_mode" value="Mode" />
                                    <select id="remote_ont_mode" v-model="form.remote_ont_mode" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                        <option value="forward">Forward</option>
                                        <option value="discard">Discard</option>
                                    </select>
                                    <InputError class="mt-2" :message="form.errors.remote_ont_mode" />
                                </div>
                                <div>
                                    <InputLabel for="remote_ont_protocol" value="Protocol" />
                                    <select id="remote_ont_protocol" v-model="form.remote_ont_protocol" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                        <option value="web">Web</option>
                                        <option value="telnet">Telnet</option>
                                        <option value="ssh">SSH</option>
                                        <option value="ftp">FTP</option>
                                        <option value="tftp">TFTP</option>
                                        <option value="snmp">SNMP</option>
                                    </select>
                                    <InputError class="mt-2" :message="form.errors.remote_ont_protocol" />
                                </div>
                            </div>
                        </div>
                    </div>

                    <input v-model="form.oid_index" type="hidden" />

                    <div class="flex items-center justify-end gap-3">
                        <Link :href="route('smartolt.unconfigured', olt.id)">
                            <SecondaryButton type="button">Batal</SecondaryButton>
                        </Link>
                        <PrimaryButton :disabled="form.processing">Generate Script</PrimaryButton>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
