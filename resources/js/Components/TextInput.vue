<script setup>
import { onMounted, ref } from 'vue';

const model = defineModel({
    type: String,
    required: true,
});

const input = ref(null);

onMounted(() => {
    if (input.value.hasAttribute('autofocus')) {
        input.value.focus();
    }
});

defineExpose({ focus: () => input.value.focus() });
</script>

<template>
    <input
        class="min-h-11 rounded-md border-slate-300 text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-sky-500 focus:ring-sky-500 disabled:bg-slate-50 disabled:text-slate-500"
        v-model="model"
        ref="input"
    />
</template>
