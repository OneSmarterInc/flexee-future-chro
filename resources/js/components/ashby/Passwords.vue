<script setup lang="ts">
defineProps<{
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
</script>

<template>
    <div
        v-if="passwords && passwords.length"
        class="card"
        role="status"
        style="border-color: #ffd84a"
    >
        <h2 style="margin-top: 0">{{ words.title }}</h2>
        <p class="soft small">{{ words.lead }}</p>
        <div class="wrap">
            <table>
                <thead>
                    <tr>
                        <th v-for="c in words.columns" :key="c">{{ c }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="p in passwords" :key="p.email">
                        <td>{{ p.name }}</td>
                        <td>{{ p.email }}</td>
                        <td>
                            <code>{{ p.password ?? '' }}</code>
                        </td>
                        <td class="small">
                            {{ words.notes[p.note] ?? p.note }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
