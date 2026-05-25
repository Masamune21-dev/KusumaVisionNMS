<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    olt: {
        type: Object,
        required: true,
    },
    defaults: {
        type: Object,
        required: true,
    },
});

const form = useForm({ ...props.defaults });

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
                            <TextInput id="onu_type" v-model="form.onu_type" class="mt-1 block w-full" required />
                            <InputError class="mt-2" :message="form.errors.onu_type" />
                        </div>
                        <div>
                            <InputLabel for="tcont_profile" value="TCONT Profile" />
                            <TextInput id="tcont_profile" v-model="form.tcont_profile" class="mt-1 block w-full" required />
                            <InputError class="mt-2" :message="form.errors.tcont_profile" />
                        </div>
                        <div>
                            <InputLabel for="vlan" value="VLAN" />
                            <TextInput id="vlan" v-model="form.vlan" type="number" class="mt-1 block w-full" required />
                            <InputError class="mt-2" :message="form.errors.vlan" />
                        </div>
                        <div>
                            <InputLabel for="vlan_profile" value="VLAN Profile" />
                            <TextInput id="vlan_profile" v-model="form.vlan_profile" class="mt-1 block w-full" />
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
                            <TextInput id="ip_profile" v-model="form.ip_profile" class="mt-1 block w-full" />
                            <InputError class="mt-2" :message="form.errors.ip_profile" />
                        </div>
                        <div>
                            <InputLabel for="static_ip" value="Static IP" />
                            <TextInput id="static_ip" v-model="form.static_ip" class="mt-1 block w-full" />
                            <InputError class="mt-2" :message="form.errors.static_ip" />
                        </div>
                        <div>
                            <InputLabel for="static_netmask" value="Netmask" />
                            <TextInput id="static_netmask" v-model="form.static_netmask" class="mt-1 block w-full" />
                            <InputError class="mt-2" :message="form.errors.static_netmask" />
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
