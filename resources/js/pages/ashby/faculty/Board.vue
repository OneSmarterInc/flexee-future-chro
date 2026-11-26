<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import StaffNav from '@/components/ashby/StaffNav.vue';
import '@/components/ashby/staff.css';

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
    feedback: string | null;
    feedback_note: string | null;
    ai_notes: { person: string; question: string; note: string }[];
};

const props = defineProps<{
    section: {
        id: number;
        name: string;
        future: string | null;
        future_drawn: boolean;
        future_locked: boolean;
        format: string;
        timezone: string;
        auto_run: boolean;
    };
    calendar: Record<
        number,
        { opens_at: string | null; closes_at: string | null }
    >;
    isAdmin: boolean;
    lastYear: number;
    ai: {
        available: boolean;
        features: { id: string; on: boolean }[];
        reading: { reading: string | null; reading_note: string | null } | null;
        debrief: { text: string | null; note: string | null };
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
        ruth: Record<string, number>;
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
const cal = reactive({
    years: JSON.parse(JSON.stringify(props.calendar)) as Record<
        number,
        { opens_at: string | null; closes_at: string | null }
    >,
    auto_run: props.section.auto_run,
});
const first = ref(props.calendar[2027]?.opens_at ?? '');
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
            <Link href="/logout" method="post" as="button" class="out">{{
                words.common.sign_out
            }}</Link>
        </header>
        <main>
            <StaffNav
                :section-id="section.id"
                here="board"
                :is-admin="isAdmin"
                :words="words"
            />
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

            <div class="card">
                <h2 style="margin-top: 0">
                    {{ F.calendar.title }} ·
                    {{ F.calendar.format[section.format] }}
                </h2>
                <p class="soft small">
                    {{ t(F.calendar.lead, { timezone: section.timezone }) }}
                </p>
                <div class="row">
                    <label class="small"
                        >{{ F.calendar.first }}
                        <input v-model="first" type="datetime-local" />
                    </label>
                    <button
                        class="btn"
                        :disabled="!first"
                        @click="post(`${base}/calendar/fill`, { first })"
                    >
                        {{ F.calendar.fill }}
                    </button>
                </div>
                <div class="wrap">
                    <table>
                        <thead>
                            <tr>
                                <th v-for="c in F.calendar.columns" :key="c">
                                    {{ c }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="y in years" :key="y.year">
                                <td>{{ y.year }}</td>
                                <td>
                                    <input
                                        v-model="cal.years[y.year].opens_at"
                                        type="datetime-local"
                                        :aria-label="`${F.calendar.opens} ${y.year}`"
                                    />
                                </td>
                                <td>
                                    <input
                                        v-model="cal.years[y.year].closes_at"
                                        type="datetime-local"
                                        :aria-label="`${F.calendar.closes} ${y.year}`"
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <label class="small row" style="margin: 8px 0"
                    ><input v-model="cal.auto_run" type="checkbox" />
                    {{ F.calendar.auto_run }}</label
                >
                <p class="soft small">{{ F.calendar.auto_note }}</p>
                <button class="btn main" @click="post(`${base}/calendar`, cal)">
                    {{ F.calendar.save }}
                </button>
            </div>

            <div class="card">
                <h2 style="margin-top: 0">{{ F.ai.title }}</h2>
                <p v-if="!ai.available" class="soft small">
                    {{ F.ai.server_off }}
                </p>
                <p v-else class="soft small">{{ F.ai.lead }}</p>
                <div class="row">
                    <button
                        v-for="f in ai.features"
                        :key="f.id"
                        class="btn"
                        :class="{ main: f.on }"
                        :aria-pressed="f.on"
                        @click="
                            post(`${base}/ai`, { feature: f.id, on: !f.on })
                        "
                    >
                        {{ F.ai.features[f.id] }}:
                        {{ f.on ? F.ai.on : F.ai.off }}
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
                                    <p v-if="row.feedback" class="small">
                                        <b>{{ F.ai.feedback }}:</b>
                                        {{ row.feedback }}
                                    </p>
                                    <p
                                        v-else-if="row.feedback_note"
                                        class="small soft"
                                    >
                                        {{ F.ai.feedback }}:
                                        {{
                                            t(F.ai.dropped, {
                                                note: row.feedback_note,
                                            })
                                        }}
                                    </p>
                                    <template v-if="row.ai_notes.length">
                                        <p class="small">
                                            <b>{{ F.ai.table_notes }}:</b>
                                        </p>
                                        <ul class="small">
                                            <li
                                                v-for="(n, i) in row.ai_notes"
                                                :key="i"
                                            >
                                                {{ n.person }}: "{{
                                                    n.question
                                                }}" · {{ n.note }}
                                            </li>
                                        </ul>
                                    </template>
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
                <p v-if="year === 2032">
                    <b>{{ F.panel.ruth }}:</b>
                    <span v-for="(n, k) in panel.ruth" :key="k">
                        {{ F.panel.ruth_names[k] }} {{ n }} ·</span
                    >
                </p>
                <p v-if="year === 2027">
                    <b>{{ F.panel.checked }}:</b> {{ panel.checked }} /
                    {{ panel.confirmed }}
                </p>
            </div>

            <h2>{{ t(F.ai.reading_title, { year }) }}</h2>
            <p v-if="ai.reading?.reading">{{ ai.reading.reading }}</p>
            <p v-else-if="ai.reading?.reading_note" class="soft small">
                {{ t(F.ai.dropped, { note: ai.reading.reading_note }) }}
            </p>
            <button
                v-if="
                    ai.available &&
                    ai.features.find((f) => f.id === 'reading')?.on
                "
                class="btn"
                :disabled="teams.some((r) => r.status !== 'confirmed')"
                :title="t(F.ai.reading_wait, { year })"
                @click="post(`${base}/years/${year}/reading`)"
            >
                {{
                    ai.reading?.reading
                        ? F.ai.reading_again
                        : F.ai.reading_button
                }}
            </button>

            <h2>{{ F.threads.title }}</h2>
            <p class="soft small">{{ F.threads.lead }}</p>
            <ul class="small">
                <li v-for="th in threadsList" :key="th.id">
                    <b>{{ th.choice }}</b> ({{ th.from }}) →
                    {{ th.comes_back }} ({{ th.returns }})
                </li>
            </ul>

            <template v-if="year === lastYear">
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

                <h2>{{ F.ai.debrief_title }}</h2>
                <p v-if="ai.debrief.text" style="white-space: pre-line">
                    {{ ai.debrief.text }}
                </p>
                <p v-else-if="ai.debrief.note" class="soft small">
                    {{ t(F.ai.dropped, { note: ai.debrief.note }) }}
                </p>
                <button
                    v-if="
                        replay &&
                        ai.available &&
                        ai.features.find((f) => f.id === 'debrief')?.on
                    "
                    class="btn"
                    @click="post(`${base}/debrief`)"
                >
                    {{
                        ai.debrief.text
                            ? F.ai.debrief_again
                            : F.ai.debrief_button
                    }}
                </button>
            </template>
        </main>
    </div>
</template>
