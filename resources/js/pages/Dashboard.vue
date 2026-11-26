<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { dashboard } from '@/routes';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto p-4">
        <section class="rounded-lg border p-5">
            <h1 class="text-lg font-semibold">Ashby Calder</h1>
            <p class="text-muted-foreground mt-1 text-sm">
                You're signed in. Your years at Ashby Calder will show here
                once your instructor opens them.
            </p>
        </section>
    </div>
</template>
