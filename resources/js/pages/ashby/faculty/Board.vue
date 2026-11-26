<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import './staff.css';

type Dict = Record<string, any>;
type Thread = {
    id: string;
    from: number;
    returns: number;
    value: string;
    seen: string | null;
};
type TeamRow = {
    id: number;
    name: string;
    members: { name: string; seat: string }[];
    status: 'not_started' | 'at_table' | 'confirmed';
    confirmed_by: string | null;
    closed_by_staff: boolean;
    can_reopen: boolean;
    minutes: number;
    checks: number;
    cards: Dict | null;
    moment: string | null;
    paragraph: string | null;
    figures: {
        profit: number;
        trust: number;
        strain: number;
        year: number;
    } | null;
    threads: Thread[];
};

const props = defineProps<{
    section: {
        id: number;
        name: string;
        future: string | null;
        future_drawn: boolean;
        future_locked: boolean;
    };
    classes: { id: number; name: string }[];
    year: number;
    years: { year: number; state: 'not_open' | 'open' | 'closed' }[];
    teams: TeamRow[];
    panel: {
        confirmed: number;
        intake: number[];
        modes: Record<string, number>;
        alone: number;
        checked: number;
    };
    replay: {
        teams: {
            id: number;
            name: string;
            futures: Record<string, number>;
            judgment: number;
        }[];
        reference: Record<string, number>;
    } | null;
    futures: string[];
    words: Dict;
}>();

const F = computed(() => props.words.faculty);
const page = usePage();
const error = computed(
    () => ((page.props.errors ?? {}) as Record<string, string>).board ?? '',
);
const open = ref<number | null>(null);
const base = computed(() => `/faculty/${props.section.id}`);
const state = computed(
    () => props.years.find((y) => y.year === props.year)?.state ?? 'not_open',
);
const threadsList = computed(() =>
    (F.value.threads.list as Dict[]).filter((t) => t.from <= props.year),
);

function t(text: string, vars: Record<string, string | number> = {}): string {
    return String(text ?? '').replace(/\{(\w+)\}/g, (m, k) =>
        k in vars ? String(vars[k]) : m,
    );
}
function money(x: number): string {
    return (x < 0 ? '−$' : '$') + Math.abs(x / 1e6).toFixed(1) + 'M';
}
function post(url: string, data: Dict = {}): void {
    router.post(url, data, { preserveScroll: true });
}
function status(row: TeamRow): string {
    if (row.status === 'confirmed') {
        return row.closed_by_staff
            ? F.value.teams.closed_by_you
            : t(F.value.teams.confirmed, { seat: row.confirmed_by ?? '' });
    }

    return F.value.teams[row.status];
}
function switchClass(e: Event): void {
    router.visit(`/faculty/${(e.target as HTMLSelectElement).value}`);
}
function threadName(id: string): Dict {
    return (F.value.threads.list as Dict[]).find((x) => x.id === id) ?? {};
}
</script>

<template>
    <Head :title="section.name" />
    <div class="staff">
        <header>
            <div class="badge" aria-hidden="true">F</div>
            <div class="brand">
                {{ words.common.product
                }}<small>{{ F.board }} · {{ section.name }}</small>
            </div>
            <select
                :value="section.id"
                :aria-label="F.classes"
                @change="switchClass"
            >
                <option v-for="c in classes" :key="c.id" :value="c.id">
                    {{ c.name }}
                </option>
            </select>
            <Link href="/faculty" class="btn">{{ F.classes }}</Link>
            <Link href="/logout" method="post" as="button" class="out">{{
                words.common.sign_out
            }}</Link>
        </header>
        <main>
            <h1>{{ section.name }}</h1>
            <p v-if="error" class="error" role="alert">{{ error }}</p>

            <div class="card">
                <h2 style="margin-top: 0">{{ F.future.title }}</h2>
                <p class="soft">{{ F.future.lead }}</p>
                <p v-if="section.future">
                    <b>{{
                        t(
                            section.future_drawn
                                ? F.future.drawn
                                : F.future.chosen,
                            { future: F.future.names[section.future] },
                        )
                    }}</b>
                    <span class="soft">
                        {{ F.future.notes[section.future] }}</span
                    >
                </p>
                <p v-else class="error">{{ F.future.none }}</p>
                <p v-if="section.future_locked" class="soft small">
                    {{ F.future.locked }}
                </p>
                <div v-else class="row">
                    <button
                        v-for="f in futures"
                        :key="f"
                        class="btn"
                        :class="{ main: section.future === f }"
                        @click="post(`${base}/future`, { future: f })"
                    >
                        {{ F.future.choose }}: {{ F.future.names[f] }}
                    </button>
                    <button
                        class="btn"
                        @click="post(`${base}/future`, { future: 'draw' })"
                    >
                        {{ F.future.draw }}
                    </button>
                </div>
            </div>

            <nav class="row" aria-label="Years" style="margin: 16px 0">
                <Link
                    v-for="y in years"
                    :key="y.year"
                    :href="`${base}/${y.year}`"
                    class="btn"
                    :class="{ main: y.year === year }"
                >
                    {{ y.year }}
                    <span class="pill" :class="{ on: y.state === 'open' }">{{
                        F.year[y.state]
                    }}</span>
                </Link>
            </nav>

            <div class="card row">
                <button
                    v-if="state === 'not_open'"
                    class="btn main"
                    :disabled="!section.future"
                    @click="post(`${base}/years/${year}/open`)"
                >
                    {{ t(F.year.open_button, { year }) }}
                </button>
                <button
                    v-if="state === 'open'"
                    class="btn main"
                    @click="post(`${base}/years/${year}/close`)"
                >
                    {{ t(F.year.close_button, { year }) }}
                </button>
                <span class="soft small">{{
                    state === 'open'
                        ? F.year.close_note
                        : t(F.year.open_note, { year })
                }}</span>
            </div>

            <h2>{{ t(F.teams.title, { year }) }}</h2>
            <div class="wrap">
                <table>
                    <thead>
                        <tr>
                            <th
                                v-for="(c, i) in F.teams.columns"
                                :key="c"
                                :class="{ n: i > 1 }"
                            >
                                {{ c }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="row in teams" :key="row.id">
                            <tr>
                                <td>
                                    <button
                                        class="btn"
                                        :aria-expanded="open === row.id"
                                        @click="
                                            open =
                                                open === row.id ? null : row.id
                                        "
                                    >
                                        {{ row.name }}
                                    </button>
                                </td>
                                <td>{{ status(row) }}</td>
                                <td class="n">{{ row.minutes }}</td>
                                <td class="n">{{ row.checks }}</td>
                                <td class="n">
                                    {{
                                        row.figures
                                            ? money(row.figures.profit)
                                            : ''
                                    }}
                                </td>
                                <td class="n">
                                    {{
                                        row.figures
                                            ? Math.round(row.figures.trust)
                                            : ''
                                    }}
                                </td>
                                <td class="n">
                                    {{
                                        row.figures
                                            ? Math.round(row.figures.strain)
                                            : ''
                                    }}
                                </td>
                            </tr>
                            <tr v-if="open === row.id">
                                <td colspan="7">
                                    <p class="small">
                                        <b>{{ F.teams.members }}:</b>
                                        {{
                                            row.members
                                                .map(
                                                    (m) =>
                                                        `${m.name} (${m.seat})`,
                                                )
                                                .join(', ') || '—'
                                        }}
                                    </p>
                                    <p class="small">
                                        <b>{{ F.teams.moment }}:</b>
                                        {{ row.moment || F.teams.none }}
                                    </p>
                                    <p class="small">
                                        <b>{{ F.teams.paragraph }}:</b>
                                        {{ row.paragraph || F.teams.none }}
                                    </p>
                                    <div class="row">
                                        <Link
                                            v-if="row.status !== 'not_started'"
                                            :href="`${base}/teams/${row.id}/years/${year}`"
                                            class="btn"
                                            >{{ F.teams.details }}</Link
                                        >
                                        <button
                                            v-if="row.can_reopen"
                                            class="btn"
                                            :title="
                                                t(F.teams.reopen_note, { year })
                                            "
                                            @click="
                                                post(
                                                    `${base}/teams/${row.id}/years/${year}/reopen`,
                                                )
                                            "
                                        >
                                            {{ t(F.teams.reopen, { year }) }}
                                        </button>
                                    </div>
                                    <template v-if="row.threads.length">
                                        <h3
                                            class="small"
                                            style="
                                                font-weight: 700;
                                                margin: 12px 0 4px;
                                            "
                                        >
                                            {{ F.threads.title }}
                                        </h3>
                                        <table>
                                            <thead>
                                                <tr>
                                                    <th
                                                        v-for="c in F.threads
                                                            .columns"
                                                        :key="c"
                                                        class="small"
                                                    >
                                                        {{ c }}
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr
                                                    v-for="th in row.threads"
                                                    :key="th.id"
                                                    class="small"
                                                >
                                                    <td>
                                                        {{
                                                            threadName(th.id)
                                                                .choice
                                                        }}
                                                        ({{ th.from }})
                                                    </td>
                                                    <td>{{ th.value }}</td>
                                                    <td>
                                                        {{
                                                            threadName(th.id)
                                                                .comes_back
                                                        }}
                                                    </td>
                                                    <td>{{ th.returns }}</td>
                                                    <td>
                                                        {{
                                                            th.seen ??
                                                            F.threads.not_yet
                                                        }}
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </template>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <h2>{{ t(F.panel.title, { year }) }}</h2>
            <p class="soft small">{{ F.panel.lead }}</p>
            <div class="card">
                <p>
                    <b>{{ F.panel.intake }}:</b>
                    {{ panel.intake.map((x) => `${x}%`).join(', ') || '—' }}
                </p>
                <p>
                    <b>{{ F.panel.modes }}:</b>
                    <span v-for="(n, m) in panel.modes" :key="m">
                        {{ F.panel.mode_names[m] }} {{ n }} ·</span
                    >
                </p>
                <p>
                    <b>{{ F.panel.alone }}:</b> {{ panel.alone }} /
                    {{ panel.confirmed }}
                </p>
                <p v-if="year === 2027">
                    <b>{{ F.panel.checked }}:</b> {{ panel.checked }} /
                    {{ panel.confirmed }}
                </p>
            </div>

            <h2>{{ F.threads.title }}</h2>
            <p class="soft small">{{ F.threads.lead }}</p>
            <ul class="small">
                <li v-for="th in threadsList" :key="th.id">
                    <b>{{ th.choice }}</b> ({{ th.from }}) →
                    {{ th.comes_back }} ({{ th.returns }})
                </li>
            </ul>

            <template v-if="year === 2031">
                <h2>{{ F.replay.title }}</h2>
                <p class="soft small">{{ F.replay.lead }}</p>
                <div v-if="replay" class="wrap">
                    <table>
                        <thead>
                            <tr>
                                <th
                                    v-for="(c, i) in F.replay.columns"
                                    :key="c"
                                    :class="{ n: i > 0 }"
                                >
                                    {{ c }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in replay.teams" :key="r.id">
                                <td>{{ r.name }}</td>
                                <td
                                    v-for="f in futures"
                                    :key="f"
                                    class="n"
                                    :style="
                                        f === section.future
                                            ? 'font-weight:700'
                                            : ''
                                    "
                                >
                                    {{ r.futures[f]?.toFixed(1) }}
                                </td>
                                <td class="n">
                                    <b>{{ r.judgment.toFixed(1) }}</b>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="small soft">
                        {{ F.replay.reference }}:
                        <span v-for="(v, k) in replay.reference" :key="k"
                            >{{ F.reference_names[k] }} {{ v.toFixed(1) }} ·
                        </span>
                    </p>
                </div>
                <p v-else class="soft">{{ F.replay.waiting }}</p>
            </template>
        </main>
    </div>
</template>
