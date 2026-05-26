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
                    class="flex h-10 w-10 flex-none items-center justify-center rounded-full"
                    :class="state.variant === 'danger' ? 'bg-red-100 text-red-600' : 'bg-amber-100 text-amber-600'"
                >
                    <AlertTriangle class="h-5 w-5" />
                </div>
                <div class="flex-1">
                    <h3 class="text-base font-semibold text-slate-900">{{ state.title }}</h3>
                    <p class="mt-1 whitespace-pre-line text-sm text-slate-600">{{ state.message }}</p>
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
