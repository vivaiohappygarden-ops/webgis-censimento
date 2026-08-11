import './bootstrap';
import '../css/app.css';
import 'maplibre-gl/dist/maplibre-gl.css';

// Il worker di MapLibre deve passare dal bundler: senza questa registrazione
// il file non viene emesso nella build e la mappa resta vuota.
import { setWorkerUrl } from 'maplibre-gl';
import maplibreWorkerUrl from 'maplibre-gl/dist/maplibre-gl-worker.mjs?worker&url';

setWorkerUrl(maplibreWorkerUrl);

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';

createInertiaApp({
    title: (title) => (title ? `${title} — WebGIS Censimento` : 'WebGIS Censimento'),
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true });

        return pages[`./Pages/${name}.vue`];
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
    progress: {
        color: '#16a34a',
    },
});

// PWA operatore: il service worker rende /operatore apribile anche offline
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {
            // Senza service worker l'app resta usabile online (P5: degrado, mai blocco)
        });
    });
}
