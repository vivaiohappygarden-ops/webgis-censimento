import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            // Il portale pubblico ha un suo pacchetto: non deve caricare
            // l'applicazione gestionale né registrare il service worker
            // dell'app di campo sul telefono del cittadino
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/portale-mappa.js'],
            refresh: true,
        }),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
