<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    olt: {
        type: Object,
        default: null,
    },
    defaults: {
        type: Object,
        default: () => ({}),
    },
    submitLabel: {
        type: String,
        required: true,
    },
});

const form = useForm({
    name: props.olt?.name ?? '',
    vendor: props.olt?.vendor ?? '',
    ip: props.olt?.ip ?? '',
    snmp_port: props.olt?.snmp_port ?? props.defaults.snmp_port ?? 161,
    snmp_version: props.olt?.snmp_version ?? props.defaults.snmp_version ?? 'v2c',
    snmp_read_community: '',
    snmp_write_community: '',
    cli_transport: props.olt?.cli_transport ?? props.defaults.cli_transport ?? '',
    cli_port: props.olt?.cli_port ?? props.defaults.cli_port ?? '',
    cli_username: props.olt?.cli_username ?? '',
    cli_password: '',
});

const submit = () => {
    if (props.olt) {
        form.put(route('smartolt.update', props.olt.id), {
            preserveScroll: true,
        });

        return;
    }

    form.post(route('smartolt.store'), {
        preserveScroll: true,
    });
};
</script>

<template>
    <form class="space-y-8" @submit.prevent="submit">
        <div class="grid gap-6 md:grid-cols-2">
            <div>
                <InputLabel for="name" value="Nama OLT" />
                <TextInput
                    id="name"
                    v-model="form.name"
                    class="mt-1 block w-full"
                    autocomplete="off"
                    required
                />
                <InputError class="mt-2" :message="form.errors.name" />
            </div>

            <div>
                <InputLabel for="vendor" value="Vendor" />
                <TextInput
                    id="vendor"
                    v-model="form.vendor"
                    class="mt-1 block w-full"
                    autocomplete="off"
                    placeholder="ZTE C320"
                />
                <InputError class="mt-2" :message="form.errors.vendor" />
            </div>

            <div>
                <InputLabel for="ip" value="IP Address" />
                <TextInput
                    id="ip"
                    v-model="form.ip"
                    class="mt-1 block w-full"
                    autocomplete="off"
                    required
                />
                <InputError class="mt-2" :message="form.errors.ip" />
            </div>

            <div>
                <InputLabel for="snmp_port" value="SNMP Port" />
                <TextInput
                    id="snmp_port"
                    v-model="form.snmp_port"
                    class="mt-1 block w-full"
                    type="number"
                    min="1"
                    max="65535"
                    required
                />
                <InputError class="mt-2" :message="form.errors.snmp_port" />
            </div>

            <div>
                <InputLabel for="snmp_version" value="SNMP Version" />
                <select
                    id="snmp_version"
                    v-model="form.snmp_version"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    required
                >
                    <option value="v1">v1</option>
                    <option value="v2c">v2c</option>
                    <option value="v3">v3</option>
                </select>
                <InputError class="mt-2" :message="form.errors.snmp_version" />
            </div>

            <div>
                <InputLabel for="snmp_read_community" value="SNMP Read Community" />
                <TextInput
                    id="snmp_read_community"
                    v-model="form.snmp_read_community"
                    class="mt-1 block w-full"
                    autocomplete="new-password"
                    :required="!olt"
                    type="password"
                />
                <p v-if="olt" class="mt-1 text-xs text-gray-500">
                    Kosongkan untuk mempertahankan community lama.
                </p>
                <InputError class="mt-2" :message="form.errors.snmp_read_community" />
            </div>

            <div>
                <InputLabel for="snmp_write_community" value="SNMP Write Community" />
                <TextInput
                    id="snmp_write_community"
                    v-model="form.snmp_write_community"
                    class="mt-1 block w-full"
                    autocomplete="new-password"
                    type="password"
                />
                <InputError class="mt-2" :message="form.errors.snmp_write_community" />
            </div>

            <div>
                <InputLabel for="cli_transport" value="CLI Transport" />
                <select
                    id="cli_transport"
                    v-model="form.cli_transport"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                >
                    <option value="">Belum dipakai</option>
                    <option value="telnet">Telnet</option>
                    <option value="ssh">SSH</option>
                </select>
                <InputError class="mt-2" :message="form.errors.cli_transport" />
            </div>

            <div>
                <InputLabel for="cli_port" value="CLI Port" />
                <TextInput
                    id="cli_port"
                    v-model="form.cli_port"
                    class="mt-1 block w-full"
                    type="number"
                    min="1"
                    max="65535"
                    placeholder="23 / 22"
                />
                <InputError class="mt-2" :message="form.errors.cli_port" />
            </div>

            <div>
                <InputLabel for="cli_username" value="CLI Username" />
                <TextInput
                    id="cli_username"
                    v-model="form.cli_username"
                    class="mt-1 block w-full"
                    autocomplete="off"
                />
                <InputError class="mt-2" :message="form.errors.cli_username" />
            </div>

            <div>
                <InputLabel for="cli_password" value="CLI Password" />
                <TextInput
                    id="cli_password"
                    v-model="form.cli_password"
                    class="mt-1 block w-full"
                    autocomplete="new-password"
                    type="password"
                />
                <p v-if="olt" class="mt-1 text-xs text-gray-500">
                    Kosongkan untuk mempertahankan password lama.
                </p>
                <InputError class="mt-2" :message="form.errors.cli_password" />
            </div>
        </div>

        <div class="flex items-center justify-end gap-3">
            <Link :href="route('smartolt.index')">
                <SecondaryButton type="button">Batal</SecondaryButton>
            </Link>
            <PrimaryButton :disabled="form.processing">
                {{ submitLabel }}
            </PrimaryButton>
        </div>
    </form>
</template>
