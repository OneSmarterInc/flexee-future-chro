<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps<{
    name: string;
    steps: { title: string; text: string }[] | null;
    words: Record<string, string>;
}>();

const open = ref(Boolean(props.steps && props.steps.length));
const i = ref(0);

function finish(): void {
    open.value = false;
    router.post(
        `/help/tours/${props.name}/done`,
        {},
        { preserveScroll: true, preserveState: true },
    );
}
</script>

<template>
    <div
        v-if="open && steps"
        class="tour"
        role="dialog"
        aria-modal="false"
        :aria-label="steps[i].title"
    >
        <p class="count">{{ i + 1 }} / {{ steps.length }}</p>
        <h2>{{ steps[i].title }}</h2>
        <p>{{ steps[i].text }}</p>
        <div class="row">
            <button class="ghost" @click="finish">{{ words.tour_skip }}</button>
            <span class="grow"></span>
            <button v-if="i > 0" @click="i--">{{ words.tour_back }}</button>
            <button v-if="i < steps.length - 1" class="main" @click="i++">
                {{ words.tour_next }}
            </button>
            <button v-else class="main" @click="finish">
                {{ words.tour_done }}
            </button>
        </div>
    </div>
</template>

<style scoped>
.tour {
    position: fixed;
    right: max(3vw, 12px);
    bottom: max(3vw, 12px);
    z-index: 50;
    width: min(380px, calc(100vw - 24px));
    background: #fff;
    color: #101418;
    border: 1px solid #e3e6ea;
    border-top: 4px solid #ffd84a;
    border-radius: 10px;
    box-shadow: 0 10px 30px rgba(16, 20, 24, 0.18);
    padding: 16px;
    font:
        15px/1.5 Manrope,
        system-ui,
        sans-serif;
}
h2 {
    font-size: 18px;
    font-weight: 800;
    margin: 0 0 6px;
}
p {
    margin: 0 0 10px;
}
.count {
    font-size: 12px;
    color: #5b6470;
    margin: 0;
}
.row {
    display: flex;
    gap: 8px;
    align-items: center;
}
.grow {
    flex: 1;
}
button {
    border: 1px solid #c9ced4;
    background: #fff;
    border-radius: 8px;
    padding: 8px 12px;
    font-weight: 600;
    min-height: 40px;
}
button.main {
    background: #101418;
    color: #fff;
    border-color: #101418;
}
button.ghost {
    border-color: transparent;
    color: #5b6470;
}
</style>
