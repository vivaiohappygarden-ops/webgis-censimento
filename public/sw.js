/*
 * Service worker della PWA operatore (Fase 3, v1).
 * Strategia minima e prevedibile:
 *  - asset compilati (/build/*): cache-first, sono immutabili (nome con hash);
 *  - navigazione su /operatore: network-first con ripiego sulla copia in cache,
 *    così l'app si apre anche senza rete;
 *  - tutto il resto (API incluse): solo rete — la coda offline vive in IndexedDB,
 *    non nella cache HTTP.
 */
const SHELL_CACHE = 'wg-shell-v1';
const ASSET_CACHE = 'wg-assets-v1';
const TILE_CACHE = 'wg-tiles-v1';
const TILE_CACHE_MAX = 2000; // ~2000 tile ≈ 30-60 MB: le zone viste online restano offline
const KNOWN_CACHES = [SHELL_CACHE, ASSET_CACHE, TILE_CACHE];

self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const names = await caches.keys();
        await Promise.all(names.filter((n) => ! KNOWN_CACHES.includes(n)).map((n) => caches.delete(n)));
        await self.clients.claim();
    })());
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;

    const url = new URL(request.url);

    // Tile cartografiche di sfondo: cache-first con tetto, così la mappa
    // dell'app operatore mostra le zone già visitate anche senza rete.
    // La scrittura in cache non deve MAI far fallire la risposta (quota piena,
    // risposte parziali): si serve la tile e si tenta la cache a parte.
    if (url.hostname === 'tile.openstreetmap.org') {
        event.respondWith((async () => {
            const cache = await caches.open(TILE_CACHE);
            const cached = await cache.match(request);
            if (cached) {
                // Rinfresca la posizione nell'ordine di inserimento (LRU
                // approssimato): al tetto si eliminano le tile meno usate,
                // non quelle della zona di lavoro corrente
                try {
                    await cache.put(request, cached.clone());
                } catch {
                    // best effort
                }
                return cached;
            }
            const response = await fetch(request);
            if (response.status === 200) {
                try {
                    await cache.put(request, response.clone());
                    const keys = await cache.keys();
                    if (keys.length > TILE_CACHE_MAX) {
                        await Promise.all(keys.slice(0, keys.length - TILE_CACHE_MAX).map((k) => cache.delete(k)));
                    }
                } catch {
                    // quota piena o risposta non memorizzabile: la tile arriva comunque
                }
            }
            return response;
        })());
        return;
    }

    if (url.origin !== self.location.origin) return;

    if (url.pathname.startsWith('/build/') || url.pathname === '/manifest.webmanifest' || url.pathname.startsWith('/icons/')) {
        event.respondWith((async () => {
            const cache = await caches.open(ASSET_CACHE);
            const cached = await cache.match(request);
            if (cached) return cached;
            const response = await fetch(request);
            if (response.status === 200) {
                try {
                    await cache.put(request, response.clone());
                } catch {
                    // quota piena: la risposta arriva comunque
                }
            }
            return response;
        })());
        return;
    }

    if (request.mode === 'navigate' && url.pathname === '/operatore') {
        event.respondWith((async () => {
            const cache = await caches.open(SHELL_CACHE);
            try {
                const response = await fetch(request);
                // Mai mettere in cache una risposta rediretta (es. sessione scaduta
                // verso /login): la shell offline deve restare la pagina operatore
                if (response.ok && ! response.redirected) await cache.put('/operatore', response.clone());
                return response;
            } catch {
                const cached = await cache.match('/operatore');
                if (cached) return cached;
                throw new Error('offline senza copia locale');
            }
        })());
    }
});
