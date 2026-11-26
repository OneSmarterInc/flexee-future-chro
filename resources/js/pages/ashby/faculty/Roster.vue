<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import Passwords from '@/components/ashby/Passwords.vue';
import StaffNav from '@/components/ashby/StaffNav.vue';
import '@/components/ashby/staff.css';

type Person = {
    id: number;
    name: string;
    email: string;
    paused: boolean;
    paid: boolean;
    team: number | null;
    seat: string | null;
};
const props = defineProps<{
    section: { id: number; name: string; capacity: number | null };
    isAdmin: boolean;
    teams: {
        id: number;
        name: string;
        members: number[];
        can_delete: boolean;
        size_note: boolean;
    }[];
    people: Person[];
    seats: string[];
    counts: { participants: number; placed: number; teams: number };
    joinLink: string | null;
    instructors: { name: string; email: string; lead: boolean }[];
    passwords:
        | {
              email: string;
              name: string;
              password: string | null;
              note: string;
          }[]
        | null;
    notice: string | null;
    words: Record<string, any>;
}>();

const R = computed(() => props.words.faculty.roster);
const seatNames = computed(
    () => props.words.common.seats as Record<string, string>,
);
const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string>,
);
const base = computed(() => `/faculty/${props.section.id}/roster`);
const add = reactive({ list: '', team_id: null as number | null });
const capacity = ref<number | null>(props.section.capacity);
const names = reactive<Record<number, string>>(
    Object.fromEntries(props.teams.map((t) => [t.id, t.name])),
);
const byId = computed(
    () =>
        Object.fromEntries(props.people.map((p) => [p.id, p])) as Record<
            number,
            Person
        >,
);

function t(text: string, vars: Record<string, string | number> = {}): string {
    return String(text ?? '').replace(/\{(\w+)\}/g, (m, k) =>
        k in vars ? String(vars[k]) : m,
    );
}
function post(
    url: string,
    data: Record<string, any> = {},
    reset?: () => void,
): void {
    router.post(url, data, {
        preserveScroll: true,
        onSuccess: () => reset?.(),
    });
}
function teamName(id: number | null): string {
    return (
        props.teams.find((x) => x.id === id)?.name ?? R.value.people.unplaced
    );
}
function move(p: Person, e: Event): void {
    const v = (e.target as HTMLSelectElement).value;
    post(`${base.value}/people/${p.id}/move`, {
        team_id: v === '' ? null : Number(v),
    });
}
</script>

<template>
    <Head :title="R.title" />
    <div class="staff">
        <header>
            <div class="badge" aria-hidden="true">F</div>
            <div class="brand">
                {{ words.common.product
                }}<small>{{ R.title }} · {{ section.name }}</small>
            </div>
            <Link href="/logout" method="post" as="button" class="out">{{
                words.common.sign_out
            }}</Link>
        </header>
        <main>
            <StaffNav
                :section-id="section.id"
                here="roster"
                :is-admin="isAdmin"
                :words="words"
            />
            <h1>{{ R.title }}</h1>
            <p class="soft">{{ R.lead }}</p>
            <p>
                {{
                    t(R.counts, {
                        participants: counts.participants,
                        placed: counts.placed,
                        teams: counts.teams,
                    })
                }}
            </p>
            <p v-for="(e, k) in errors" :key="k" class="error" role="alert">
                {{ e }}
            </p>
            <p v-if="notice" class="error">{{ notice }}</p>
            <Passwords
                :passwords="passwords"
                :words="words.faculty.passwords"
            />

            <h2>{{ R.add.title }}</h2>
            <p class="soft small">{{ R.add.hint }}</p>
            <form
                @submit.prevent="
                    post(`${base}/add`, add, () => (add.list = ''))
                "
            >
                <textarea
                    v-model="add.list"
                    rows="5"
                    style="
                        width: 100%;
                        border: 1px solid #c9ced4;
                        border-radius: 8px;
                        padding: 8px;
                    "
                    :aria-label="R.add.title"
                ></textarea>
                <div class="row" style="margin-top: 6px">
                    <label class="small"
                        >{{ R.add.to_team }}
                        <select v-model="add.team_id">
                            <option :value="null">{{ R.add.no_team }}</option>
                            <option
                                v-for="tm in teams"
                                :key="tm.id"
                                :value="tm.id"
                            >
                                {{ tm.name }}
                            </option>
                        </select>
                    </label>
                    <button class="btn main">{{ R.add.button }}</button>
                </div>
            </form>

            <h2>{{ R.join.title }}</h2>
            <p v-if="joinLink">
                <code>{{ joinLink }}</code>
            </p>
            <p v-else class="soft">{{ R.join.none }}</p>
            <div class="row">
                <button class="btn" @click="post(`${base}/join-code`)">
                    {{ joinLink ? R.join.replace : R.join.make }}
                </button>
                <label class="small"
                    >{{ R.join.limit }}
                    <input
                        v-model.number="capacity"
                        type="number"
                        min="1"
                        :placeholder="R.join.no_limit"
                        style="width: 100px"
                    />
                </label>
                <button
                    class="btn"
                    @click="
                        post(`${base}/capacity`, { capacity: capacity || null })
                    "
                >
                    {{ R.join.save_limit }}
                </button>
            </div>

            <h2>{{ R.teams.title }}</h2>
            <div class="row">
                <button class="btn main" @click="post(`${base}/teams/form`)">
                    {{ R.teams.form }}
                </button>
                <button class="btn" @click="post(`${base}/teams`)">
                    {{ R.teams.new }}
                </button>
            </div>
            <div v-for="tm in teams" :key="tm.id" class="card">
                <div class="row">
                    <input
                        v-model="names[tm.id]"
                        :aria-label="R.teams.rename"
                        style="max-width: 220px"
                    />
                    <button
                        class="btn"
                        @click="
                            post(`${base}/teams/${tm.id}`, {
                                name: names[tm.id],
                            })
                        "
                    >
                        {{ R.teams.rename }}
                    </button>
                    <button
                        v-if="tm.can_delete"
                        class="btn"
                        @click="post(`${base}/teams/${tm.id}/delete`)"
                    >
                        {{ R.teams.delete }}
                    </button>
                    <span v-if="tm.size_note" class="error small">{{
                        t(R.teams.size_note, { count: tm.members.length })
                    }}</span>
                </div>
                <p v-if="tm.members.length === 0" class="soft small">
                    {{ R.teams.empty }}
                </p>
                <ul class="small">
                    <li v-for="id in tm.members" :key="id">
                        {{ byId[id]?.name }} ·
                        <select
                            :value="byId[id]?.seat"
                            :aria-label="R.teams.seat"
                            @change="
                                post(`${base}/people/${id}/seat`, {
                                    seat: ($event.target as HTMLSelectElement)
                                        .value,
                                })
                            "
                        >
                            <option v-for="s in seats" :key="s" :value="s">
                                {{ seatNames[s] }}
                            </option>
                        </select>
                    </li>
                </ul>
            </div>

            <h2>{{ R.people.title }}</h2>
            <div class="wrap">
                <table>
                    <thead>
                        <tr>
                            <th v-for="(c, i) in R.people.columns" :key="i">
                                {{ c }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in people" :key="p.id">
                            <td>{{ p.name }}</td>
                            <td class="small">{{ p.email }}</td>
                            <td>
                                <select
                                    :value="p.team ?? ''"
                                    :aria-label="R.people.move"
                                    @change="move(p, $event)"
                                >
                                    <option value="">
                                        {{ R.people.off_team }}
                                    </option>
                                    <option
                                        v-for="tm in teams"
                                        :key="tm.id"
                                        :value="tm.id"
                                    >
                                        {{ tm.name }}
                                    </option>
                                </select>
                            </td>
                            <td class="small">
                                {{
                                    p.seat ? seatNames[p.seat] : teamName(null)
                                }}
                            </td>
                            <td>
                                <button
                                    class="btn"
                                    @click="
                                        post(`${base}/people/${p.id}/pause`, {
                                            paused: !p.paused,
                                        })
                                    "
                                >
                                    {{
                                        p.paused
                                            ? R.people.restore
                                            : R.people.pause
                                    }}
                                </button>
                                <span class="small soft">
                                    {{
                                        p.paused
                                            ? R.people.paused
                                            : R.people.active
                                    }}</span
                                >
                            </td>
                            <td>
                                <button
                                    class="btn"
                                    @click="
                                        post(`${base}/people/${p.id}/paid`, {
                                            paid: !p.paid,
                                        })
                                    "
                                >
                                    {{
                                        p.paid
                                            ? R.people.paid
                                            : R.people.not_paid
                                    }}
                                </button>
                            </td>
                            <td>
                                <div class="row">
                                    <button
                                        class="btn"
                                        @click="
                                            post(`${base}/people/${p.id}/reset`)
                                        "
                                    >
                                        {{ R.people.reset }}
                                    </button>
                                    <button
                                        class="btn"
                                        @click="
                                            post(
                                                `${base}/people/${p.id}/remove`,
                                            )
                                        "
                                    >
                                        {{ R.people.remove }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <h2>{{ R.instructors }}</h2>
            <ul class="small">
                <li v-for="i in instructors" :key="i.email">
                    {{ i.name }} · {{ i.email
                    }}<template v-if="i.lead"> ({{ R.lead_label }})</template>
                </li>
            </ul>
        </main>
    </div>
</template>
