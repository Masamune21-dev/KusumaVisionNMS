<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { Cpu, KeyRound, Network } from '@lucide/vue';

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
    vendor: props.olt?.vendor ?? props.defaults.vendor ?? 'C-Data GPON 34592',
    ip: props.olt?.ip ?? '',
    snmp_port: props.olt?.snmp_port ?? props.defaults.snmp_port ?? 161,
    snmp_version: props.olt?.snmp_version ?? props.defaults.snmp_version ?? 'v2c',
    snmp_read_community: '',
    snmp_write_community: '',
    cli_transport: props.olt?.cli_transport ?? props.defaults.cli_transport ?? 'telnet',
    cli_port: props.olt?.cli_port ?? props.defaults.cli_port ?? 23,
    cli_username: props.olt?.cli_username ?? '',
    cli_password: '',
});

const submit = () => {
    if (props.olt) {
        form.put(route('cdata-olt.update', props.olt.id), {
            preserveScroll: true,
        });

        return;
    }

    form.post(route('cdata-olt.store'), {
        preserveScroll: true,
    });
};
</script>

<template>
    <form class="space-y-5" @submit.prevent="submit">
        <!-- Section: Identitas OLT -->
        <div class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
            <div class="flex items-center gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                <div class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-sky-500/15 ring-1 ring-cyan-500/30">
                    <Cpu class="h-4 w-4 text-cyan-400" />
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-white">Identitas OLT</h3>
                    <p class="text-xs text-slate-500">Nama, family C-Data, dan alamat IP perangkat</p>
                </div>
            </div>
            <div class="grid gap-5 p-6 md:grid-cols-2">
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
                    <InputLabel for="vendor" value="Family C-Data" />
                    <select
                        id="vendor"
                        v-model="form.vendor"
                        class="mt-1 block w-full rounded-md border-white/10 shadow-sm focus:border-cyan-500 focus:ring-cyan-500"
                        required
                    >
                        <option value="C-Data EPON 17409">C-Data EPON (17409)</option>
                        <option value="C-Data GPON 34592">C-Data GPON (34592)</option>
                    </select>
                    <p class="mt-1 text-xs text-slate-500">
                        Family menentukan driver. Tombol Test memverifikasi via sysObjectID & mendeteksi firmware V3 (GPON).
                    </p>
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
            </div>
        </div>

        <!-- Section: SNMP -->
        <div class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
            <div class="flex items-center gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                <div class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-sky-500/15 ring-1 ring-cyan-500/30">
                    <Network class="h-4 w-4 text-cyan-400" />
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-white">Konfigurasi SNMP</h3>
                    <p class="text-xs text-slate-500">C-Data hanya mendukung SNMP v1/v2c (port 161 default)</p>
                </div>
            </div>
            <div class="grid gap-5 p-6 md:grid-cols-2">
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
                        class="mt-1 block w-full rounded-md border-white/10 shadow-sm focus:border-cyan-500 focus:ring-cyan-500"
                        required
                    >
                        <option value="v1">v1</option>
                        <option value="v2c">v2c</option>
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
                    <p v-if="olt" class="mt-1 text-xs text-slate-500">
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
                    <p class="mt-1 text-xs text-slate-500">Opsional — write ONU C-Data sering ditolak; aksi nanti lewat CLI.</p>
                    <InputError class="mt-2" :message="form.errors.snmp_write_community" />
                </div>
            </div>
        </div>

        <!-- Section: CLI -->
        <div class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
            <div class="flex items-center gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                <div class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-sky-500/15 ring-1 ring-cyan-500/30">
                    <KeyRound class="h-4 w-4 text-cyan-400" />
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-white">Konfigurasi CLI</h3>
                    <p class="text-xs text-slate-500">Diperlukan untuk inventory GPON FlashV3.x (show ont info all)</p>
                </div>
            </div>
            <div class="grid gap-5 p-6 md:grid-cols-2">
                <div>
                    <InputLabel for="cli_transport" value="CLI Transport" />
                    <select
                        id="cli_transport"
                        v-model="form.cli_transport"
                        class="mt-1 block w-full rounded-md border-white/10 shadow-sm focus:border-cyan-500 focus:ring-cyan-500"
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
                    <p v-if="olt" class="mt-1 text-xs text-slate-500">
                        Kosongkan untuk mempertahankan password lama.
                    </p>
                    <InputError class="mt-2" :message="form.errors.cli_password" />
                </div>
            </div>
        </div>

        <!-- Submit bar -->
        <div class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl px-4 py-4 sm:px-6 grid gap-2 sm:flex sm:items-center sm:justify-end sm:gap-3">
            <Link :href="route('cdata-olt.index')">
                <SecondaryButton type="button">Batal</SecondaryButton>
            </Link>
            <PrimaryButton :disabled="form.processing">
                {{ submitLabel }}
            </PrimaryButton>
        </div>
    </form>
</template>
