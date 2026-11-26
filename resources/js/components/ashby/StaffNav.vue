<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{
    sectionId: number | null;
    here: string;
    isAdmin: boolean;
    words: Record<string, any>;
}>();
</script>

<template>
    <nav class="row" aria-label="Menu" style="margin: 0 0 16px">
        <template v-if="sectionId !== null">
            <Link
                :href="`/faculty/${sectionId}`"
                class="btn"
                :class="{ main: here === 'board' }"
                >{{ words.faculty.nav.board }}</Link
            >
            <Link
                :href="`/faculty/${sectionId}/roster`"
                class="btn"
                :class="{ main: here === 'roster' }"
                >{{ words.faculty.nav.roster }}</Link
            >
            <a :href="`/faculty/${sectionId}/export/results.csv`" class="btn">{{
                words.faculty.nav.results
            }}</a>
            <a
                :href="`/faculty/${sectionId}/export/gradebook.csv`"
                class="btn"
                >{{ words.faculty.nav.gradebook }}</a
            >
        </template>
        <Link
            href="/faculty"
            class="btn"
            :class="{ main: here === 'classes' }"
            >{{ words.faculty.nav.classes }}</Link
        >
        <Link
            v-if="isAdmin"
            href="/admin"
            class="btn"
            :class="{ main: here === 'admin' }"
            >{{ words.faculty.nav.admin }}</Link
        >
    </nav>
</template>
