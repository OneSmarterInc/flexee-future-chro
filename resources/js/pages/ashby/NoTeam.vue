<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

defineProps<{ words: Record<string, any> }>();
</script>

<template>
    <Head :title="words.product" />
    <main
        class="mx-auto flex min-h-screen max-w-xl flex-col justify-center gap-4 p-8"
    >
        <p class="text-xl font-bold">{{ words.product }}</p>
        <p>{{ words.no_team }}</p>
        <Link
            href="/logout"
            method="post"
            as="button"
            class="text-muted-foreground self-start text-sm underline"
            >{{ words.sign_out }}</Link
        >
    </main>
</template>
