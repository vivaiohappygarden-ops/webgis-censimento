<script setup>
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import SearchPalette from '@/Components/SearchPalette.vue';

const page = usePage();
const user = computed(() => page.props.auth?.user);
const permissions = computed(() => user.value?.permissions ?? []);
const palette = ref(null);

const can = (permission) => permissions.value.includes(permission);

// Voci raggruppate per come si lavora: prima quello che serve ogni giorno,
// in fondo le impostazioni. I gruppi senza voci visibili spariscono da soli
const gruppi = computed(() =>
    [
        {
            titolo: 'Campo',
            voci: [
                { label: 'Oggi', href: '/oggi', show: can('works.view') },
                { label: 'Mappa', href: '/mappa', show: can('assets.view') },
                { label: 'Campo (operatore)', href: '/operatore', show: can('assets.create') },
            ],
        },
        {
            titolo: 'Patrimonio',
            voci: [
                { label: 'Censimento', href: '/censimento', show: can('assets.view') },
                { label: 'VTA', href: '/vta', show: can('assets.view') },
                { label: 'Irrigazione', href: '/irrigazione', show: can('areas.view') },
            ],
        },
        {
            titolo: 'Lavori',
            voci: [
                { label: 'Lavori', href: '/lavori', show: can('works.view') },
                { label: 'Segnalazioni', href: '/segnalazioni', show: can('works.view') },
                { label: 'Ispezioni', href: '/ispezioni', show: can('works.view') },
                { label: 'Listini', href: '/listini', show: can('works.view') },
            ],
        },
        {
            titolo: 'Registri',
            voci: [
                { label: 'Fitosanitari', href: '/fitosanitari', show: can('works.view') },
                { label: 'Patentini', href: '/patentini', show: can('works.view') },
                { label: 'Statistiche', href: '/statistiche', show: can('works.view') },
            ],
        },
        {
            titolo: 'Configurazione',
            voci: [
                { label: 'Territorio', href: '/territorio', show: can('clients.view') },
                { label: 'Catalogo', href: '/catalogo', show: can('catalog.view') },
                { label: 'Utenti', href: '/utenti', show: can('users.manage') },
                { label: 'Portale', href: '/portale', show: can('portal.view') },
            ],
        },
    ]
        .map((g) => ({ ...g, voci: g.voci.filter((v) => v.show) }))
        .filter((g) => g.voci.length > 0)
);

const isActive = (href) => page.url === href || page.url.startsWith(`${href}/`);

const logout = async () => {
    // Dispositivo condiviso: la shell offline in cache contiene i dati di sessione
    // dell'utente e va eliminata all'uscita (la coda locale resta, separata per utente)
    try {
        await window.caches?.delete('wg-shell-v1');
    } catch {
        // la cache può non essere disponibile (es. contesto non sicuro): si prosegue
    }
    router.post('/logout');
};
</script>

<template>
    <div class="flex h-screen overflow-hidden">
        <aside class="flex w-56 shrink-0 flex-col border-r border-gray-200 bg-white">
            <div class="border-b border-gray-100 px-4 py-4">
                <div class="text-sm font-semibold leading-tight">WebGIS Censimento</div>
                <div class="text-xs text-gray-500">{{ user?.organization?.name }}</div>
            </div>

            <div v-if="can('assets.view')" class="px-3 pt-3">
                <button
                    class="flex w-full items-center justify-between rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-400 transition hover:border-green-600 hover:text-gray-600"
                    @click="palette?.toggle(true)"
                >
                    <span>Cerca…</span>
                    <kbd class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] text-gray-500">Ctrl K</kbd>
                </button>
            </div>

            <nav class="flex-1 overflow-y-auto p-3">
                <div v-for="gruppo in gruppi" :key="gruppo.titolo" class="mb-3">
                    <div class="px-3 pb-1 text-[10px] font-semibold uppercase tracking-wide text-gray-400">
                        {{ gruppo.titolo }}
                    </div>
                    <Link
                        v-for="item in gruppo.voci"
                        :key="item.href"
                        :href="item.href"
                        class="block rounded-lg px-3 py-1.5 text-sm font-medium transition"
                        :class="isActive(item.href)
                            ? 'bg-green-50 text-green-800'
                            : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'"
                    >{{ item.label }}</Link>
                </div>
                <Link
                    href="/guida"
                    class="mt-1 block rounded-lg border-t border-gray-100 px-3 pb-1.5 pt-3 text-sm font-medium transition"
                    :class="isActive('/guida')
                        ? 'text-green-800'
                        : 'text-gray-600 hover:text-gray-900'"
                >Guida</Link>
            </nav>

            <div class="border-t border-gray-100 p-3">
                <div class="px-2 pb-2">
                    <div class="truncate text-sm font-medium">{{ user?.name }}</div>
                    <div class="truncate text-xs text-gray-500">{{ user?.email }}</div>
                </div>
                <button
                    class="w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-gray-600 transition hover:bg-red-50 hover:text-red-700"
                    @click="logout"
                >
                    Esci
                </button>
            </div>
        </aside>

        <main class="flex-1 overflow-y-auto">
            <slot />
        </main>

        <SearchPalette v-if="can('assets.view')" ref="palette" />
    </div>
</template>
