<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import SearchPalette from '@/Components/SearchPalette.vue';

const page = usePage();
// Sul telefono il menu è a scomparsa: lasciarlo fisso mangerebbe metà schermo
// e il contenuto verrebbe tagliato. Su schermo grande resta sempre visibile
const menuAperto = ref(false);
watch(() => page.url, () => { menuAperto.value = false; });
const user = computed(() => page.props.auth?.user);
const permissions = computed(() => user.value?.permissions ?? []);
const palette = ref(null);

const can = (permission) => permissions.value.includes(permission);

// La veste: "nuova" (bozza A del 26/09/2026, sei voci per compiti) o
// "precedente" (le venti pagine raggruppate). La scelta è per utente e si
// cambia dal fondo del menu; finché le pagine nuove non sono tutte pronte, le
// voci della veste nuova aprono le pagine di prima
const interfaccia = computed(() => page.props.interfaccia ?? { modo: 'precedente' });
const nuova = computed(() => interfaccia.value.modo === 'nuova');

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
                { label: 'I lavori affidati', href: '/impresa', show: can('impresa.view') },
            ],
        },
    ]
        .map((g) => ({ ...g, voci: g.voci.filter((v) => v.show) }))
        .filter((g) => g.voci.length > 0)
);

const isActive = (href) => page.url === href || page.url.startsWith(`${href}/`) || page.url.startsWith(`${href}?`);

// Veste nuova: sei voci per compiti. Una voce con più pagine dentro apre la
// prima e, finché ci si sta dentro, mostra le altre sotto di sé; con una sola
// pagina è un collegamento e basta. Le pagine sono ancora quelle di prima:
// spariranno da qui man mano che i blocchi nuovi le sostituiscono
const sezioni = computed(() =>
    [
        { label: 'Oggi', href: '/oggi', show: can('assets.view') || can('works.view') },
        {
            label: 'Patrimonio',
            // Le sue schede stanno nella testata della pagina (blocco 2):
            // qui servono solo a capire quando la voce e' attiva
            schede: true,
            voci: [
                { label: 'Elenco', href: '/patrimonio', show: can('assets.view') },
                { label: 'Elenco precedente', href: '/censimento', show: can('assets.view') },
                { label: 'Mappa', href: '/mappa', show: can('assets.view') },
                { label: 'Alberi e VTA', href: '/vta', show: can('assets.view') },
                { label: 'Irrigazione', href: '/irrigazione', show: can('areas.view') },
            ],
        },
        {
            label: 'Lavori',
            // Le schede (ordini, agenda, gantt, segnalazioni, ispezioni) stanno
            // nella testata della pagina (blocco 4)
            schede: true,
            voci: [
                { label: 'Ordini', href: '/lavori', show: can('works.view') },
                { label: 'Segnalazioni', href: '/segnalazioni', show: can('works.view') },
                { label: 'Ispezioni', href: '/ispezioni', show: can('works.view') },
                { label: 'Listini', href: '/listini', show: can('works.view') },
            ],
        },
        {
            label: 'Documenti',
            voci: [
                { label: 'Fitosanitari', href: '/fitosanitari', show: can('works.view') },
                { label: 'Patentini', href: '/patentini', show: can('works.view') },
                { label: 'Statistiche', href: '/statistiche', show: can('works.view') },
            ],
        },
        {
            label: 'Committenti',
            voci: [
                { label: 'Territorio e portali', href: '/territorio', show: can('clients.view') },
                // Le viste dei portali esterni: per lo staff che le ha stanno
                // qui dentro, per chi ha solo quelle sono voci a se' (sotto)
                { label: 'Portale del committente', href: '/portale', show: can('clients.view') && can('portal.view') },
                { label: 'I lavori affidati', href: '/impresa', show: can('clients.view') && can('impresa.view') },
            ],
        },
        {
            label: 'Impostazioni',
            voci: [
                { label: 'Catalogo', href: '/catalogo', show: can('catalog.view') },
                { label: 'Utenti e studio', href: '/utenti', show: can('users.manage') },
            ],
        },
        { label: 'Portale', href: '/portale', show: can('portal.view') && ! can('clients.view') },
        { label: 'I lavori affidati', href: '/impresa', show: can('impresa.view') && ! can('clients.view') },
    ]
        .map((s) => {
            const voci = (s.voci ?? []).filter((v) => v.show);
            const href = s.href ?? voci[0]?.href;
            const attiva = s.href ? isActive(s.href) : voci.some((v) => isActive(v.href));

            return { ...s, voci: voci.length > 1 && ! s.schede ? voci : [], href, attiva, show: s.href ? s.show : voci.length > 0 };
        })
        .filter((s) => s.show)
);

const cambiaInterfaccia = () => {
    router.post('/interfaccia', { modo: nuova.value ? 'precedente' : 'nuova' });
};

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
    <div class="flex h-screen overflow-hidden" :class="nuova ? 'bg-[#f4f5f4]' : ''">
        <!-- Barra superiore: solo su schermo piccolo, per aprire il menu -->
        <div class="fixed inset-x-0 top-0 z-30 flex items-center gap-3 border-b border-gray-200 bg-white px-3 py-2 md:hidden">
            <button
                class="min-h-10 rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700"
                data-test="apri-menu"
                @click="menuAperto = true"
            >Menu</button>
            <div class="min-w-0">
                <div class="truncate text-sm font-semibold leading-tight">WebGIS Censimento</div>
                <div class="truncate text-xs text-gray-500">{{ user?.organization?.name }}</div>
            </div>
        </div>

        <!-- Sfondo scuro dietro il menu aperto: toccandolo si chiude -->
        <div
            v-if="menuAperto"
            class="fixed inset-0 z-40 bg-black/30 md:hidden"
            @click="menuAperto = false"
        />

        <aside
            class="fixed inset-y-0 left-0 z-50 flex w-64 shrink-0 flex-col border-r border-gray-200 bg-white transition-transform md:static md:z-auto md:translate-x-0"
            :class="[menuAperto ? 'translate-x-0' : '-translate-x-full', nuova ? 'md:w-60' : 'md:w-56']"
            data-test="menu-laterale"
        >
            <button
                class="absolute right-3 top-3 text-gray-400 md:hidden"
                data-test="chiudi-menu"
                @click="menuAperto = false"
            >✕</button>
            <div class="border-b border-gray-100 px-4 py-4">
                <div class="text-sm font-semibold leading-tight" :class="nuova ? 'text-base font-bold' : ''">WebGIS Censimento</div>
                <div class="text-xs text-gray-500" :class="nuova ? 'text-[13px]' : ''">{{ user?.organization?.name }}</div>
            </div>

            <div v-if="can('assets.view')" class="px-3 pt-3">
                <button
                    class="flex w-full items-center justify-between rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-400 transition hover:border-green-600 hover:text-gray-600"
                    :class="nuova ? 'min-h-[38px] border-gray-300 text-gray-500' : ''"
                    @click="palette?.toggle(true)"
                >
                    <span>Cerca…</span>
                    <kbd class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] text-gray-500">Ctrl K</kbd>
                </button>
            </div>

            <!-- Veste nuova: sei voci per compiti -->
            <nav v-if="nuova" class="flex-1 overflow-y-auto p-3" data-test="menu-nuovo">
                <template v-for="sezione in sezioni" :key="sezione.label">
                    <Link
                        :href="sezione.href"
                        class="flex min-h-10 items-center rounded-lg px-3 text-[15px] transition"
                        :class="sezione.attiva
                            ? 'bg-green-100 font-semibold text-green-900'
                            : 'text-gray-700 hover:bg-gray-50 hover:text-gray-900'"
                        :aria-current="sezione.attiva ? 'page' : undefined"
                    >{{ sezione.label }}</Link>
                    <div v-if="sezione.attiva && sezione.voci.length" class="mb-1 mt-0.5" data-test="menu-sottovoci">
                        <Link
                            v-for="voce in sezione.voci"
                            :key="voce.href"
                            :href="voce.href"
                            class="flex min-h-9 items-center rounded-lg py-1 pl-7 pr-3 text-sm transition"
                            :class="isActive(voce.href)
                                ? 'font-semibold text-green-900'
                                : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'"
                        >{{ voce.label }}</Link>
                    </div>
                </template>
                <Link
                    href="/guida"
                    class="mt-2 flex min-h-10 items-center rounded-lg border-t border-gray-100 px-3 pt-2 text-sm font-medium transition"
                    :class="isActive('/guida') ? 'text-green-900' : 'text-gray-600 hover:text-gray-900'"
                >Guida</Link>
            </nav>

            <!-- Veste precedente: le pagine raggruppate -->
            <nav v-else class="flex-1 overflow-y-auto p-3">
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
                <Link
                    v-if="nuova && can('assets.create')"
                    href="/operatore"
                    class="mb-2 flex min-h-[38px] items-center rounded-lg border border-gray-300 px-3 text-sm font-semibold text-gray-900 transition hover:bg-gray-50"
                    data-test="app-di-campo"
                >App di campo</Link>
                <div class="px-2 pb-2">
                    <div class="truncate text-sm font-medium">{{ user?.name }}</div>
                    <div class="truncate text-xs text-gray-500">{{ user?.email }}</div>
                </div>
                <button
                    type="button"
                    class="w-full rounded-lg px-3 py-2 text-left text-sm text-gray-600 transition hover:bg-gray-50 hover:text-gray-900"
                    data-test="cambia-interfaccia"
                    @click="cambiaInterfaccia"
                >{{ nuova ? 'Torna all\'interfaccia precedente' : 'Prova la nuova interfaccia' }}</button>
                <button
                    class="w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-gray-600 transition hover:bg-red-50 hover:text-red-700"
                    @click="logout"
                >
                    Esci
                </button>
            </div>
        </aside>

        <!-- pt-14 sul telefono: lo spazio della barra superiore fissa -->
        <main class="flex-1 overflow-x-hidden overflow-y-auto pt-14 md:pt-0">
            <slot />
        </main>

        <SearchPalette v-if="can('assets.view')" ref="palette" />
    </div>
</template>
