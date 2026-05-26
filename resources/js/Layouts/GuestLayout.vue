<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div
        class="flex min-h-screen flex-col items-center bg-slate-50 pt-6 sm:justify-center sm:pt-0"
    >
        <div>
            <Link href="/">
                <ApplicationLogo class="h-20 w-20 fill-current text-sky-500" />
            </Link>
        </div>

        <div
            class="mt-6 w-full overflow-hidden rounded-lg border border-sky-200 bg-white px-6 py-4 shadow-sm shadow-sky-100/60 sm:max-w-md"
        >
            <slot />
        </div>
    </div>
</template>
