<script setup>
import DangerButton from '@/Components/DangerButton.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { AlertTriangle } from '@lucide/vue';

defineProps({
    state: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits(['confirm', 'cancel']);
</script>

<template>
    <Modal :show="state.show" max-width="md" @close="emit('cancel')">
        <div class="p-4 sm:p-6">
            <div class="flex items-start gap-4">
                <div
                    class="flex h-10 w-10 flex-none items-center justify-center rounded-full ring-1"
                    :class="state.variant === 'danger' ? 'bg-red-500/15 text-red-400 ring-red-500/30' : 'bg-amber-500/15 text-amber-400 ring-amber-500/30'"
                >
                    <AlertTriangle class="h-5 w-5" />
                </div>
                <div class="flex-1">
                    <h3 class="text-base font-semibold text-white">{{ state.title }}</h3>
                    <p class="mt-1 whitespace-pre-line text-sm text-slate-400">{{ state.message }}</p>
                </div>
            </div>

            <div class="mt-6 grid gap-2 sm:flex sm:justify-end">
                <SecondaryButton type="button" @click="emit('cancel')">
                    {{ state.cancelLabel }}
                </SecondaryButton>
                <DangerButton v-if="state.variant === 'danger'" type="button" @click="emit('confirm')">
                    {{ state.confirmLabel }}
                </DangerButton>
                <PrimaryButton v-else type="button" @click="emit('confirm')">
                    {{ state.confirmLabel }}
                </PrimaryButton>
            </div>
        </div>
    </Modal>
</template>
