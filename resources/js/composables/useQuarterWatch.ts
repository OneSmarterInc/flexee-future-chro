import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';

export type NewerBar = { text: string; go: string; href: string };

/**
 * Checks for news every 30 seconds (items 23 and 24). When the quarter being read has moved on
 * (closed, or published) the page reloads in place. When a newer quarter is open, a bar offers it.
 * The page never moves a student by itself.
 */
export function useQuarterWatch(
    number: () => number,
    status: () => string,
    off: () => boolean,
) {
    const bar = ref<NewerBar | null>(null);
    let timer: ReturnType<typeof setInterval> | null = null;

    async function check(): Promise<void> {
        if (off() || document.hidden) {
            return;
        }

        try {
            const response = await fetch('/play/status', {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                return;
            }

            const data = (await response.json()) as {
                quarters: Record<string, string>;
                newest: number | null;
                bar: NewerBar | null;
            };
            const now = data.quarters[String(number())];

            if (now !== undefined && now !== status()) {
                router.reload();
            }

            bar.value =
                data.newest !== null && data.newest > number()
                    ? data.bar
                    : null;
        } catch {
            // A missed check is fine. The next one tries again.
        }
    }

    onMounted(() => {
        timer = setInterval(check, 30000);
    });
    onBeforeUnmount(() => {
        if (timer !== null) {
            clearInterval(timer);
        }
    });

    return { bar, check };
}
