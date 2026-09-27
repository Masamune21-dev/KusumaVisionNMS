<script setup>
/*
 * Modal konfirmasi — tampilan baku (UI_DESIGN_SYSTEM): varian danger /
 * warning / info, lingkaran ikon berwarna varian, Batal lalu tombol varian di kanan
 * bawah (bertumpuk penuh di HP). Escape & klik scrim = batal (ditangani Modal.vue).
 * Dikendalikan composable useConfirm(); `variant` selain tiga nilai itu dibaca warning.
 */
import { computed } from 'vue';
import DangerButton from '@/Components/DangerButton.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { AlertTriangle, Info, Trash2 } from '@lucide/vue';

const props = defineProps({
    state: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits(['confirm', 'cancel']);

const VARIANTS = {
    danger: { icon: Trash2, circle: 'bg-rose-500/15 text-rose-400 ring-rose-500/30' },
    warning: { icon: AlertTriangle, circle: 'bg-amber-500/15 text-amber-400 ring-amber-500/30' },
    info: { icon: Info, circle: 'bg-sky-500/15 text-sky-400 ring-sky-500/30' },
};

const look = computed(() => VARIANTS[props.state.variant] ?? VARIANTS.warning);
</script>

<template>
    <Modal :show="state.show" max-width="md" @close="emit('cancel')">
        <div class="p-5 sm:p-6">
            <div class="flex items-start gap-4">
                <div class="flex h-11 w-11 flex-none items-center justify-center rounded-full ring-1" :class="look.circle">
                    <component :is="look.icon" class="h-5 w-5" />
                </div>
                <div class="min-w-0 flex-1">
                    <h3 class="text-base font-semibold text-slate-100">{{ state.title }}</h3>
                    <p class="mt-1 whitespace-pre-line text-sm text-slate-400">{{ state.message }}</p>
                </div>
            </div>

            <div class="mt-6 flex flex-col-reverse gap-2 border-t border-white/10 pt-4 sm:flex-row sm:justify-end">
                <SecondaryButton type="button" size="sm" class="w-full sm:w-auto" @click="emit('cancel')">
                    {{ state.cancelLabel }}
                </SecondaryButton>
                <DangerButton v-if="state.variant === 'danger'" type="button" size="sm" class="w-full sm:w-auto" @click="emit('confirm')">
                    {{ state.confirmLabel }}
                </DangerButton>
                <PrimaryButton v-else type="button" size="sm" class="w-full sm:w-auto" @click="emit('confirm')">
                    {{ state.confirmLabel }}
                </PrimaryButton>
            </div>
        </div>
    </Modal>
</template>
