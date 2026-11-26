<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { dashboard, login } from '@/routes';
</script>

<template>
    <Head title="Welcome" />

    <main class="mx-auto flex min-h-screen max-w-3xl flex-col justify-center gap-6 p-8">
        <div class="flex items-center gap-3">
            <img src="/flexee-logo.svg" alt="Flexee" class="h-10 w-10" />
            <div>
                <p class="text-xl font-bold">The Future CHRO</p>
                <p class="text-muted-foreground text-sm">Ashby Calder, 2027 to 2035</p>
            </div>
        </div>
        <h1 class="text-3xl font-bold leading-tight">
            Lead the people of a 2,000-person firm through nine years of AI.
        </h1>
        <p class="text-muted-foreground max-w-prose">
            You're the people leadership team of Ashby Calder, an audit, tax and
            advisory firm. You decide how work is split between people and AI,
            how the firm keeps growing experts and leaders, and how AI is
            checked. Nobody knows how fast AI will move. At the end, you see
            your plan replayed in three possible futures.
        </p>
        <div>
            <Link
                v-if="$page.props.auth.user"
                :href="dashboard()"
                class="bg-primary text-primary-foreground inline-block rounded-md px-4 py-2 font-semibold"
                >Go on</Link
            >
            <Link
                v-else
                :href="login()"
                class="bg-primary text-primary-foreground inline-block rounded-md px-4 py-2 font-semibold"
                >Sign in</Link
            >
        </div>
    </main>
</template>
