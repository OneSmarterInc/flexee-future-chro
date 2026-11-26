<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import '@/components/ashby/staff.css';

const props = defineProps<{
    sections: { id: number; name: string; teams: number }[];
    futures: string[];
    words: Record<string, any>;
}>();

const future = ref(props.futures[0] ?? 'rapid');
const F = props.words.faculty;

function t(text: string, vars: Record<string, string | number> = {}): string {
    return String(text ?? '').replace(/\{(\w+)\}/g, (m, k) =>
        k in vars ? String(vars[k]) : m,
    );
}
function makeSample(): void {
    router.post('/faculty/sample', { future: future.value });
}
</script>

<template>
    <Head :title="F.title" />
    <div class="staff">
        <header>
            <div class="badge" aria-hidden="true">F</div>
            <div class="brand">
                {{ words.common.product
                }}<small>{{ words.common.company }}</small>
            </div>
            <Link href="/logout" method="post" as="button" class="out">{{
                words.common.sign_out
            }}</Link>
        </header>
        <main>
            <h1>{{ F.title }}</h1>
            <p class="soft">{{ F.lead }}</p>
            <p v-if="sections.length === 0">{{ F.no_classes }}</p>
            <div v-for="s in sections" :key="s.id" class="card row">
                <Link :href="`/faculty/${s.id}`" class="btn main">{{
                    s.name
                }}</Link>
                <span class="soft">{{
                    t(F.teams_count, { count: s.teams })
                }}</span>
            </div>
            <h2>{{ F.sample_button }}</h2>
            <p class="soft">{{ F.sample_note }}</p>
            <div class="row">
                <select v-model="future" :aria-label="F.future.title">
                    <option v-for="f in futures" :key="f" :value="f">
                        {{ F.future.names[f] }}
                    </option>
                </select>
                <button class="btn main" @click="makeSample">
                    {{ F.sample_button }}
                </button>
            </div>
        </main>
    </div>
</template>
