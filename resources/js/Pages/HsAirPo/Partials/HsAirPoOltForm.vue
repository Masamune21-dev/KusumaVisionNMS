<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { Activity, Cpu, KeyRound, Network } from '@lucide/vue';

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
    // HsAirPo/HSGQ satu family (OEM Photon Broadband 12170) — vendor tetap, tak ada pilihan family.
    vendor: props.olt?.vendor ?? props.defaults.vendor ?? 'HsAirPo HSGQ EPON 12170',
    ip: props.olt?.ip ?? '',
    snmp_port: props.olt?.snmp_port ?? props.defaults.snmp_port ?? 161,
    snmp_version: props.olt?.snmp_version ?? props.defaults.snmp_version ?? 'v2c',
    snmp_read_community: '',
    // CLI telnet WAJIB: inventori ONU family ini hanya ada di CLI (SNMP tak punya tabel ONU).
    cli_transport: props.olt?.cli_transport ?? props.defaults.cli_transport ?? 'telnet',
    cli_port: props.olt?.cli_port ?? props.defaults.cli_port ?? 23,
    cli_username: props.olt?.cli_username ?? '',
    cli_password: '',
    polling_enabled: props.olt?.polling_enabled ?? true,
    poll_interval_minutes: props.olt?.poll_interval_minutes ?? props.defaults.poll_interval_minutes ?? 5,
    rx_poll_interval_minutes: props.olt?.rx_poll_interval_minutes ?? props.defaults.rx_poll_interval_minutes ?? 5,
});

const submit = () => {
    if (props.olt) {
        form.put(route('hsairpo-olt.update', props.olt.id), {
            preserveScroll: true,
        });

        return;
    }

    form.post(route('hsairpo-olt.store'), {
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
                    <h3 class="text-sm font-semibold text-white">{{ $t('oltform.section_identity_title') }}</h3>
                    <p class="text-xs text-slate-500">{{ $t('hsairpo.identity_sub') }}</p>
                </div>
            </div>
            <div class="grid gap-5 p-6 md:grid-cols-2">
                <div>
                    <InputLabel for="name" :value="$t('oltform.name')" />
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
                    <InputLabel for="vendor" :value="$t('cdataform.family')" />
                    <TextInput
                        id="vendor"
                        model-value="HsAirPo / HSGQ EPON (12170)"
                        class="mt-1 block w-full opacity-70"
                        disabled
                    />
                    <p class="mt-1 text-xs text-slate-500">
                        {{ $t('hsairpo.family_hint') }}
                    </p>
                </div>

                <div>
                    <InputLabel for="ip" :value="$t('oltform.ip')" />
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
                    <h3 class="text-sm font-semibold text-white">{{ $t('oltform.section_snmp_title') }}</h3>
                    <p class="text-xs text-slate-500">{{ $t('hsairpo.snmp_sub') }}</p>
                </div>
            </div>
            <div class="grid gap-5 p-6 md:grid-cols-2">
                <div>
                    <InputLabel for="snmp_port" :value="$t('oltform.snmp_port')" />
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
                    <InputLabel for="snmp_version" :value="$t('oltform.snmp_version')" />
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
                    <InputLabel for="snmp_read_community" :value="$t('oltform.snmp_read')" />
                    <TextInput
                        id="snmp_read_community"
                        v-model="form.snmp_read_community"
                        class="mt-1 block w-full"
                        autocomplete="new-password"
                        :required="!olt"
                        type="password"
                    />
                    <p v-if="olt" class="mt-1 text-xs text-slate-500">
                        {{ $t('oltform.keep_community') }}
                    </p>
                    <InputError class="mt-2" :message="form.errors.snmp_read_community" />
                </div>
            </div>
        </div>

        <!-- Section: CLI (wajib — sumber tunggal data ONU) -->
        <div class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
            <div class="flex items-center gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                <div class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-sky-500/15 ring-1 ring-cyan-500/30">
                    <KeyRound class="h-4 w-4 text-cyan-400" />
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-white">{{ $t('oltform.section_cli_title') }}</h3>
                    <p class="text-xs text-slate-500">{{ $t('hsairpo.cli_sub') }}</p>
                </div>
            </div>
            <div class="grid gap-5 p-6 md:grid-cols-2">
                <div>
                    <InputLabel for="cli_transport" :value="$t('oltform.cli_transport')" />
                    <select
                        id="cli_transport"
                        v-model="form.cli_transport"
                        class="mt-1 block w-full rounded-md border-white/10 shadow-sm focus:border-cyan-500 focus:ring-cyan-500"
                    >
                        <option value="telnet">Telnet</option>
                    </select>
                    <InputError class="mt-2" :message="form.errors.cli_transport" />
                </div>

                <div>
                    <InputLabel for="cli_port" :value="$t('oltform.cli_port')" />
                    <TextInput
                        id="cli_port"
                        v-model="form.cli_port"
                        class="mt-1 block w-full"
                        type="number"
                        min="1"
                        max="65535"
                        placeholder="23"
                    />
                    <InputError class="mt-2" :message="form.errors.cli_port" />
                </div>

                <div>
                    <InputLabel for="cli_username" :value="$t('oltform.cli_username')" />
                    <TextInput
                        id="cli_username"
                        v-model="form.cli_username"
                        class="mt-1 block w-full"
                        autocomplete="off"
                    />
                    <InputError class="mt-2" :message="form.errors.cli_username" />
                </div>

                <div>
                    <InputLabel for="cli_password" :value="$t('oltform.cli_password')" />
                    <TextInput
                        id="cli_password"
                        v-model="form.cli_password"
                        class="mt-1 block w-full"
                        autocomplete="new-password"
                        type="password"
                    />
                    <p v-if="olt" class="mt-1 text-xs text-slate-500">
                        {{ $t('oltform.keep_password') }}
                    </p>
                    <InputError class="mt-2" :message="form.errors.cli_password" />
                </div>
            </div>
        </div>

        <!-- Section: Polling -->
        <div class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
            <div class="flex items-center gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                <div class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-sky-500/15 ring-1 ring-cyan-500/30">
                    <Activity class="h-4 w-4 text-cyan-400" />
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-white">{{ $t('oltform.section_poll_title') }}</h3>
                    <p class="text-xs text-slate-500">{{ $t('hsairpo.poll_sub') }}</p>
                </div>
            </div>
            <div class="p-6">
                <label class="inline-flex items-center gap-2 text-sm text-slate-200">
                    <input
                        v-model="form.polling_enabled"
                        type="checkbox"
                        class="rounded border-white/10 text-cyan-400 shadow-sm focus:ring-cyan-500"
                    />
                    {{ $t('oltform.enable_autopoll') }}
                </label>
                <div class="mt-4 grid gap-5 md:grid-cols-2">
                    <div>
                        <InputLabel for="poll_interval_minutes" :value="$t('oltform.poll_interval')" />
                        <TextInput
                            id="poll_interval_minutes"
                            v-model="form.poll_interval_minutes"
                            class="mt-1 block w-full"
                            type="number"
                            min="1"
                            max="1440"
                            required
                        />
                        <InputError class="mt-2" :message="form.errors.poll_interval_minutes" />
                    </div>
                    <div>
                        <InputLabel for="rx_poll_interval_minutes" :value="$t('oltform.rx_interval')" />
                        <TextInput
                            id="rx_poll_interval_minutes"
                            v-model="form.rx_poll_interval_minutes"
                            class="mt-1 block w-full"
                            type="number"
                            min="1"
                            max="1440"
                            required
                        />
                        <p class="mt-1 text-xs text-slate-500">{{ $t('hsairpo.rx_pending_hint') }}</p>
                        <InputError class="mt-2" :message="form.errors.rx_poll_interval_minutes" />
                    </div>
                </div>
            </div>
        </div>

        <!-- Submit bar -->
        <div class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl px-4 py-4 sm:px-6 grid gap-2 sm:flex sm:items-center sm:justify-end sm:gap-3">
            <Link :href="route('smartolt.index', { tab: 'hsairpo' })">
                <SecondaryButton type="button">{{ $t('common.cancel') }}</SecondaryButton>
            </Link>
            <PrimaryButton :disabled="form.processing">
                {{ submitLabel }}
            </PrimaryButton>
        </div>
    </form>
</template>
