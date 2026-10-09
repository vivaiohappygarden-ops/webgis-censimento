<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import SearchPalette from '@/Components/SearchPalette.vue';
import Icona from '@/Components/Nuovo/Icona.vue';

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
        {
            // Solo per chi gestisce la piattaforma (qualifica data dal terminale)
            titolo: 'Piattaforma',
            voci: [
                { label: 'Console', href: '/piattaforma', show: !! user.value?.piattaforma },
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
        { label: 'Oggi', icona: 'oggi', href: '/oggi', show: can('assets.view') || can('works.view') },
        {
            label: 'Patrimonio',
            icona: 'patrimonio',
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
            icona: 'lavori',
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
            icona: 'documenti',
            schede: true,
            voci: [
                { label: 'Documenti', href: '/documenti', show: can('works.view') || can('assets.view') },
                { label: 'Fitosanitari', href: '/fitosanitari', show: can('works.view') },
                { label: 'Patentini', href: '/patentini', show: can('works.view') },
                { label: 'Statistiche', href: '/statistiche', show: can('works.view') },
            ],
        },
        {
            label: 'Committenti',
            icona: 'committenti',
            schede: true,
            voci: [
                { label: 'Committenti', href: '/committenti', show: can('clients.view') },
                { label: 'Territorio e portali', href: '/territorio', show: can('clients.view') },
                // Le viste dei portali esterni: per lo staff che le ha stanno
                // qui dentro, per chi ha solo quelle sono voci a se' (sotto)
                { label: 'Portale del committente', href: '/portale', show: can('clients.view') && can('portal.view') },
                { label: 'I lavori affidati', href: '/impresa', show: can('clients.view') && can('impresa.view') },
            ],
        },
        {
            label: 'Impostazioni',
            icona: 'impostazioni',
            schede: true,
            voci: [
                { label: 'Impostazioni', href: '/impostazioni', show: can('assets.view') || can('works.view') || can('clients.view') || can('users.manage') || can('catalog.view') },
                { label: 'Catalogo', href: '/catalogo', show: can('catalog.view') },
                { label: 'Utenti e studio', href: '/utenti', show: can('users.manage') },
                { label: 'Listini', href: '/listini', show: can('works.view') },
            ],
        },
        // La console di chi gestisce la piattaforma: tutte le organizzazioni
        { label: 'Piattaforma', icona: 'piattaforma', href: '/piattaforma', show: !! user.value?.piattaforma },
        { label: 'Portale', icona: 'portale', href: '/portale', show: can('portal.view') && ! can('clients.view') },
        { label: 'I lavori affidati', icona: 'impresa', href: '/impresa', show: can('impresa.view') && ! can('clients.view') },
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

// L'accesso di assistenza in corso (console della piattaforma): la fascia
// lo ricorda e riporta alla console con il proprio utente
const assistenza = computed(() => page.props.assistenza ?? null);
const oraAssistenza = computed(() => {
    if (! assistenza.value?.inizio) return '';
    const d = new Date(assistenza.value.inizio);
    return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
});
const terminaAssistenza = () => router.post('/piattaforma/assistenza/termina');

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
    <!--
        Veste "Lo strumento" (bozza B, 09/10/2026): menu scuro con le icone e la
        voce attiva segnata dalla riga ocra, campo di ricerca nel menu, fondo
        grigio freddo. Le classi sono le stesse per le due interfacce (nuova e
        precedente): cambia l'elenco delle voci, non la veste.
    -->
    <div class="flex h-screen overflow-hidden bg-gray-100">
        <!-- Barra superiore: solo su schermo piccolo, per aprire il menu -->
        <div class="fixed inset-x-0 top-0 z-30 flex items-center gap-3 bg-gray-800 px-3 py-2 text-white md:hidden">
            <button
                class="inline-flex min-h-11 items-center gap-2 rounded-sm border border-[#4a5a53] px-3 text-sm font-medium text-white"
                data-test="apri-menu"
                @click="menuAperto = true"
            ><Icona nome="menu" :size="16" />Menu</button>
            <div class="min-w-0">
                <div class="truncate text-sm font-semibold leading-tight">{{ user?.organization?.name || 'WebGIS Censimento' }}</div>
                <div v-if="user?.zone?.length" class="truncate text-xs text-[#b9c4bd]" data-test="menu-zona">Zona: {{ user.zone.map((z) => z.name).join(', ') }}</div>
            </div>
        </div>

        <!-- Sfondo scuro dietro il menu aperto: toccandolo si chiude -->
        <div
            v-if="menuAperto"
            class="fixed inset-0 z-40 bg-black/40 md:hidden"
            @click="menuAperto = false"
        />

        <aside
            class="fixed inset-y-0 left-0 z-50 flex w-64 shrink-0 flex-col bg-gray-800 text-[#dfe6e1] transition-transform md:static md:z-auto md:translate-x-0"
            :class="[menuAperto ? 'translate-x-0' : '-translate-x-full', nuova ? 'md:w-60' : 'md:w-56']"
            data-test="menu-laterale"
        >
            <button
                class="absolute right-2 top-2 inline-flex h-11 w-11 items-center justify-center text-[#b9c4bd] hover:text-white md:hidden"
                data-test="chiudi-menu"
                aria-label="Chiudi il menu"
                @click="menuAperto = false"
            ><Icona nome="chiudi" :size="18" /></button>
            <div class="px-4 pb-3 pt-4">
                <div class="text-[11px] font-medium uppercase tracking-[0.08em] text-[#b9c4bd]">WebGIS Censimento</div>
                <div class="mt-0.5 truncate text-[15px] font-semibold text-white">{{ user?.organization?.name }}</div>
                <!-- Chi e' di zona lo legge qui: vede solo il territorio delle sue zone -->
                <div v-if="user?.zone?.length" class="mt-0.5 text-[13px] text-[#b9c4bd]" data-test="menu-zona">Zona: {{ user.zone.map((z) => z.name).join(', ') }}</div>
            </div>

            <div v-if="can('assets.view')" class="px-3 pb-2">
                <button
                    type="button"
                    class="flex min-h-11 w-full items-center gap-2 rounded-sm border border-[#4a5a53] bg-[#162220] px-3 text-left text-sm text-[#b9c4bd] transition hover:border-[#8a948e] hover:text-white md:min-h-9"
                    title="Cerca nel programma (Ctrl K)"
                    @click="palette?.toggle(true)"
                >
                    <Icona nome="cerca" :size="16" />
                    <span>Cerca nel programma</span>
                </button>
            </div>

            <!-- Veste nuova: sei voci per compiti -->
            <nav v-if="nuova" class="flex-1 overflow-y-auto py-1" data-test="menu-nuovo">
                <template v-for="sezione in sezioni" :key="sezione.label">
                    <Link
                        :href="sezione.href"
                        class="flex min-h-11 items-center gap-2.5 border-l-[3px] pl-3 pr-4 text-[14.5px] transition md:min-h-10"
                        :class="sezione.attiva
                            ? 'border-amber-500 bg-[#2a3b34] font-medium text-white'
                            : 'border-transparent text-[#dfe6e1] hover:bg-[#243530] hover:text-white'"
                        :aria-current="sezione.attiva ? 'page' : undefined"
                    ><Icona v-if="sezione.icona" :nome="sezione.icona" :size="18" :class="sezione.attiva ? 'text-white' : 'text-[#b9c4bd]'" />{{ sezione.label }}</Link>
                    <div v-if="sezione.attiva && sezione.voci.length" class="mb-1 mt-0.5" data-test="menu-sottovoci">
                        <Link
                            v-for="voce in sezione.voci"
                            :key="voce.href"
                            :href="voce.href"
                            class="flex min-h-10 items-center py-1 pl-11 pr-3 text-sm transition md:min-h-9"
                            :class="isActive(voce.href)
                                ? 'font-medium text-white'
                                : 'text-[#b9c4bd] hover:text-white'"
                        >{{ voce.label }}</Link>
                    </div>
                </template>
                <div class="mx-4 my-2 h-px bg-[#3a4a43]" />
                <Link
                    href="/guida"
                    class="flex min-h-11 items-center gap-2.5 border-l-[3px] pl-3 pr-4 text-[14.5px] transition md:min-h-10"
                    :class="isActive('/guida') ? 'border-amber-500 bg-[#2a3b34] font-medium text-white' : 'border-transparent text-[#dfe6e1] hover:bg-[#243530] hover:text-white'"
                ><Icona nome="guida" :size="18" :class="isActive('/guida') ? 'text-white' : 'text-[#b9c4bd]'" />Guida</Link>
            </nav>

            <!-- Veste precedente: le pagine raggruppate -->
            <nav v-else class="flex-1 overflow-y-auto py-1">
                <div v-for="gruppo in gruppi" :key="gruppo.titolo" class="mb-2">
                    <div class="px-4 pb-0.5 pt-2 font-mono text-[10.5px] uppercase tracking-[0.08em] text-[#8a948e]">
                        {{ gruppo.titolo }}
                    </div>
                    <Link
                        v-for="item in gruppo.voci"
                        :key="item.href"
                        :href="item.href"
                        class="flex min-h-10 items-center border-l-[3px] pl-3 pr-4 text-sm transition md:min-h-9"
                        :class="isActive(item.href)
                            ? 'border-amber-500 bg-[#2a3b34] font-medium text-white'
                            : 'border-transparent text-[#dfe6e1] hover:bg-[#243530] hover:text-white'"
                    >{{ item.label }}</Link>
                </div>
                <div class="mx-4 my-1 h-px bg-[#3a4a43]" />
                <Link
                    href="/guida"
                    class="flex min-h-10 items-center border-l-[3px] pl-3 pr-4 text-sm transition md:min-h-9"
                    :class="isActive('/guida')
                        ? 'border-amber-500 bg-[#2a3b34] font-medium text-white'
                        : 'border-transparent text-[#dfe6e1] hover:bg-[#243530] hover:text-white'"
                >Guida</Link>
            </nav>

            <div class="border-t border-[#3a4a43] px-3 pb-3 pt-3">
                <Link
                    v-if="nuova && can('assets.create')"
                    href="/operatore"
                    class="mb-3 flex min-h-11 items-center gap-2 rounded-sm border border-[#4a5a53] px-3 text-sm font-medium text-white transition hover:border-[#8a948e] hover:bg-[#243530] md:min-h-9"
                    data-test="app-di-campo"
                ><Icona nome="campo" :size="18" />App di campo</Link>
                <div class="px-1 pb-1">
                    <div class="truncate text-sm font-medium text-white">{{ user?.name }}</div>
                    <div class="truncate font-mono text-[12px] text-[#b9c4bd]">{{ user?.email }}</div>
                </div>
                <Link
                    href="/sicurezza"
                    class="flex min-h-11 w-full items-center gap-2 px-1 text-left text-[13.5px] transition hover:text-white md:min-h-8"
                    :class="isActive('/sicurezza') ? 'font-medium text-white' : 'text-[#b9c4bd]'"
                    data-test="il-mio-accesso"
                ><Icona nome="accesso" :size="15" />Il mio accesso</Link>
                <button
                    type="button"
                    class="flex min-h-11 w-full items-center px-1 text-left text-[13.5px] text-[#b9c4bd] transition hover:text-white md:min-h-8"
                    data-test="cambia-interfaccia"
                    @click="cambiaInterfaccia"
                >{{ nuova ? 'Torna all\'interfaccia precedente' : 'Prova la nuova interfaccia' }}</button>
                <button
                    class="flex min-h-11 w-full items-center gap-2 px-1 text-left text-[13.5px] text-[#b9c4bd] transition hover:text-white md:min-h-8"
                    @click="logout"
                ><Icona nome="esci" :size="15" />Esci</button>
            </div>
        </aside>

        <!-- pt-14 sul telefono: lo spazio della barra superiore fissa -->
        <main class="flex-1 overflow-x-hidden overflow-y-auto pt-14 md:pt-0">
            <div
                v-if="assistenza"
                class="flex flex-wrap items-center justify-between gap-2 border-b border-amber-300 bg-amber-50 px-4 py-2 text-sm text-amber-900"
                data-test="fascia-assistenza"
            >
                <span>
                    Assistenza in corso in <strong>{{ assistenza.organizzazione }}</strong><template v-if="oraAssistenza"> dalle {{ oraAssistenza }}</template>:
                    le modifiche che fai restano a nome "Assistenza piattaforma".
                </span>
                <button
                    type="button"
                    class="inline-flex min-h-11 items-center rounded-sm border border-amber-700 bg-white px-3 text-sm font-medium text-amber-900 hover:bg-amber-100 md:min-h-[34px]"
                    data-test="termina-assistenza"
                    @click="terminaAssistenza"
                >Termina e torna alla console</button>
            </div>
            <slot />
        </main>

        <SearchPalette v-if="can('assets.view')" ref="palette" />
    </div>
</template>
