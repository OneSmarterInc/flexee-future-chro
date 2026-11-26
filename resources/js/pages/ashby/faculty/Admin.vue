<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import Passwords from '@/components/ashby/Passwords.vue';
import StaffNav from '@/components/ashby/StaffNav.vue';
import Tour from '@/components/ashby/Tour.vue';
import '@/components/ashby/staff.css';

type Person = { id: number; name: string; email: string; role: string };
const props = defineProps<{
    tour?: { title: string; text: string }[] | null;
    tourWords?: Record<string, string>;
    classes: {
        id: number;
        name: string;
        format: string;
        length: string;
        lead: { id: number; name: string } | null;
        co: { id: number; name: string }[];
        people: string;
        open_through: number;
        can_delete: boolean;
    }[];
    instructors: Person[];
    timezones: string[];
    passwords:
        | {
              email: string;
              name: string;
              password: string | null;
              note: string;
          }[]
        | null;
    words: Record<string, any>;
}>();

const A = computed(() => props.words.faculty.admin);
const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string>,
);
const login = reactive({ name: '', email: '' });
const klass = reactive({
    name: '',
    format: 'workshop',
    length: 'slice',
    instructor_id: props.instructors[0]?.id ?? 0,
    teams: 0,
    capacity: null as number | null,
    timezone: 'America/New_York',
});
const pick = reactive<Record<number, number>>({});

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
</script>

<template>
    <Head :title="A.title" />
    <div class="staff">
        <header>
            <div class="badge" aria-hidden="true">F</div>
            <div class="brand">
                {{ words.common.product }}<small>{{ A.title }}</small>
            </div>
            <Link href="/logout" method="post" as="button" class="out">{{
                words.common.sign_out
            }}</Link>
        </header>
        <main>
            <StaffNav
                :section-id="null"
                here="admin"
                :is-admin="true"
                :words="words"
            />
            <h1>{{ A.title }}</h1>
            <p class="soft">{{ A.lead }}</p>
            <p v-for="(e, k) in errors" :key="k" class="error" role="alert">
                {{ e }}
            </p>
            <Passwords
                :passwords="passwords"
                :words="words.faculty.passwords"
            />

            <h2>{{ A.login.title }}</h2>
            <form
                class="row"
                @submit.prevent="
                    post('/admin/logins', login, () =>
                        Object.assign(login, { name: '', email: '' }),
                    )
                "
            >
                <input
                    v-model="login.name"
                    :placeholder="A.login.name"
                    :aria-label="A.login.name"
                    required
                />
                <input
                    v-model="login.email"
                    type="email"
                    :placeholder="A.login.email"
                    :aria-label="A.login.email"
                    required
                />
                <button class="btn main">{{ A.login.button }}</button>
            </form>

            <h2>{{ A.class.title }}</h2>
            <form
                class="row"
                @submit.prevent="
                    post('/admin/classes', klass, () => (klass.name = ''))
                "
            >
                <input
                    v-model="klass.name"
                    :placeholder="A.class.name"
                    :aria-label="A.class.name"
                    required
                />
                <select v-model="klass.length" :aria-label="A.class.length">
                    <option value="slice">
                        {{ words.faculty.length.slice }}
                    </option>
                    <option value="full">
                        {{ words.faculty.length.full }}
                    </option>
                </select>
                <select v-model="klass.format" :aria-label="A.class.format">
                    <option value="workshop">
                        {{ words.faculty.calendar.format.workshop }}
                    </option>
                    <option value="term">
                        {{ words.faculty.calendar.format.term }}
                    </option>
                </select>
                <select
                    v-model.number="klass.instructor_id"
                    :aria-label="A.class.instructor"
                >
                    <option v-for="i in instructors" :key="i.id" :value="i.id">
                        {{ i.name }}
                    </option>
                </select>
                <label class="small"
                    >{{ A.class.teams }}
                    <input
                        v-model.number="klass.teams"
                        type="number"
                        min="0"
                        max="40"
                        style="width: 70px"
                /></label>
                <label class="small"
                    >{{ A.class.capacity }}
                    <input
                        v-model.number="klass.capacity"
                        type="number"
                        min="1"
                        style="width: 90px"
                /></label>
                <select v-model="klass.timezone" :aria-label="A.class.timezone">
                    <option v-for="z in timezones" :key="z" :value="z">
                        {{ z }}
                    </option>
                </select>
                <button class="btn main">{{ A.class.button }}</button>
            </form>

            <h2>{{ A.classes.title }}</h2>
            <div class="wrap">
                <table>
                    <thead>
                        <tr>
                            <th v-for="c in A.classes.columns" :key="c">
                                {{ c }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in classes" :key="c.id">
                            <td>
                                <Link :href="`/faculty/${c.id}`">{{
                                    c.name
                                }}</Link>
                            </td>
                            <td>
                                {{ words.faculty.calendar.format[c.format] }} ·
                                {{ words.faculty.length[c.length] }}
                            </td>
                            <td>{{ c.lead?.name ?? '' }}</td>
                            <td>
                                <span
                                    v-for="co in c.co"
                                    :key="co.id"
                                    class="small"
                                >
                                    {{ co.name }}
                                    <button
                                        class="btn"
                                        style="
                                            min-height: 28px;
                                            padding: 2px 6px;
                                        "
                                        @click="
                                            post(
                                                `/admin/classes/${c.id}/co/${co.id}/remove`,
                                            )
                                        "
                                    >
                                        {{ A.classes.remove }}
                                    </button>
                                </span>
                            </td>
                            <td>{{ c.people }}</td>
                            <td>
                                {{
                                    c.open_through < 2027
                                        ? A.classes.not_started
                                        : t(A.classes.started, {
                                              year: c.open_through,
                                          })
                                }}
                            </td>
                            <td>
                                <div class="row">
                                    <select
                                        v-model.number="pick[c.id]"
                                        :aria-label="A.class.instructor"
                                    >
                                        <option
                                            v-for="i in instructors"
                                            :key="i.id"
                                            :value="i.id"
                                        >
                                            {{ i.name }}
                                        </option>
                                    </select>
                                    <button
                                        class="btn"
                                        :disabled="!pick[c.id]"
                                        @click="
                                            post(
                                                `/admin/classes/${c.id}/lead`,
                                                { instructor_id: pick[c.id] },
                                            )
                                        "
                                    >
                                        {{ A.classes.make_lead }}
                                    </button>
                                    <button
                                        class="btn"
                                        :disabled="!pick[c.id]"
                                        @click="
                                            post(`/admin/classes/${c.id}/co`, {
                                                instructor_id: pick[c.id],
                                            })
                                        "
                                    >
                                        {{ A.classes.add_co }}
                                    </button>
                                    <button
                                        v-if="c.can_delete"
                                        class="btn"
                                        :title="A.classes.delete_note"
                                        @click="
                                            post(
                                                `/admin/classes/${c.id}/delete`,
                                            )
                                        "
                                    >
                                        {{ A.classes.delete }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <h2>{{ A.people.title }}</h2>
            <ul class="small">
                <li v-for="i in instructors" :key="i.id">
                    {{ i.name }} · {{ i.email }} · {{ i.role }}
                </li>
            </ul>
        </main>
        <Tour v-if="tour" name="admin" :steps="tour" :words="tourWords ?? {}" />
    </div>
</template>
