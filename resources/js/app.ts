import { createInertiaApp } from '@inertiajs/vue3';

// The product name comes from the content pack, through a meta tag the page carries.
const appName =
    document.querySelector<HTMLMetaElement>('meta[name="product"]')?.content ||
    '';

// The pages still on Vue (welcome and the sim pages) carry their own layout.
// They move to Livewire one at a time (design/15-livewire-migration.md).
void createInertiaApp({
    title: (title) => (title ? `${appName}: ${title}` : appName),
    layout: () => null,
    progress: {
        color: '#4B5563',
    },
});
