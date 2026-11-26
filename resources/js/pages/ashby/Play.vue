<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, reactive, ref, watch } from 'vue';

type Dict = Record<string, any>;
type Figures = {
    year: number;
    profit: number;
    revenue: number;
    headcount: number;
    trust: number;
    strain: number;
    incidents: number;
    bench: number;
    delivery_levels: number[];
    operations_levels: number[];
    notes: string[];
};
type Person = {
    key: string;
    name: string;
    role: string;
    opens: string;
    suggested: string[];
    messages: { question: string; answer: string; new: boolean }[];
};

const props = defineProps<{
    year: number;
    years: { year: number; open: boolean; confirmed: boolean }[];
    team: { name: string; seat: string };
    words: Dict;
    people: Person[];
    plan: Dict;
    minutesLeft: number;
    checkingUsed: number;
    cards: Dict;
    moment: string | null;
    paragraph: string | null;
    confirmed: boolean;
    standing: Figures | null;
    results: { years: Figures[] } | null;
}>();

const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string>,
);
const W = computed(() => props.words);
const C = computed(() => props.words.common);
const K = computed(() => props.words.cards);
const R = computed(() => props.words.results);

function t(text: string, vars: Record<string, string | number> = {}): string {
    return String(text ?? '').replace(/\{(\w+)\}/g, (m, k) =>
        k in vars ? String(vars[k]) : m,
    );
}
function money(x: number): string {
    return (x < 0 ? '−$' : '$') + Math.abs(x / 1e6).toFixed(1) + 'M';
}

const initialStep = Number(
    new URLSearchParams(window.location.search).get('step') ??
        (props.confirmed ? 4 : 0),
);
const step = ref(Math.min(Math.max(initialStep, 0), 4));
const person = ref(props.people[0]?.key ?? '');
const question = ref('');
const form = reactive<Dict>(JSON.parse(JSON.stringify(props.cards)));
const momentText = ref(props.moment ?? '');
const offers = ref(Number(props.cards.offers ?? 230));
const planning = ref(String(props.cards.planning ?? 'hr'));
const paragraph = ref(props.paragraph ?? '');
const chatBox = ref<HTMLElement | null>(null);
const saving = ref(false);

watch(
    () => props.cards,
    (cards) => {
        Object.assign(form, JSON.parse(JSON.stringify(cards)));
    },
);

const current = computed(
    () => props.people.find((p) => p.key === person.value) ?? props.people[0],
);
const asked = computed(() => current.value?.messages.length ?? 0);
const firstName = computed(() => (current.value?.name ?? '').split(' ')[0]);

function scrollChat(): void {
    void nextTick(() => {
        if (chatBox.value) {
            chatBox.value.scrollTop = chatBox.value.scrollHeight;
        }
    });
}
watch(person, scrollChat);

function go(n: number): void {
    if (!props.confirmed && step.value === 3 && n !== 3) {
        saveCards();
    }
    if (step.value === 2 && n !== 2 && !props.confirmed) {
        saveMoment();
    }
    step.value = n;
    window.scrollTo(0, 0);
}

function post(url: string, data: Dict, onSuccess?: () => void): void {
    saving.value = true;
    router.post(url, data, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => onSuccess?.(),
        onFinish: () => (saving.value = false),
    });
}

function ask(text: string): void {
    const q = text.trim();
    if (!q || props.confirmed) {
        return;
    }
    post(
        `/play/${props.year}/ask`,
        { person: person.value, question: q },
        () => {
            question.value = '';
            scrollChat();
        },
    );
}
function check(id: string): void {
    post(`/play/${props.year}/check`, { check: id });
}
function saveMoment(): void {
    const data: Dict = { text: momentText.value };
    if (props.year === 2027) {
        data.offers = offers.value;
    }
    if (props.year === 2029) {
        data.planning = planning.value;
    }
    post(`/play/${props.year}/moment`, data);
}
function cardsPayload(): Dict {
    const data = JSON.parse(JSON.stringify(form));
    if (props.year === 2027) {
        data.offers = offers.value;
    }
    if (props.year === 2029) {
        data.planning = planning.value;
    }

    return data;
}
function saveCards(): void {
    post(`/play/${props.year}/cards`, cardsPayload());
}
function confirm(): void {
    router.post(`/play/${props.year}/confirm`, cardsPayload(), {
        onSuccess: () => {
            step.value = 4;
            window.scrollTo(0, 0);
        },
    });
}
function saveParagraph(then?: () => void): void {
    post(`/play/${props.year}/paragraph`, { paragraph: paragraph.value }, then);
}
function nextYear(): void {
    const next = props.years.find((y) => y.year > props.year);
    saveParagraph(() =>
        router.visit(next ? `/play/${next.year}` : '/play/replay'),
    );
}

const lastRan = computed(
    () => props.results?.years[props.results.years.length - 1] ?? null,
);
const band = computed(() => {
    const p = lastRan.value?.profit ?? 0;
    const b = R.value.bands as number[];

    return p >= b[0] ? 0 : p >= b[1] ? 1 : p >= b[2] ? 2 : 3;
});
const notes = computed(() =>
    (props.results?.years ?? []).flatMap((y) =>
        y.notes.map((n) => {
            const amount = (n.match(/(\d+)/) ?? ['', ''])[1];

            return t(n.startsWith('Outage') ? R.value.outage : R.value.vendor, {
                year: y.year,
                amount,
            });
        }),
    ),
);
const helen = computed(() =>
    props.standing
        ? t(W.value.moment.context ?? '', {
              offers: Math.round(Number(props.cards.offers ?? 230)),
              seniors: Math.round(props.standing.delivery_levels[1]),
              managers: Math.round(props.standing.delivery_levels[2]),
          })
        : '',
);
const START = {
    delivery: [520, 360, 210, 90],
    operations: [364, 252, 147, 63],
};
const kpis = (f: Figures): [string, string][] => [
    [money(f.profit), R.value.figures.profit],
    [money(f.revenue), R.value.figures.revenue],
    [Math.round(f.headcount).toLocaleString(), R.value.figures.headcount],
    [String(Math.round(f.trust)), R.value.figures.trust],
    [String(Math.round(f.strain)), R.value.figures.strain],
    [f.incidents.toFixed(1), R.value.figures.incidents],
    [f.bench.toFixed(2), R.value.figures.bench],
];
</script>

<template>
    <Head :title="String(year)" />
    <div class="chro">
        <header>
            <div class="bar">
                <div class="badge" aria-hidden="true">F</div>
                <div class="brand">
                    {{ C.product }}<small>{{ C.company }} · {{ year }}</small>
                </div>
                <div class="team">
                    {{ team.name
                    }}<template v-if="team.seat">
                        · <b>{{ C.seats[team.seat] }}</b></template
                    >
                </div>
                <div class="clock">
                    {{ t(C.table_time, { minutes: minutesLeft }) }}
                </div>
                <Link href="/logout" method="post" as="button" class="out">{{
                    C.sign_out
                }}</Link>
            </div>
            <nav aria-label="Steps">
                <button
                    v-for="(s, i) in C.steps"
                    :key="i"
                    :class="{ on: i === step }"
                    :disabled="i === 4 && !confirmed"
                    @click="go(i)"
                >
                    {{ i + 1 }}. {{ s }}
                </button>
            </nav>
        </header>

        <main>
            <!-- 1. The year in brief -->
            <section v-if="step === 0">
                <h1>{{ W.brief.title }}</h1>
                <p v-for="(p, i) in W.brief.paragraphs" :key="i">
                    {{ t(p, { revenue: '$389 million' }) }}
                </p>
                <div v-if="W.brief.figures" class="kpis">
                    <div v-for="f in W.brief.figures" :key="f[1]" class="kpi">
                        <b>{{ f[0] }}</b
                        ><span>{{ f[1] }}</span>
                    </div>
                </div>
                <template v-if="standing">
                    <p class="soft">
                        {{ t(R.stand, { year: standing.year }) }}
                    </p>
                    <div class="kpis">
                        <div
                            v-for="k in kpis(standing)"
                            :key="k[1]"
                            class="kpi"
                        >
                            <b>{{ k[0] }}</b
                            ><span>{{ k[1] }}</span>
                        </div>
                    </div>
                </template>
                <h2>{{ W.signals_title }}</h2>
                <ol>
                    <li v-for="(s, i) in W.signals" :key="i">{{ s }}</li>
                </ol>
                <template v-if="W.brief.team">
                    <h2>{{ W.brief.team_title }}</h2>
                    <p>{{ W.brief.team }}</p>
                </template>
                <div class="foot">
                    <span></span
                    ><button class="btn main" @click="go(1)">
                        {{ C.go_to_table }}
                    </button>
                </div>
            </section>

            <!-- 2. The table -->
            <section v-else-if="step === 1">
                <h1>{{ C.table.title }}</h1>
                <p>
                    {{
                        t(C.table.lead, {
                            count:
                                people.length > 3
                                    ? C.table.five
                                    : C.table.three,
                        })
                    }}
                </p>
                <div class="two grid">
                    <div>
                        <div class="who">
                            <button
                                v-for="p in people"
                                :key="p.key"
                                :class="{ on: p.key === person }"
                                @click="person = p.key"
                            >
                                {{ p.name }}<br /><small>{{ p.role }}</small>
                            </button>
                        </div>
                        <div ref="chatBox" class="chat" aria-live="polite">
                            <p class="msg">{{ current?.opens }}</p>
                            <template
                                v-for="(m, i) in current?.messages ?? []"
                                :key="i"
                            >
                                <p class="msg me">{{ m.question }}</p>
                                <p class="msg" :class="{ new: m.new }">
                                    {{ m.answer }}
                                </p>
                            </template>
                        </div>
                        <form class="ask" @submit.prevent="ask(question)">
                            <input
                                v-model="question"
                                :placeholder="
                                    t(C.table.ask_placeholder, {
                                        name: firstName,
                                    })
                                "
                                autocomplete="off"
                                :aria-label="
                                    t(C.table.ask_placeholder, {
                                        name: firstName,
                                    })
                                "
                                :disabled="confirmed"
                            />
                            <button
                                class="btn main"
                                :disabled="
                                    confirmed ||
                                    saving ||
                                    asked >= 8 ||
                                    minutesLeft < 1
                                "
                            >
                                {{ C.table.ask }}
                            </button>
                        </form>
                        <p v-if="errors.question" class="error">
                            {{ errors.question }}
                        </p>
                        <div class="sugg">
                            <button
                                v-for="s in current?.suggested ?? []"
                                :key="s"
                                class="btn"
                                :disabled="
                                    confirmed ||
                                    saving ||
                                    asked >= 8 ||
                                    minutesLeft < 1
                                "
                                @click="ask(s)"
                            >
                                {{ s }}
                            </button>
                        </div>
                        <p class="note">
                            {{
                                t(C.table.left, {
                                    count: Math.max(0, 8 - asked),
                                    name: firstName,
                                })
                            }}
                        </p>
                    </div>
                    <div class="card panel">
                        <h3>{{ plan.title }}</h3>
                        <p>{{ plan.lead }}</p>
                        <p>
                            <b
                                >{{ plan.claim.split('{figure}')[0]
                                }}<span class="slab">{{ plan.figure }}</span
                                >{{ plan.claim.split('{figure}')[1] }}</b
                            >
                        </p>
                        <p class="soft">
                            {{
                                t(C.table.checking_used, { used: checkingUsed })
                            }}
                        </p>
                        <template v-for="k in plan.checks" :key="k.id">
                            <div v-if="k.found" class="found">
                                <b>{{ k.label }}.</b> {{ k.found }}
                            </div>
                            <button
                                v-else
                                class="btn wide"
                                :disabled="
                                    confirmed ||
                                    saving ||
                                    checkingUsed + k.minutes > 10 ||
                                    minutesLeft < k.minutes
                                "
                                @click="check(k.id)"
                            >
                                {{
                                    t(C.table.check_button, {
                                        label: k.label,
                                        minutes: k.minutes,
                                    })
                                }}
                            </button>
                        </template>
                        <p v-if="errors.check" class="error">
                            {{ errors.check }}
                        </p>
                    </div>
                </div>
                <div class="foot">
                    <button class="btn" @click="go(0)">{{ C.back }}</button>
                    <button class="btn main" @click="go(2)">
                        {{ C.next_moment }}
                    </button>
                </div>
            </section>

            <!-- 3. One moment -->
            <section v-else-if="step === 2">
                <h1>{{ W.moment.title }}</h1>
                <div class="quote">
                    <p>
                        <b>{{ W.moment.who }}:</b> "{{ W.moment.says }}"
                    </p>
                </div>
                <p v-if="year === 2031">{{ helen }}</p>
                <div v-if="year === 2027" class="row">
                    <label for="offers"
                        ><b>{{ W.moment.number_label }}</b>
                        <span class="soft">{{
                            W.moment.number_hint
                        }}</span></label
                    >
                    <input
                        id="offers"
                        v-model.number="offers"
                        type="number"
                        min="0"
                        max="230"
                        step="5"
                        class="short"
                        :disabled="confirmed"
                    />
                </div>
                <div v-if="year === 2029" class="row">
                    <label
                        ><b>{{ W.moment.choice_label }}</b
                        ><br /><small class="soft">{{
                            W.moment.choice_hint
                        }}</small></label
                    >
                    <div class="seg">
                        <button
                            v-for="c in W.moment.choices"
                            :key="c[0]"
                            :class="{ on: planning === c[0] }"
                            :disabled="confirmed"
                            @click="planning = c[0]"
                        >
                            {{ c[1] }}
                        </button>
                    </div>
                </div>
                <label for="moment"
                    ><b>{{ W.moment.text_label }}</b></label
                >
                <textarea
                    id="moment"
                    v-model="momentText"
                    :placeholder="W.moment.text_placeholder"
                    :disabled="confirmed"
                ></textarea>
                <div class="foot">
                    <button class="btn" @click="go(1)">{{ C.back }}</button>
                    <button class="btn main" @click="go(3)">
                        {{ C.next_decide }}
                    </button>
                </div>
            </section>

            <!-- 4. Decide -->
            <section v-else-if="step === 3">
                <h1>{{ t(K.title, { year }) }}</h1>
                <p>{{ year === 2027 ? K.lead_first : K.lead_later }}</p>
                <fieldset :disabled="confirmed">
                    <h2>{{ K.governance.title }}</h2>
                    <p class="soft">{{ K.governance.hint }}</p>
                    <table>
                        <thead>
                            <tr>
                                <th v-for="c in K.governance.columns" :key="c">
                                    {{ c }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="w in K.governance.workflows" :key="w[0]">
                                <td>
                                    <b>{{ w[1] }}</b
                                    ><br /><small class="soft">{{
                                        w[2]
                                    }}</small>
                                </td>
                                <td>{{ w[3] }}</td>
                                <td>
                                    <select
                                        v-model="form.workflows[w[0]]"
                                        :aria-label="w[1]"
                                    >
                                        <option
                                            v-for="m in K.governance.modes"
                                            :key="m[0]"
                                            :value="m[0]"
                                        >
                                            {{ m[1] }}
                                        </option>
                                    </select>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div class="row">
                        <label
                            ><b>{{ K.governance.oversight_label }}</b
                            ><br /><small class="soft">{{
                                K.governance.oversight_hint
                            }}</small></label
                        >
                        <div class="seg">
                            <button
                                v-for="o in K.governance.oversight"
                                :key="o[0]"
                                type="button"
                                :class="{ on: form.oversight === o[0] }"
                                @click="form.oversight = o[0]"
                            >
                                {{ o[1] }}
                            </button>
                        </div>
                    </div>
                    <div v-if="year > 2027" class="row">
                        <label
                            ><b>{{ K.governance.checking_label }}</b
                            ><br /><small class="soft">{{
                                K.governance.checking_hint
                            }}</small></label
                        >
                        <div class="seg">
                            <button
                                v-for="o in K.chro.yes_no"
                                :key="String(o[0])"
                                type="button"
                                :class="{ on: form.checking === o[0] }"
                                @click="form.checking = o[0]"
                            >
                                {{ o[1] }}
                            </button>
                        </div>
                    </div>

                    <h2>{{ K.learning.title }}</h2>
                    <div class="row">
                        <label for="tutor"
                            ><b>{{ K.learning.tutor_label }}</b
                            ><br /><small class="soft">{{
                                K.learning.tutor_hint
                            }}</small></label
                        >
                        <input
                            id="tutor"
                            v-model.number="form.tutor"
                            type="range"
                            min="0"
                            max="100"
                            step="10"
                            class="short"
                        /><output>{{ form.tutor }}%</output>
                    </div>
                    <div class="row">
                        <label
                            ><b>{{ K.learning.mentoring_label }}</b></label
                        >
                        <div class="seg">
                            <button
                                v-for="o in K.learning.mentoring"
                                :key="o[0]"
                                type="button"
                                :class="{ on: form.mentoring === o[0] }"
                                @click="form.mentoring = o[0]"
                            >
                                {{ o[1] }}
                            </button>
                        </div>
                    </div>
                    <div v-if="year > 2027" class="row">
                        <label
                            ><b>{{ K.learning.knowledge_label }}</b
                            ><br /><small class="soft">{{
                                K.learning.knowledge_hint
                            }}</small></label
                        >
                        <div class="seg">
                            <button
                                v-for="o in K.learning.knowledge"
                                :key="o[0]"
                                type="button"
                                :class="{ on: form.knowledge === o[0] }"
                                @click="form.knowledge = o[0]"
                            >
                                {{ o[1] }}
                            </button>
                        </div>
                    </div>

                    <h2>{{ K.talent.title }}</h2>
                    <p v-if="year === 2027">
                        {{ t(K.talent.offers_line, { offers }) }}
                    </p>
                    <div v-else class="row">
                        <label for="del"
                            ><b>{{ K.talent.delivery_label }}</b>
                            <span class="soft">{{
                                K.talent.delivery_hint
                            }}</span></label
                        >
                        <input
                            id="del"
                            v-model.number="form.delivery_intake"
                            type="range"
                            min="0"
                            max="100"
                            step="5"
                            class="short"
                        /><output>{{ form.delivery_intake }}%</output>
                    </div>
                    <div class="row">
                        <label for="ops"
                            ><b>{{ K.talent.ops_label }}</b>
                            <span class="soft">{{
                                K.talent.ops_hint
                            }}</span></label
                        >
                        <input
                            id="ops"
                            v-model.number="form.ops_intake"
                            type="range"
                            min="0"
                            max="100"
                            step="5"
                            class="short"
                        /><output>{{ form.ops_intake }}%</output>
                    </div>
                    <div class="row">
                        <label
                            ><b>{{ K.talent.lateral_label }}</b
                            ><br /><small class="soft">{{
                                K.talent.lateral_hint
                            }}</small></label
                        >
                        <div class="seg">
                            <button
                                v-for="o in K.talent.lateral"
                                :key="o[0]"
                                type="button"
                                :class="{ on: form.lateral === o[0] }"
                                @click="form.lateral = o[0]"
                            >
                                {{ o[1] }}
                            </button>
                        </div>
                    </div>
                    <div class="row">
                        <label
                            ><b>{{ K.talent.lay_delivery_label }}</b
                            ><br /><small class="soft">{{
                                K.talent.lay_hint
                            }}</small></label
                        >
                        <div class="seg">
                            <button
                                v-for="o in K.talent.lay"
                                :key="o[0]"
                                type="button"
                                :class="{ on: form.lay_delivery === o[0] }"
                                @click="form.lay_delivery = o[0]"
                            >
                                {{ o[1] }}
                            </button>
                        </div>
                    </div>
                    <div class="row">
                        <label
                            ><b>{{ K.talent.lay_ops_label }}</b></label
                        >
                        <div class="seg">
                            <button
                                v-for="o in K.talent.lay"
                                :key="o[0]"
                                type="button"
                                :class="{ on: form.lay_ops === o[0] }"
                                @click="form.lay_ops = o[0]"
                            >
                                {{ o[1] }}
                            </button>
                        </div>
                    </div>

                    <h2>{{ K.chro.title }}</h2>
                    <div class="row">
                        <label
                            ><b>{{ K.chro.transparency_label }}</b></label
                        >
                        <div class="seg">
                            <button
                                v-for="o in K.chro.yes_no"
                                :key="String(o[0])"
                                type="button"
                                :class="{ on: form.transparency === o[0] }"
                                @click="form.transparency = o[0]"
                            >
                                {{ o[1] }}
                            </button>
                        </div>
                    </div>
                    <div class="row">
                        <label
                            ><b>{{ K.chro.gains_label }}</b>
                            <span class="soft">{{
                                K.chro.gains_hint
                            }}</span></label
                        >
                        <div class="seg">
                            <button
                                v-for="o in K.chro.yes_no"
                                :key="String(o[0])"
                                type="button"
                                :class="{ on: form.share_gains === o[0] }"
                                @click="form.share_gains = o[0]"
                            >
                                {{ o[1] }}
                            </button>
                        </div>
                    </div>
                    <template v-if="year > 2027">
                        <p>
                            {{
                                t(K.chro.planning_line, {
                                    planning: K.chro.planning_names[planning],
                                })
                            }}
                        </p>
                        <div class="row">
                            <label
                                ><b>{{ K.chro.board_label }}</b>
                                <span class="soft">{{
                                    K.chro.board_hint
                                }}</span></label
                            >
                            <div class="seg">
                                <button
                                    v-for="o in K.chro.yes_no"
                                    :key="String(o[0])"
                                    type="button"
                                    :class="{ on: form.board_report === o[0] }"
                                    @click="form.board_report = o[0]"
                                >
                                    {{ o[1] }}
                                </button>
                            </div>
                        </div>
                    </template>
                </fieldset>
                <p v-if="errors.confirm" class="error">{{ errors.confirm }}</p>
                <div class="foot">
                    <button class="btn" @click="go(2)">{{ C.back }}</button>
                    <button v-if="!confirmed" class="btn main" @click="confirm">
                        {{ t(K.confirm, { year }) }}
                    </button>
                </div>
            </section>

            <!-- 5. Results -->
            <section v-else-if="step === 4 && results && lastRan">
                <h1>
                    {{
                        t(R.title, {
                            years: results.years
                                .map((y) => y.year)
                                .join(' and '),
                        })
                    }}
                </h1>
                <div class="two grid">
                    <div>
                        <p class="soft">
                            {{ t(R.profit_in, { year: lastRan.year }) }}
                        </p>
                        <div class="big">
                            <span class="slab">{{
                                money(lastRan.profit)
                            }}</span>
                        </div>
                        <p class="soft">
                            {{
                                results.years
                                    .map((y) => `${y.year}: ${money(y.profit)}`)
                                    .join(' · ')
                            }}
                        </p>
                    </div>
                    <div class="card panel">
                        <p>{{ R.stories[band] }}</p>
                        <p v-for="n in notes" :key="n">{{ n }}</p>
                    </div>
                </div>
                <div class="kpis">
                    <div v-for="k in kpis(lastRan)" :key="k[1]" class="kpi">
                        <b>{{ k[0] }}</b
                        ><span>{{ k[1] }}</span>
                    </div>
                </div>
                <h2>{{ t(R.levels_title, { year: lastRan.year }) }}</h2>
                <table>
                    <thead>
                        <tr>
                            <th
                                v-for="(c, i) in R.levels_columns"
                                :key="i"
                                :class="{ n: i > 0 }"
                            >
                                {{ c }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(l, i) in R.levels" :key="l">
                            <td>{{ l }}</td>
                            <td class="n">
                                {{ Math.round(lastRan.delivery_levels[i]) }}
                            </td>
                            <td class="n soft">{{ START.delivery[i] }}</td>
                            <td class="n">
                                {{ Math.round(lastRan.operations_levels[i]) }}
                            </td>
                            <td class="n soft">{{ START.operations[i] }}</td>
                        </tr>
                    </tbody>
                </table>
                <h2>{{ R.people_title }}</h2>
                <div v-for="q in R.quotes[band]" :key="q[0]" class="quote">
                    <b>{{ q[0] }}:</b> "{{ q[1] }}"
                </div>
                <h2>{{ R.ahead_title }}</h2>
                <p>
                    {{
                        t(R.ahead_question, {
                            when:
                                year < 2031
                                    ? t(R.ahead_when_next, { year: year + 2 })
                                    : R.ahead_when_last,
                        })
                    }}
                </p>
                <textarea
                    v-model="paragraph"
                    :placeholder="R.ahead_placeholder"
                    @blur="saveParagraph()"
                ></textarea>
                <div class="foot">
                    <button class="btn" @click="go(3)">{{ C.back }}</button>
                    <button class="btn main" @click="nextYear">
                        {{
                            year < 2031
                                ? t(R.next_year, { year: year + 2 })
                                : R.to_replay
                        }}
                    </button>
                </div>
            </section>
        </main>
    </div>
</template>

<style scoped>
.chro {
    --bg: #fff;
    --ink: #101418;
    --soft: #5b6470;
    --line: #e3e6ea;
    --panel: #f6f7f8;
    --hi: #ffd84a;
    --red: #ec1a22;
    background: var(--bg);
    color: var(--ink);
    min-height: 100vh;
    font:
        16px/1.55 Manrope,
        system-ui,
        sans-serif;
}
header {
    position: sticky;
    top: 0;
    z-index: 5;
    background: var(--bg);
    border-bottom: 1px solid var(--line);
}
.bar {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px max(4vw, 16px);
    flex-wrap: wrap;
}
.badge {
    width: 34px;
    height: 34px;
    border-radius: 7px;
    background: var(--red);
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
    color: var(--soft);
    font-size: 13px;
}
.team {
    margin-left: auto;
    font-size: 14px;
    color: var(--soft);
}
.clock {
    font-size: 14px;
    padding: 4px 10px;
    border-radius: 20px;
    background: var(--panel);
}
.out {
    font-size: 14px;
    color: var(--soft);
}
nav {
    display: flex;
    gap: 4px;
    padding: 0 max(4vw, 16px) 8px;
    overflow-x: auto;
}
nav button {
    flex: none;
    border: 1px solid var(--line);
    background: var(--bg);
    color: var(--soft);
    border-radius: 20px;
    padding: 6px 12px;
    font-weight: 600;
    font-size: 14px;
    min-height: 36px;
}
nav button.on {
    background: var(--ink);
    color: var(--bg);
}
nav button:disabled {
    opacity: 0.45;
}
main {
    max-width: 1100px;
    margin: 0 auto;
    padding: clamp(20px, 3vw, 40px) max(4vw, 16px) 80px;
}
h1 {
    font-size: clamp(28px, 4vw, 42px);
    line-height: 1.08;
    margin: 0 0 12px;
    font-weight: 800;
}
h2 {
    font-size: 22px;
    margin: 28px 0 10px;
    font-weight: 700;
}
h3 {
    font-size: 17px;
    margin: 0 0 6px;
    font-weight: 700;
}
p {
    max-width: 70ch;
    margin: 0 0 12px;
}
ol {
    list-style: decimal;
    padding-left: 22px;
}
.soft {
    color: var(--soft);
}
.slab {
    background: var(--hi);
    color: #101418;
    padding: 2px 8px;
    border-radius: 4px;
    font-weight: 800;
}
.grid.two {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 420px), 1fr));
    gap: 16px;
    align-items: start;
}
.card {
    border: 1px solid var(--line);
    border-radius: 10px;
    padding: 18px;
}
.panel {
    background: var(--panel);
}
.who {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 10px;
}
.who button,
.btn {
    border: 1px solid var(--line);
    background: var(--bg);
    color: var(--ink);
    border-radius: 8px;
    padding: 10px 14px;
    font-weight: 600;
    font-size: 15px;
    min-height: 44px;
}
.who button.on,
.btn.main {
    background: var(--ink);
    color: var(--bg);
    border-color: var(--ink);
}
.btn:disabled {
    opacity: 0.45;
}
.btn.wide {
    width: 100%;
    margin: 4px 0;
}
.chat {
    border: 1px solid var(--line);
    border-radius: 10px;
    padding: 12px;
    height: 340px;
    overflow-y: auto;
    background: var(--panel);
}
.msg {
    margin: 0 0 10px;
    max-width: 85%;
    padding: 8px 12px;
    border-radius: 10px;
    background: var(--bg);
    border: 1px solid var(--line);
}
.msg.me {
    margin-left: auto;
    background: var(--ink);
    color: var(--bg);
}
.msg.new {
    box-shadow: 0 0 0 2px var(--hi) inset;
}
.ask {
    display: flex;
    gap: 8px;
    margin-top: 8px;
}
input,
select,
textarea {
    font-size: 16px;
    color: var(--ink);
    background: var(--bg);
    border: 1px solid var(--line);
    border-radius: 8px;
    padding: 10px;
    min-height: 44px;
    width: 100%;
}
input[type='range'] {
    padding: 0;
}
.short {
    max-width: 220px;
}
textarea {
    min-height: 120px;
}
.sugg {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 8px;
}
.sugg .btn {
    font-size: 13px;
    padding: 6px 10px;
    min-height: 36px;
}
table {
    border-collapse: collapse;
    width: 100%;
}
th,
td {
    text-align: left;
    padding: 8px 6px;
    border-bottom: 1px solid var(--line);
}
.n {
    text-align: right;
    white-space: nowrap;
}
.row {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
    margin: 10px 0;
}
.row label {
    flex: 1 1 260px;
}
.seg {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.seg button {
    border: 1px solid var(--line);
    background: var(--bg);
    border-radius: 8px;
    padding: 8px 12px;
    min-height: 40px;
    font-weight: 600;
    font-size: 14px;
}
.seg button.on {
    background: var(--ink);
    color: var(--bg);
}
fieldset {
    border: 0;
    padding: 0;
    margin: 0;
}
.kpis {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 160px), 1fr));
    gap: 12px;
    margin: 16px 0;
}
.kpi {
    border: 1px solid var(--line);
    border-radius: 10px;
    padding: 14px;
}
.kpi b {
    display: block;
    font-size: clamp(20px, 2.4vw, 26px);
    white-space: nowrap;
}
.kpi span {
    font-size: 13px;
    color: var(--soft);
}
.big {
    font-size: clamp(34px, 5vw, 52px);
    font-weight: 800;
    line-height: 1.1;
}
.quote {
    border-left: 4px solid var(--hi);
    padding: 4px 12px;
    margin: 10px 0;
}
.found {
    border: 1px solid var(--line);
    border-left: 4px solid var(--hi);
    border-radius: 8px;
    padding: 10px 12px;
    margin: 8px 0;
    background: var(--bg);
}
.foot {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    margin-top: 28px;
    flex-wrap: wrap;
}
.note {
    font-size: 13px;
    color: var(--soft);
}
.error {
    color: #a3261b;
    font-size: 14px;
}
</style>
