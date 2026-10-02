<script setup>
/*
 * Pembungkus tipis Chart.js — satu-satunya tempat Chart.js dibuat.
 *
 * Chart.js dimuat MALAS lewat import() di onMounted: halaman yang tidak
 * menampilkan satu grafik pun tidak ikut mengunduhnya. Jangan mengimpor
 * 'chart.js' / '@/lib/charts' secara statis di tempat lain, dan jangan sebut
 * keduanya di `manualChunks` vite.config.js (dijaga BundelAsetTest).
 *
 * `data` & `options` dirakit pemanggil di dalam computed() (lihat
 * @/lib/chartOptions); setiap kali salah satunya berubah — termasuk saat tema
 * berganti — grafik di-update di tempat, tanpa dibuat ulang.
 */
import { onBeforeUnmount, onMounted, ref, toRaw, watch } from 'vue';

const props = defineProps({
    type: { type: String, required: true },
    data: { type: Object, required: true },
    options: { type: Object, default: () => ({}) },
    height: { type: [Number, String], default: 240 },
    // Teks untuk pembaca layar; kanvas sendiri tidak terbaca.
    label: { type: String, default: '' },
});

const canvas = ref(null);
let chart = null;
let unmounted = false;

// Chart.js menambal metode array data (push/splice) untuk animasinya sendiri.
// Larik dari props Inertia/reactive() adalah Proxy Vue — salin ke larik polos
// supaya Chart.js tidak menambal Proxy (dan memicu reaktivitas berantai).
const plainData = (data) => ({
    ...data,
    labels: data.labels ? [...toRaw(data.labels)] : undefined,
    datasets: (data.datasets ?? []).map((ds) => ({ ...ds, data: [...toRaw(ds.data ?? [])] })),
});

onMounted(async () => {
    const { Chart } = await import('@/lib/charts');
    if (unmounted || !canvas.value) return;

    chart = new Chart(canvas.value, {
        type: props.type,
        data: plainData(props.data),
        options: props.options,
    });
});

watch(
    () => [props.data, props.options],
    ([data, options]) => {
        if (!chart) return;
        chart.data = plainData(data);
        chart.options = options;
        chart.update();
    },
);

onBeforeUnmount(() => {
    unmounted = true;
    chart?.destroy();
    chart = null;
});
</script>

<template>
    <div class="relative w-full" :style="{ height: typeof height === 'number' ? `${height}px` : height }">
        <canvas ref="canvas" role="img" :aria-label="label || undefined" />
    </div>
</template>
