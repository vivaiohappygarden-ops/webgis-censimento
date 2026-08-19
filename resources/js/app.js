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

// PWA operatore: il service worker rende /operatore apribile anche offline.
// L'ambito e' ristretto a /operatore di proposito: sullo stesso indirizzo puo'
// esserci il portale pubblico di un Comune, e sul telefono di un cittadino non
// deve finire la cache dell'app di campo.
if ('serviceWorker' in navigator) {
    window.addEventListener('load', async () => {
        try {
            // Installazioni vecchie con ambito su tutto il sito: vanno rimosse,
            // altrimenti continuerebbero a controllare anche le pagine pubbliche
            const registrazioni = await navigator.serviceWorker.getRegistrations();
            const radice = new URL('/', window.location.origin).href;
            await Promise.all(registrazioni
                .filter((r) => r.scope === radice)
                .map((r) => r.unregister()));

            await navigator.serviceWorker.register('/sw.js', { scope: '/operatore' });
        } catch {
            // Senza service worker l'app resta usabile online (P5: degrado, mai blocco)
        }
    });
}
