<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps<{
    code: string;
    title: string;
    intro: string;
    signedIn: string | null;
    blocked: string | null;
    words: Record<string, any>;
}>();

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});
const J = props.words.join;
const joinError = (): string | undefined =>
    (form.errors as Record<string, string | undefined>).join;

function submit(): void {
    form.post(`/join/${props.code}`);
}
</script>

<template>
    <Head :title="title" />
    <main class="join">
        <div class="badge" aria-hidden="true">F</div>
        <p class="product">{{ words.common.product }}</p>
        <h1>{{ title }}</h1>
        <p>{{ intro }}</p>
        <p v-if="blocked" class="error" role="alert">{{ blocked }}</p>
        <template v-else-if="signedIn">
            <p>{{ signedIn }}</p>
            <p v-if="joinError()" class="error" role="alert">
                {{ joinError() }}
            </p>
            <button class="btn" :disabled="form.processing" @click="submit">
                {{ J.join_button }}
            </button>
        </template>
        <form v-else @submit.prevent="submit">
            <label
                >{{ J.name }}
                <input v-model="form.name" autocomplete="name" required />
            </label>
            <p v-if="form.errors.name" class="error">{{ form.errors.name }}</p>
            <label
                >{{ J.email }}
                <input
                    v-model="form.email"
                    type="email"
                    autocomplete="email"
                    required
                />
            </label>
            <p v-if="form.errors.email" class="error">
                {{ form.errors.email }}
            </p>
            <label
                >{{ J.password }}
                <input
                    v-model="form.password"
                    type="password"
                    autocomplete="new-password"
                    required
                />
            </label>
            <p v-if="form.errors.password" class="error">
                {{ form.errors.password }}
            </p>
            <label
                >{{ J.password_confirmation }}
                <input
                    v-model="form.password_confirmation"
                    type="password"
                    autocomplete="new-password"
                    required
                />
            </label>
            <p v-if="joinError()" class="error" role="alert">
                {{ joinError() }}
            </p>
            <button class="btn" :disabled="form.processing">
                {{ J.join_button }}
            </button>
        </form>
    </main>
</template>

<style scoped>
.join {
    max-width: 460px;
    margin: 0 auto;
    padding: 48px 20px;
    font:
        16px/1.55 Manrope,
        system-ui,
        sans-serif;
    color: #101418;
}
.badge {
    width: 36px;
    height: 36px;
    border-radius: 7px;
    background: #ec1a22;
    color: #fff;
    font-weight: 800;
    display: grid;
    place-items: center;
}
.product {
    font-weight: 800;
    margin: 8px 0 20px;
}
h1 {
    font-size: 30px;
    font-weight: 800;
    margin: 0 0 10px;
}
label {
    display: block;
    margin: 12px 0 4px;
    font-weight: 600;
}
input {
    display: block;
    width: 100%;
    margin-top: 4px;
    border: 1px solid #c9ced4;
    border-radius: 8px;
    padding: 10px;
    min-height: 44px;
    font-size: 16px;
}
.btn {
    margin-top: 16px;
    background: #101418;
    color: #fff;
    border: 0;
    border-radius: 8px;
    padding: 12px 18px;
    font-weight: 700;
    min-height: 44px;
}
.error {
    color: #a3261b;
    font-size: 14px;
    margin: 4px 0;
}
</style>
