<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type Dict = Record<string, any>;
const props = defineProps<{
    team: { name: string };
    future: string;
    replay: {
        futures: Record<
            string,
            {
                cum_profit_m: number;
                end_bench: number;
                end_trust: number;
                overall: number;
            }
        >;
        judgment: number;
        reference: Record<string, number>;
    };
    paragraphs: Record<string, string | null>;
    words: Dict;
}>();

const C = computed(() => props.words.common);
const W = computed(() => props.words.replay);
const copied = ref('');

function t(text: string, vars: Record<string, string | number> = {}): string {
    return String(text ?? '').replace(/\{(\w+)\}/g, (m, k) =>
        k in vars ? String(vars[k]) : m,
    );
}
function money(m: number): string {
    return (m < 0 ? '−$' : '$') + Math.abs(m).toFixed(1) + 'M';
}
const bars = computed(() =>
    Object.entries(props.replay.reference)
        .map(([k, v]) => [k, v] as [string, number])
        .concat([['you', props.replay.judgment]])
        .sort((a, b) => b[1] - a[1]),
);
function copy(): void {
    const text = Object.entries(props.paragraphs)
        .map(([y, p]) => `${y}: ${p ?? ''}`)
        .join('\n\n');
    navigator.clipboard.writeText(text).then(
        () => (copied.value = W.value.copied),
        () => (copied.value = W.value.copy_failed),
    );
}
</script>

<template>
    <Head :title="W.title" />
    <div class="chro">
        <header>
            <div class="badge" aria-hidden="true">F</div>
            <div class="brand">
                {{ C.product }}<small>{{ C.company }} · {{ team.name }}</small>
            </div>
            <Link href="/logout" method="post" as="button" class="out">{{
                C.sign_out
            }}</Link>
        </header>
        <main>
            <h1>{{ W.title }}</h1>
            <p>{{ t(W.happened, { future: W.futures[future] }) }}</p>
            <table>
                <thead>
                    <tr>
                        <th
                            v-for="(c, i) in W.columns"
                            :key="i"
                            :class="{ n: i > 0 }"
                        >
                            {{ c }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(x, f) in replay.futures" :key="f">
                        <td>
                            {{ W.futures[f] }}
                            <small v-if="f === future" class="soft">{{
                                W.happened_tag
                            }}</small>
                        </td>
                        <td class="n">{{ money(x.cum_profit_m) }}</td>
                        <td class="n">{{ x.end_bench.toFixed(2) }}</td>
                        <td class="n">{{ Math.round(x.end_trust) }}</td>
                        <td class="n">
                            <b>{{ x.overall.toFixed(1) }}</b>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p class="soft">{{ W.overall_note }}</p>
            <h2>
                {{ t(W.judgment_title, { value: replay.judgment.toFixed(1) }) }}
            </h2>
            <p>{{ W.judgment_body }}</p>
            <div class="card">
                <div v-for="[k, v] in bars" :key="k" class="barrow">
                    <span class="lbl" :class="{ you: k === 'you' }">{{
                        W.teams[k]
                    }}</span>
                    <span class="track"
                        ><span
                            class="fill"
                            :class="{ you: k === 'you' }"
                            :style="{
                                width: Math.max(2, (v / 80) * 100) + '%',
                            }"
                        ></span
                    ></span>
                    <b class="val">{{ v.toFixed(1) }}</b>
                </div>
                <p class="soft small">{{ W.teams_note }}</p>
            </div>
            <h2>{{ W.paragraphs_title }}</h2>
            <p v-for="(p, y) in paragraphs" :key="y">
                <b>{{ y }}:</b> {{ p || W.none_written }}
            </p>
            <button class="btn" @click="copy">{{ W.copy }}</button>
            <span class="soft small">{{ copied }}</span>
        </main>
    </div>
</template>

<style scoped>
.chro {
    background: #fff;
    color: #101418;
    min-height: 100vh;
    font:
        16px/1.55 Manrope,
        system-ui,
        sans-serif;
}
header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px max(4vw, 16px);
    border-bottom: 1px solid #e3e6ea;
}
.badge {
    width: 34px;
    height: 34px;
    border-radius: 7px;
    background: #ec1a22;
    color: #fff;
    font-weight: 800;
    display: grid;
    place-items: center;
    font-size: 20px;
}
.brand {
    font-weight: 800;
}
.brand small {
    display: block;
    font-weight: 500;
    color: #5b6470;
    font-size: 13px;
}
.out {
    margin-left: auto;
    font-size: 14px;
    color: #5b6470;
}
main {
    max-width: 1100px;
    margin: 0 auto;
    padding: clamp(20px, 3vw, 40px) max(4vw, 16px) 80px;
}
h1 {
    font-size: clamp(28px, 4vw, 42px);
    font-weight: 800;
    margin: 0 0 12px;
}
h2 {
    font-size: 22px;
    font-weight: 700;
    margin: 28px 0 10px;
}
p {
    max-width: 70ch;
    margin: 0 0 12px;
}
.soft {
    color: #5b6470;
}
.small {
    font-size: 13px;
}
table {
    border-collapse: collapse;
    width: 100%;
}
th,
td {
    text-align: left;
    padding: 8px 6px;
    border-bottom: 1px solid #e3e6ea;
}
.n {
    text-align: right;
    white-space: nowrap;
}
.card {
    border: 1px solid #e3e6ea;
    border-radius: 10px;
    padding: 18px;
}
.barrow {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 6px 0;
}
.lbl {
    flex: 0 0 120px;
}
.lbl.you {
    font-weight: 800;
}
.track {
    flex: 1;
    background: #f6f7f8;
    border-radius: 4px;
    height: 22px;
    position: relative;
}
.fill {
    position: absolute;
    inset: 0 auto 0 0;
    background: #e3e6ea;
    border-radius: 4px;
}
.fill.you {
    background: #ffd84a;
}
.val {
    flex: 0 0 48px;
    text-align: right;
}
.btn {
    border: 1px solid #101418;
    background: #101418;
    color: #fff;
    border-radius: 8px;
    padding: 10px 14px;
    font-weight: 600;
    min-height: 44px;
    margin-right: 10px;
}
</style>
