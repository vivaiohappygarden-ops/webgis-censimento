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
const KNOWN_CACHES = [SHELL_CACHE, ASSET_CACHE];

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
    if (url.origin !== self.location.origin) return;

    if (url.pathname.startsWith('/build/') || url.pathname === '/manifest.webmanifest' || url.pathname.startsWith('/icons/')) {
        event.respondWith((async () => {
            const cache = await caches.open(ASSET_CACHE);
            const cached = await cache.match(request);
            if (cached) return cached;
            const response = await fetch(request);
            if (response.ok) await cache.put(request, response.clone());
            return response;
        })());
        return;
    }

    if (request.mode === 'navigate' && url.pathname === '/operatore') {
        event.respondWith((async () => {
            const cache = await caches.open(SHELL_CACHE);
            try {
                const response = await fetch(request);
                if (response.ok) await cache.put('/operatore', response.clone());
                return response;
            } catch {
                const cached = await cache.match('/operatore');
                if (cached) return cached;
                throw new Error('offline senza copia locale');
            }
        })());
    }
});
