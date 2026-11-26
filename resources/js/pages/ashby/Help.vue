<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type Item = string | { title: string; list: string[] };
const props = defineProps<{
    audience: 'participant' | 'instructor';
    sections: { id: string; title: string; for: string; body: Item[] }[];
    questions: { q: string; a: string }[];
    changes: { date: string; titles: string[] }[];
    words: Record<string, any>;
    isStaff: boolean;
}>();

const W = computed(() => props.words.manual);
const search = ref('');
const shown = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) {
        return props.sections;
    }

    return props.sections.filter((s) =>
        JSON.stringify([s.title, s.body]).toLowerCase().includes(q),
    );
});
const shownQuestions = computed(() => {
    const q = search.value.trim().toLowerCase();

    return q
        ? props.questions.filter((x) => (x.q + x.a).toLowerCase().includes(q))
        : props.questions;
});
</script>

<template>
    <Head :title="audience === 'instructor' ? W.instructor_title : W.title" />
    <div class="help">
        <header>
            <div class="badge" aria-hidden="true">F</div>
            <div class="brand">
                {{ words.common.product
                }}<small>{{
                    audience === 'instructor'
                        ? W.instructor_title
                        : W.participant_title
                }}</small>
            </div>
            <Link
                v-if="isStaff && audience === 'participant'"
                href="/faculty/help"
                class="link"
                >{{ W.instructor_title }}</Link
            >
            <Link v-if="audience === 'instructor'" href="/help" class="link">{{
                W.participant_title
            }}</Link>
            <a href="javascript:history.back()" class="link">{{ W.back }}</a>
        </header>
        <main>
            <h1>
                {{
                    audience === 'instructor'
                        ? W.instructor_title
                        : W.participant_title
                }}
            </h1>
            <input
                v-model="search"
                type="search"
                :placeholder="W.search"
                :aria-label="W.search"
            />
            <nav v-if="!search" class="toc" :aria-label="W.manual">
                <a v-for="s in sections" :key="s.id" :href="`#${s.id}`">{{
                    s.title
                }}</a>
            </nav>
            <section v-for="s in shown" :id="s.id" :key="s.id">
                <h2>{{ s.title }}</h2>
                <template v-for="(item, i) in s.body" :key="i">
                    <p v-if="typeof item === 'string'">{{ item }}</p>
                    <template v-else>
                        <h3>{{ item.title }}</h3>
                        <ul>
                            <li v-for="(l, j) in item.list" :key="j">
                                {{ l }}
                            </li>
                        </ul>
                    </template>
                </template>
            </section>
            <h2>{{ W.faq }}</h2>
            <details v-for="(x, i) in shownQuestions" :key="i">
                <summary>{{ x.q }}</summary>
                <p>{{ x.a }}</p>
            </details>
            <p
                v-if="
                    search && shown.length === 0 && shownQuestions.length === 0
                "
                class="soft"
            >
                {{ W.none }}
            </p>
            <h2>{{ W.changed }}</h2>
            <div v-for="c in changes" :key="c.date">
                <p>
                    <b>{{ c.date }}:</b> {{ c.titles.join(', ') }}
                </p>
            </div>
        </main>
    </div>
</template>

<style scoped>
.help {
    background: #fff;
    color: #101418;
    min-height: 100vh;
    font:
        16px/1.6 Manrope,
        system-ui,
        sans-serif;
}
header {
    position: sticky;
    top: 0;
    background: #fff;
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px max(4vw, 16px);
    border-bottom: 1px solid #e3e6ea;
    flex-wrap: wrap;
}
.badge {
    width: 32px;
    height: 32px;
    border-radius: 7px;
    background: #ec1a22;
    color: #fff;
    font-weight: 800;
    display: grid;
    place-items: center;
}
.brand {
    font-weight: 800;
    margin-right: auto;
}
.brand small {
    display: block;
    font-weight: 500;
    color: #5b6470;
    font-size: 13px;
}
.link {
    font-size: 14px;
    color: #101418;
    text-decoration: underline;
}
main {
    max-width: 760px;
    margin: 0 auto;
    padding: 24px max(4vw, 16px) 80px;
}
h1 {
    font-size: clamp(26px, 3.4vw, 36px);
    font-weight: 800;
    margin: 0 0 12px;
}
h2 {
    font-size: 21px;
    font-weight: 700;
    margin: 28px 0 8px;
}
h3 {
    font-size: 16px;
    font-weight: 700;
    margin: 12px 0 4px;
}
p {
    margin: 0 0 10px;
}
ul {
    list-style: disc;
    padding-left: 22px;
    margin: 0 0 10px;
}
input {
    width: 100%;
    border: 1px solid #c9ced4;
    border-radius: 8px;
    padding: 10px;
    min-height: 44px;
    font-size: 16px;
}
.toc {
    display: flex;
    flex-wrap: wrap;
    gap: 6px 14px;
    margin: 14px 0;
    font-size: 14px;
}
.toc a {
    text-decoration: underline;
}
details {
    border-bottom: 1px solid #e3e6ea;
    padding: 8px 0;
}
summary {
    font-weight: 600;
    cursor: pointer;
}
.soft {
    color: #5b6470;
}
</style>
