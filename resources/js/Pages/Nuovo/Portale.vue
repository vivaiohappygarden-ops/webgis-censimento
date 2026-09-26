<script setup>
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import AvvisoErrore from '@/Components/AvvisoErrore.vue';
import MappaTerritorio from '@/Components/Portale/MappaTerritorio.vue';
import SchedaElemento from '@/Components/Portale/SchedaElemento.vue';
import ModuloRichiesta from '@/Components/Portale/ModuloRichiesta.vue';
import { usaCaricamento } from '@/caricamento';
import { avvisoCaricamento } from '@/avvisi';
import { BOTTONE, BOTTONE_PICCOLO, BOTTONE_SECONDARIO, CARTA, CHIP, ETICHETTA, plurale } from '@/nuovo/stile';
import { STATI, STATO_LAVORO, STATO_RICHIESTA, URGENZA, conta, formatData, num, oggiIso } from '@/portale/formato';

/*
 * Il portale del Comune (dal 26/09/2026): la pagina con cui l'ufficio tecnico
 * legge il proprio verde. Sei schede: la panoramica con la mappa e le cose in
 * corso, la mappa a tutto schermo con la ricerca, il patrimonio con la scheda
 * di ogni elemento, i lavori, le segnalazioni con le richieste e i documenti
 * emessi. Tutto in sola lettura, tranne la richiesta.
 *
 * Niente riquadri di numeri (decisione committente 26/09/2026): i conteggi
 * stanno in una frase e accanto ai filtri.
 */
const props = defineProps({
    sfondi: { type: Array, default: () => [] },
    navigazioneUrl: { type: String, default: '' },
});

const page = usePage();
const SCHEDE = [
    ['panoramica', 'Panoramica'],
    ['mappa', 'Mappa'],
    ['patrimonio', 'Patrimonio'],
    ['lavori', 'Lavori'],
    ['segnalazioni', 'Segnalazioni'],
    ['documenti', 'Documenti'],
];
const parametri = new URLSearchParams(window.location.search);
const scheda = ref(SCHEDE.some(([c]) => c === parametri.get('scheda')) ? parametri.get('scheda') : 'panoramica');
const elementoScelto = ref(parametri.get('elemento') || null);
const lavoroScelto = ref(parametri.get('lavoro') || null);
const moduloAperto = ref(parametri.get('nuova') === '1');

/** L'indirizzo segue la scheda aperta, cosi' si puo' condividere e ricaricare. */
function aggiornaIndirizzo() {
    const p = new URLSearchParams();
    if (scheda.value !== 'panoramica') p.set('scheda', scheda.value);
    if (elementoScelto.value && ['mappa', 'patrimonio'].includes(scheda.value)) p.set('elemento', elementoScelto.value);
    if (lavoroScelto.value && scheda.value === 'lavori') p.set('lavoro', lavoroScelto.value);
    if (moduloAperto.value && scheda.value === 'segnalazioni') p.set('nuova', '1');
    const q = p.toString();
    window.history.replaceState(window.history.state, '', `/portale${q ? `?${q}` : ''}`);
}
watch([scheda, elementoScelto, lavoroScelto, moduloAperto], aggiornaIndirizzo);

// --- Riepilogo ---------------------------------------------------------------
const { avviso, riprovaInCorso, carica, riprova } = usaCaricamento();
const riepilogo = ref(null);
const richieste = ref([]);
const documenti = ref(null);

async function caricaRiepilogo() {
    const { data } = await axios.get('/api/v1/portal/overview');
    riepilogo.value = data;
    if (data.linked) {
        const [r, d] = await Promise.all([axios.get('/api/v1/portal/requests'), axios.get('/api/v1/portal/documenti')]);
        richieste.value = r.data.data;
        documenti.value = d.data.data;
    }
}

const collegato = computed(() => riepilogo.value?.linked === true);
const conteggi = computed(() => riepilogo.value?.counts ?? {});
const richiesteAperte = computed(() => richieste.value.filter((r) => ['open', 'in_charge'].includes(r.status)));
const altreSegnalazioni = computed(() => (riepilogo.value?.issues ?? []).filter((i) => i.channel !== 'client_portal'));

/** La frase in testa: i numeri veri, non riquadri. */
const frase = computed(() => {
    const c = conteggi.value;
    if (! riepilogo.value) return [];
    const parti = [];
    const b = (t, rosso = false) => ({ t, b: true, rosso });
    const t = (s) => ({ t: s });
    if (! c.assets) {
        parti.push(t('Il rilievo del patrimonio non è ancora iniziato: quando gli elementi saranno censiti compariranno qui e sulla mappa.'));

        return parti;
    }
    parti.push(t('Il patrimonio in gestione conta '), b(conta(c.assets, 'elemento', 'elementi')), t(' in '), b(conta(c.areas, 'area', 'aree')));
    if (c.trees) parti.push(t(', di cui '), b(conta(c.trees, 'albero', 'alberi')));
    parti.push(t('. '));
    if (c.vta_scadute) parti.push(b(conta(c.vta_scadute, 'albero ha', 'alberi hanno'), true), t(' il ricontrollo di stabilità scaduto. '));
    if (c.vta_mai && c.trees) parti.push(t(`${num(c.vta_mai, 0)} ${plurale(c.vta_mai, 'albero non è ancora stato valutato', 'alberi non sono ancora stati valutati')}. `));
    parti.push(b(conta(c.open_orders, 'lavoro', 'lavori')), t(c.open_orders === 1 ? ' è in programma o in corso' : ' sono in programma o in corso'));
    parti.push(t(`, ${num(c.completed_orders, 0)} ${plurale(c.completed_orders, 'è stato fatto', 'sono stati fatti')}. `));
    if (c.open_requests) parti.push(b(conta(c.open_requests, 'richiesta', 'richieste')), t(c.open_requests === 1 ? ' vostra è aperta.' : ' vostre sono aperte.'));
    else parti.push(t('Nessuna vostra richiesta è aperta.'));

    return parti;
});

const novita = computed(() => {
    const r = riepilogo.value?.recenti;
    if (! r) return [];

    return [
        [r.elementi_rilevati, 'elemento rilevato', 'elementi rilevati'],
        [r.valutazioni, 'valutazione di stabilità', 'valutazioni di stabilità'],
        [r.lavori_fatti, 'lavoro fatto', 'lavori fatti'],
        [r.documenti, 'perizia emessa', 'perizie emesse'],
        [r.richieste_risolte, 'richiesta risolta', 'richieste risolte'],
    ].filter(([n]) => n > 0).map(([n, uno, molti]) => conta(n, uno, molti));
});

const contatti = computed(() => riepilogo.value?.contatti ?? {});

// --- Mappa -------------------------------------------------------------------
const mappaPanoramica = ref(null);
const mappaGrande = ref(null);
const ricercaMappa = reactive({ testo: '', risultati: [], busy: false, aperta: false });
let timerRicerca = null;

watch(() => ricercaMappa.testo, (testo) => {
    clearTimeout(timerRicerca);
    if (testo.trim().length < 2) {
        ricercaMappa.risultati = [];
        ricercaMappa.aperta = false;

        return;
    }
    timerRicerca = setTimeout(async () => {
        ricercaMappa.busy = true;
        try {
            const { data } = await axios.get('/api/v1/portal/elementi', { params: { q: testo.trim(), per_page: 8 } });
            ricercaMappa.risultati = data.data;
            ricercaMappa.aperta = true;
        } catch {
            ricercaMappa.risultati = [];
        } finally {
            ricercaMappa.busy = false;
        }
    }, 250);
});

function apriSullaMappa(riga) {
    scheda.value = 'mappa';
    elementoScelto.value = riga.id;
    ricercaMappa.aperta = false;
    ricercaMappa.testo = '';
    nextTick(() => mappaGrande.value?.vaiA(riga));
}

// --- Patrimonio --------------------------------------------------------------
const filtri = reactive({ q: parametri.get('q') || '', area_id: parametri.get('area_id') || '', stato: '', tipo: '', vta: '', page: 1 });
const elenco = ref([]);
const meta = ref(null);
const caricamentoElenco = ref(false);
const erroreElenco = ref('');
let timerElenco = null;

async function caricaElenco() {
    caricamentoElenco.value = true;
    erroreElenco.value = '';
    try {
        const params = Object.fromEntries(Object.entries(filtri).filter(([, v]) => v !== '' && v !== null));
        const { data } = await axios.get('/api/v1/portal/elementi', { params });
        elenco.value = data.data;
        meta.value = { total: data.total, from: data.from, to: data.to, current_page: data.current_page, last_page: data.last_page };
    } catch (err) {
        erroreElenco.value = avvisoCaricamento(err);
    } finally {
        caricamentoElenco.value = false;
    }
}
watch(() => [filtri.q, filtri.area_id, filtri.stato, filtri.tipo, filtri.vta], () => {
    filtri.page = 1;
    clearTimeout(timerElenco);
    timerElenco = setTimeout(caricaElenco, 250);
});
watch(() => filtri.page, caricaElenco);
const filtriAttivi = computed(() => filtri.q || filtri.area_id || filtri.stato || filtri.tipo || filtri.vta);
function azzeraFiltri() {
    Object.assign(filtri, { q: '', area_id: '', stato: '', tipo: '', vta: '', page: 1 });
}

// --- Lavori ------------------------------------------------------------------
const filtroLavori = ref('aperti');
const lavori = ref([]);
const metaLavori = ref(null);
const paginaLavori = ref(1);
const caricamentoLavori = ref(false);
const erroreLavori = ref('');
const dettaglioLavoro = reactive({ dati: null, caricamento: false, errore: '' });

async function caricaLavori() {
    caricamentoLavori.value = true;
    erroreLavori.value = '';
    try {
        const { data } = await axios.get('/api/v1/portal/lavori', { params: { stato: filtroLavori.value, page: paginaLavori.value } });
        lavori.value = data.data;
        metaLavori.value = { total: data.total, from: data.from, to: data.to, current_page: data.current_page, last_page: data.last_page };
    } catch (err) {
        erroreLavori.value = avvisoCaricamento(err);
    } finally {
        caricamentoLavori.value = false;
    }
}
watch(filtroLavori, () => { paginaLavori.value = 1; caricaLavori(); });
watch(paginaLavori, caricaLavori);

async function apriLavoro(id) {
    scheda.value = 'lavori';
    lavoroScelto.value = id;
    Object.assign(dettaglioLavoro, { dati: null, caricamento: true, errore: '' });
    try {
        const { data } = await axios.get(`/api/v1/portal/lavori/${id}`);
        if (lavoroScelto.value === id) dettaglioLavoro.dati = data.data;
    } catch (err) {
        dettaglioLavoro.errore = avvisoCaricamento(err);
    } finally {
        dettaglioLavoro.caricamento = false;
    }
}
/** Quando: la data vera se c'e', altrimenti quella prevista, senza far passare un lavoro fatto per uno da programmare. */
function periodoLavoro(l) {
    const previsto = [l.planned_start ? `dal ${formatData(l.planned_start)}` : null, l.planned_end ? `al ${formatData(l.planned_end)}` : null].filter(Boolean).join(' ');
    if (l.status === 'completed') {
        if (l.completed_at) return `fatto il ${formatData(l.completed_at)}`;

        return previsto ? `fatto, previsto ${previsto}` : 'fatto, data non registrata';
    }

    return previsto || 'da programmare';
}

// --- Schede e caricamenti pigri ----------------------------------------------
const caricate = reactive({ patrimonio: false, lavori: false });
watch(scheda, (s) => {
    if (s === 'patrimonio' && ! caricate.patrimonio) { caricate.patrimonio = true; caricaElenco(); }
    if (s === 'lavori' && ! caricate.lavori) { caricate.lavori = true; caricaLavori(); }
    if (s === 'mappa') nextTick(() => mappaGrande.value?.adatta?.(false));
    if (s === 'panoramica') nextTick(() => mappaPanoramica.value?.adatta?.(false));
}, { immediate: true });

function vaiAScheda(s) {
    scheda.value = s;
    if (s === 'segnalazioni' && ! richieste.value.length) moduloAperto.value = true;
}

onMounted(async () => {
    await carica(caricaRiepilogo);
    if (lavoroScelto.value && scheda.value === 'lavori') apriLavoro(lavoroScelto.value);
});

const ricontrolloScaduto = (r) => r.vta_prossimo && r.vta_prossimo < oggiIso();
// Le voci degli elenchi: bersaglio da 44px sul telefono, testo semplice col mouse
const VOCE = 'inline-flex min-h-11 items-center text-left font-semibold text-gray-900 underline-offset-2 hover:underline md:min-h-0';

/** Sul telefono la scheda si apre sopra la mappa o l'elenco: la si porta in vista. */
function portaInVista(selettore) {
    if (window.innerWidth >= 1024) return;
    nextTick(() => document.querySelector(selettore)?.scrollIntoView({
        behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
        block: 'start',
    }));
}
watch(elementoScelto, (id) => {
    if (id && scheda.value === 'mappa') portaInVista('[data-test=portale-mappa-pannello]');
    if (id && scheda.value === 'patrimonio') portaInVista('[data-test=portale-anteprima]');
});
watch(lavoroScelto, (id) => { if (id) portaInVista('[data-test=portale-lavoro-dettaglio]'); });
const nomeComune = computed(() => riepilogo.value?.client?.name ?? page.props.auth?.user?.organization?.name ?? 'Portale');
</script>

<template>
    <Head title="Portale del Comune" />

    <AppLayout>
        <div class="mx-auto flex max-w-[1640px] flex-col gap-4 p-4 md:gap-5 md:p-6 lg:px-7" data-test="portal">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div class="min-w-0">
                    <h1 class="text-2xl font-bold text-gray-900" data-test="portale-titolo">{{ nomeComune }}</h1>
                    <p class="mt-0.5 text-[13px] text-gray-500">
                        Il verde in gestione
                        <template v-if="riepilogo?.ultimo_rilievo"> · ultimo rilievo il {{ formatData(riepilogo.ultimo_rilievo) }}</template>
                        <template v-if="riepilogo?.ultima_valutazione"> · ultima valutazione di stabilità il {{ formatData(riepilogo.ultima_valutazione) }}</template>
                    </p>
                </div>
                <div v-if="collegato" class="flex flex-wrap gap-2">
                    <button type="button" :class="BOTTONE" data-test="portale-nuova-richiesta" @click="scheda = 'segnalazioni'; moduloAperto = true">Nuova richiesta</button>
                </div>
            </div>

            <AvvisoErrore :messaggio="avviso" :in-corso="riprovaInCorso" @riprova="riprova" />

            <p v-if="riepilogo && ! collegato" :class="CARTA" class="bg-amber-50 px-4 py-3 text-sm text-amber-900" data-test="portal-unlinked">{{ riepilogo.message }}</p>

            <template v-if="collegato">
                <nav class="flex flex-wrap gap-2 md:inline-flex md:gap-0 md:self-start md:overflow-hidden md:rounded-lg md:border md:border-gray-300 md:bg-white" aria-label="Sezioni del portale">
                    <button
                        v-for="[chiave, etichetta] in SCHEDE"
                        :key="chiave"
                        type="button"
                        class="inline-flex min-h-11 items-center rounded-lg border px-3.5 text-sm font-semibold transition md:min-h-9 md:rounded-none md:border-0 md:border-r md:border-gray-200 md:last:border-r-0"
                        :class="scheda === chiave ? 'border-green-700 bg-green-700 text-white' : 'border-gray-300 text-gray-700 hover:bg-gray-50'"
                        :aria-current="scheda === chiave ? 'page' : undefined"
                        :data-test="`portale-scheda-${chiave}`"
                        @click="vaiAScheda(chiave)"
                    >{{ etichetta }}</button>
                </nav>

                <!-- Panoramica -->
                <template v-if="scheda === 'panoramica'">
                    <p class="max-w-[78ch] text-base leading-relaxed text-gray-900" data-test="portale-frase">
                        <template v-for="(p, i) in frase" :key="i">
                            <b v-if="p.b" :class="p.rosso ? 'text-red-800' : ''">{{ p.t }}</b>
                            <template v-else>{{ p.t }}</template>
                        </template>
                    </p>

                    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_380px] lg:items-start">
                        <div class="flex min-w-0 flex-col gap-4">
                            <MappaTerritorio ref="mappaPanoramica" :sfondi="sfondi" :estensione="riepilogo.estensione" @apri="apriSullaMappa">
                                <template #sopra>
                                    <button type="button" :class="BOTTONE_SECONDARIO" class="pointer-events-auto" @click="scheda = 'mappa'">Apri la mappa</button>
                                </template>
                            </MappaTerritorio>

                            <div class="grid gap-4 md:grid-cols-2">
                                <section :class="CARTA" data-test="portale-prossimi">
                                    <h2 class="border-b border-gray-200 px-4 py-3 text-base font-bold text-gray-900">Prossimi lavori</h2>
                                    <ul v-if="riepilogo.prossimi.length" class="divide-y divide-gray-100 text-sm">
                                        <li v-for="l in riepilogo.prossimi" :key="l.id" class="px-4 py-2.5">
                                            <button type="button" :class="VOCE" @click="apriLavoro(l.id)">{{ l.title }}</button>
                                            <div class="flex flex-wrap items-center gap-x-2 text-[13px] text-gray-600">
                                                <span :class="STATO_LAVORO[l.status]">{{ l.status_etichetta }}</span>
                                                <span>{{ periodoLavoro(l) }}</span>
                                                <span v-if="l.area">· {{ l.area }}</span>
                                            </div>
                                        </li>
                                    </ul>
                                    <p v-else class="px-4 py-4 text-sm text-gray-600">Nessun lavoro in programma.</p>
                                    <div class="border-t border-gray-100 px-4 py-2"><button type="button" class="min-h-11 text-[13px] font-semibold text-green-800 underline-offset-2 hover:underline md:min-h-9" @click="scheda = 'lavori'">Tutti i lavori</button></div>
                                </section>
                                <section :class="CARTA" data-test="portale-fatti">
                                    <h2 class="border-b border-gray-200 px-4 py-3 text-base font-bold text-gray-900">Ultimi lavori fatti</h2>
                                    <ul v-if="riepilogo.orders.length" class="divide-y divide-gray-100 text-sm">
                                        <li v-for="o in riepilogo.orders.slice(0, 6)" :key="o.code" class="px-4 py-2.5">
                                            <button type="button" :class="VOCE" @click="apriLavoro(o.id)">{{ o.title }}</button>
                                            <div class="text-[13px] text-gray-600">{{ [o.completed_at ? formatData(o.completed_at) : 'data non registrata', o.area].filter(Boolean).join(' · ') }}</div>
                                        </li>
                                    </ul>
                                    <p v-else class="px-4 py-4 text-sm text-gray-600">Nessun lavoro completato finora.</p>
                                    <div class="border-t border-gray-100 px-4 py-2"><button type="button" class="min-h-11 text-[13px] font-semibold text-green-800 underline-offset-2 hover:underline md:min-h-9" @click="filtroLavori = 'fatti'; scheda = 'lavori'">Tutti i lavori fatti</button></div>
                                </section>
                            </div>
                        </div>

                        <div class="flex min-w-0 flex-col gap-4">
                            <section :class="CARTA" class="p-4" data-test="portale-novita">
                                <h2 class="text-base font-bold text-gray-900">Ultimi {{ riepilogo.recenti.giorni }} giorni</h2>
                                <ul v-if="novita.length" class="mt-2 space-y-1 text-sm text-gray-900">
                                    <li v-for="(n, i) in novita" :key="i">{{ n }}</li>
                                </ul>
                                <p v-else class="mt-2 text-sm text-gray-600">Nessuna novità registrata.</p>
                            </section>

                            <section :class="CARTA" data-test="portale-richieste-aperte">
                                <h2 class="border-b border-gray-200 px-4 py-3 text-base font-bold text-gray-900">Le vostre richieste aperte</h2>
                                <ul v-if="richiesteAperte.length" class="divide-y divide-gray-100 text-sm">
                                    <li v-for="r in richiesteAperte.slice(0, 5)" :key="r.id" class="px-4 py-2.5">
                                        <div class="flex flex-wrap items-center gap-2"><span class="font-semibold text-gray-900">{{ r.code }}</span><span :class="STATO_RICHIESTA[r.status]?.chip">{{ STATO_RICHIESTA[r.status]?.etichetta }}</span></div>
                                        <div class="truncate text-[13px] text-gray-700" :title="r.description">{{ r.description }}</div>
                                        <div v-if="r.lavoro" class="text-[13px] text-gray-600">Lavoro {{ r.lavoro.code }}: {{ r.lavoro.status_etichetta.toLowerCase() }}</div>
                                    </li>
                                </ul>
                                <p v-else class="px-4 py-4 text-sm text-gray-600">Nessuna richiesta aperta.</p>
                                <div class="border-t border-gray-100 px-4 py-2"><button type="button" class="min-h-11 text-[13px] font-semibold text-green-800 underline-offset-2 hover:underline md:min-h-9" @click="scheda = 'segnalazioni'">Tutte le richieste</button></div>
                            </section>

                            <section :class="CARTA" data-test="portale-documenti-recenti">
                                <h2 class="border-b border-gray-200 px-4 py-3 text-base font-bold text-gray-900">Ultimi documenti</h2>
                                <ul v-if="documenti?.perizie?.length" class="divide-y divide-gray-100 text-sm">
                                    <li v-for="d in documenti.perizie.slice(0, 4)" :key="d.id" class="flex flex-wrap items-center justify-between gap-2 px-4 py-2.5">
                                        <div class="min-w-0">
                                            <div class="font-semibold text-gray-900">Perizia n. {{ d.report_number }} · {{ d.census_code ?? 'senza cartellino' }}</div>
                                            <div class="text-[13px] text-gray-600">{{ formatData(d.report_issued_at) }} · classe {{ d.failure_class }}</div>
                                        </div>
                                        <a :href="d.pdf" target="_blank" rel="noopener" :class="BOTTONE_PICCOLO">PDF</a>
                                    </li>
                                </ul>
                                <p v-else class="px-4 py-4 text-sm text-gray-600">Nessuna perizia emessa finora.</p>
                                <div class="border-t border-gray-100 px-4 py-2"><button type="button" class="min-h-11 text-[13px] font-semibold text-green-800 underline-offset-2 hover:underline md:min-h-9" @click="scheda = 'documenti'">Tutti i documenti</button></div>
                            </section>

                            <section :class="CARTA" class="p-4" data-test="portale-aree">
                                <h2 class="text-base font-bold text-gray-900">Le aree</h2>
                                <ul v-if="riepilogo.areas.length" class="mt-2 divide-y divide-gray-100 text-sm">
                                    <li v-for="a in riepilogo.areas" :key="a.id" class="flex flex-wrap items-center justify-between gap-x-3 py-1.5">
                                        <button type="button" :class="VOCE" class="font-medium" @click="azzeraFiltri(); filtri.area_id = a.id; scheda = 'patrimonio'">{{ a.name }}</button>
                                        <span class="tabular-nums text-gray-600">{{ conta(a.elementi, 'elemento', 'elementi') }}<template v-if="a.area_sqm"> · {{ num(Math.round(a.area_sqm), 0) }} m²</template></span>
                                    </li>
                                </ul>
                                <p v-else class="mt-2 text-sm text-gray-600">Nessuna area collegata.</p>
                            </section>

                            <section v-if="Object.keys(contatti).length" :class="CARTA" class="p-4" data-test="portale-contatti">
                                <h2 class="text-base font-bold text-gray-900">Chi si occupa del vostro verde</h2>
                                <dl class="mt-2 space-y-1 text-sm text-gray-900">
                                    <div v-if="contatti.studio"><dt class="sr-only">Studio</dt><dd class="font-semibold">{{ contatti.studio }}</dd></div>
                                    <div v-if="contatti.professionista"><dt class="sr-only">Professionista</dt><dd>{{ contatti.professionista }}<template v-if="contatti.titolo">, {{ contatti.titolo }}</template></dd></div>
                                    <div v-if="contatti.iscrizione"><dt class="sr-only">Iscrizione</dt><dd class="text-gray-600">{{ contatti.iscrizione }}</dd></div>
                                    <div v-if="contatti.recapiti"><dt class="sr-only">Recapiti</dt><dd class="whitespace-pre-line">{{ contatti.recapiti }}</dd></div>
                                    <div v-if="contatti.telefono"><dt class="inline text-gray-500">Telefono </dt><dd class="inline"><a :href="`tel:${contatti.telefono.replace(/\s+/g, '')}`" class="inline-flex min-h-11 items-center underline-offset-2 hover:underline md:min-h-0">{{ contatti.telefono }}</a></dd></div>
                                    <div v-if="contatti.email"><dt class="inline text-gray-500">E-mail </dt><dd class="inline"><a :href="`mailto:${contatti.email}`" class="inline-flex min-h-11 items-center underline-offset-2 hover:underline md:min-h-0">{{ contatti.email }}</a></dd></div>
                                    <div v-if="contatti.pec"><dt class="inline text-gray-500">PEC </dt><dd class="inline"><a :href="`mailto:${contatti.pec}`" class="inline-flex min-h-11 items-center underline-offset-2 hover:underline md:min-h-0">{{ contatti.pec }}</a></dd></div>
                                </dl>
                            </section>
                        </div>
                    </div>
                </template>

                <!-- Mappa -->
                <div v-else-if="scheda === 'mappa'" class="grid gap-4" :class="elementoScelto ? 'lg:grid-cols-[minmax(0,1fr)_420px] lg:items-start' : ''">
                    <MappaTerritorio ref="mappaGrande" :sfondi="sfondi" :estensione="riepilogo.estensione" :evidenziato="elementoScelto" altezza="h-[70vh] min-h-[420px]" @apri="(e) => { elementoScelto = e.id; }">
                        <template #sopra>
                            <div class="pointer-events-auto relative w-full max-w-sm">
                                <label class="block">
                                    <span class="sr-only">Cerca un elemento</span>
                                    <input
                                        v-model="ricercaMappa.testo"
                                        type="search"
                                        class="min-h-11 w-full rounded-lg border border-gray-300 bg-white/95 px-3 text-sm shadow-sm placeholder:text-gray-500 focus:border-green-700 focus:outline-none focus:ring-2 focus:ring-green-700/30 md:min-h-9"
                                        placeholder="Cerca: cartellino, specie, area"
                                        data-test="portale-mappa-ricerca"
                                        @focus="ricercaMappa.aperta = ricercaMappa.risultati.length > 0"
                                    >
                                </label>
                                <ul v-if="ricercaMappa.aperta" :class="CARTA" class="absolute left-0 right-0 z-20 mt-1 max-h-72 overflow-y-auto shadow-lg" data-test="portale-mappa-risultati">
                                    <li v-for="r in ricercaMappa.risultati" :key="r.id">
                                        <button type="button" class="flex min-h-11 w-full flex-wrap items-center gap-x-2 px-3 py-1.5 text-left text-sm hover:bg-gray-50" @click="apriSullaMappa(r)">
                                            <span class="font-semibold text-gray-900">{{ r.census_code ?? 'senza cartellino' }}</span>
                                            <span class="text-gray-600">{{ r.specie ?? r.tipo }}</span>
                                            <span v-if="r.area" class="text-[13px] text-gray-500">· {{ r.area }}</span>
                                        </button>
                                    </li>
                                    <li v-if="! ricercaMappa.risultati.length" class="px-3 py-2 text-sm text-gray-500">Nessun elemento trovato.</li>
                                </ul>
                            </div>
                        </template>
                    </MappaTerritorio>
                    <aside v-if="elementoScelto" :class="CARTA" class="order-first scroll-mt-20 p-4 lg:order-none lg:max-h-[70vh] lg:overflow-y-auto" data-test="portale-mappa-pannello">
                        <SchedaElemento :elemento-id="elementoScelto" :navigazione-url="navigazioneUrl" compatta @chiudi="elementoScelto = null" @mappa="(d) => mappaGrande?.vaiA(d)" @lavoro="apriLavoro" />
                    </aside>
                </div>

                <!-- Patrimonio -->
                <template v-else-if="scheda === 'patrimonio'">
                    <div class="flex flex-wrap items-center gap-2">
                        <label class="relative min-w-0 flex-1 basis-64">
                            <span class="sr-only">Cerca</span>
                            <input v-model="filtri.q" type="search" class="min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm placeholder:text-gray-400 focus:border-green-700 focus:outline-none focus:ring-2 focus:ring-green-700/30 md:min-h-9" placeholder="Cerca: cartellino, specie, area" data-test="portale-ricerca">
                        </label>
                        <select v-model="filtri.area_id" class="min-h-11 w-full rounded-lg border border-gray-300 bg-white px-2.5 text-sm sm:w-auto md:min-h-9" aria-label="Area" data-test="portale-filtro-area">
                            <option value="">Area: tutte</option>
                            <option v-for="a in riepilogo.areas" :key="a.id" :value="a.id">{{ a.name }}</option>
                        </select>
                        <select v-model="filtri.stato" class="min-h-11 w-full rounded-lg border border-gray-300 bg-white px-2.5 text-sm sm:w-auto md:min-h-9" aria-label="Stato" data-test="portale-filtro-stato">
                            <option value="">Stato: tutti</option>
                            <option v-for="(s, k) in STATI" :key="k" :value="k">{{ k === 'altro' ? 'Altri elementi (non alberi)' : s.etichetta }}</option>
                        </select>
                        <select v-model="filtri.tipo" class="min-h-11 w-full rounded-lg border border-gray-300 bg-white px-2.5 text-sm sm:w-auto md:min-h-9" aria-label="Tipo">
                            <option value="">Alberi e altri elementi</option>
                            <option value="alberi">Solo alberi</option>
                            <option value="altri">Solo altri elementi</option>
                        </select>
                        <button
                            type="button"
                            class="inline-flex min-h-11 items-center gap-1.5 rounded-full border px-3 text-[13px] font-semibold transition md:min-h-9"
                            :class="filtri.vta === 'scaduta' ? 'border-red-800 bg-red-800 text-white' : 'border-gray-300 bg-white text-gray-700 hover:border-red-300'"
                            :aria-pressed="filtri.vta === 'scaduta'"
                            data-test="portale-scorciatoia-vta"
                            @click="filtri.vta = filtri.vta === 'scaduta' ? '' : 'scaduta'"
                        >Ricontrollo scaduto <span :class="filtri.vta === 'scaduta' ? '' : 'text-red-800'">{{ conteggi.vta_scadute }}</span></button>
                        <button v-if="filtriAttivi" type="button" class="min-h-11 px-2 text-[13px] font-medium text-gray-600 underline-offset-2 hover:underline md:min-h-9" @click="azzeraFiltri">Azzera i filtri</button>
                    </div>

                    <AvvisoErrore :messaggio="erroreElenco" @riprova="caricaElenco" />

                    <div class="grid gap-4" :class="elementoScelto ? 'lg:grid-cols-[minmax(0,1fr)_420px] lg:items-start' : ''">
                        <section :class="CARTA" class="min-w-0">
                            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 px-4 py-3 text-sm">
                                <span v-if="meta" class="font-semibold text-gray-900" data-test="portale-elenco-totale">{{ conta(meta.total, 'elemento', 'elementi') }}<template v-if="filtriAttivi"> con questi filtri</template></span>
                                <span v-if="caricamentoElenco" class="text-gray-500">Carico…</span>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm" data-test="portale-elenco">
                                    <thead>
                                        <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500">
                                            <th class="px-4 py-2.5 font-semibold">Cartellino</th>
                                            <th class="px-3 py-2.5 font-semibold">Specie o tipo</th>
                                            <th class="px-3 py-2.5 font-semibold">Area</th>
                                            <th class="px-3 py-2.5 font-semibold">Stato</th>
                                            <th class="px-3 py-2.5 font-semibold">Stabilità</th>
                                            <th class="px-3 py-2.5 text-right font-semibold">Foto</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <tr v-for="r in elenco" :key="r.id" class="cursor-pointer hover:bg-gray-50" :class="elementoScelto === r.id ? 'bg-green-50' : ''" data-test="portale-riga" @click="elementoScelto = r.id">
                                            <td class="px-4 py-2.5"><button type="button" class="min-h-11 font-semibold text-gray-900 underline-offset-2 hover:underline md:min-h-0" :aria-pressed="elementoScelto === r.id" @click.stop="elementoScelto = r.id">{{ r.census_code ?? 'senza cartellino' }}</button></td>
                                            <td class="whitespace-nowrap px-3 py-2.5 text-gray-800"><i v-if="r.specie">{{ r.specie }}</i><template v-else>{{ r.tipo }}</template><span v-if="r.nome_comune" class="text-gray-500"> · {{ r.nome_comune }}</span></td>
                                            <td class="whitespace-nowrap px-3 py-2.5 text-gray-700">{{ r.area ?? '—' }}</td>
                                            <td class="px-3 py-2.5"><span v-if="r.stato_etichetta" :class="STATI[r.stato]?.chip">{{ r.stato_etichetta }}</span><span v-else class="text-gray-500">—</span></td>
                                            <td class="px-3 py-2.5 whitespace-nowrap tabular-nums" :class="ricontrolloScaduto(r) ? 'font-semibold text-red-800' : 'text-gray-700'">
                                                <template v-if="r.vta_data">{{ r.vta_classe ? `classe ${r.vta_classe}` : 'senza classe' }} · {{ formatData(r.vta_data) }}<template v-if="ricontrolloScaduto(r)"> · scaduta</template></template>
                                                <template v-else-if="r.albero">mai valutato</template>
                                                <template v-else>—</template>
                                            </td>
                                            <td class="px-3 py-2.5 text-right tabular-nums text-gray-700">{{ r.n_foto || '—' }}</td>
                                        </tr>
                                        <tr v-if="! elenco.length && ! caricamentoElenco">
                                            <td colspan="6" class="px-4 py-8 text-center text-sm text-gray-500">{{ filtriAttivi ? 'Nessun elemento con questi filtri.' : 'Nessun elemento censito finora.' }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div v-if="meta && meta.last_page > 1" class="flex flex-wrap items-center justify-between gap-2 border-t border-gray-200 px-4 py-2 text-[13px] text-gray-600">
                                <span data-test="portale-pagine">Righe {{ meta.from }}–{{ meta.to }} di {{ num(meta.total, 0) }} · pagina {{ meta.current_page }} di {{ meta.last_page }}</span>
                                <div class="flex gap-2">
                                    <button type="button" :class="BOTTONE_PICCOLO" :disabled="meta.current_page <= 1 || caricamentoElenco" @click="filtri.page = meta.current_page - 1">← Precedente</button>
                                    <button type="button" :class="BOTTONE_PICCOLO" :disabled="meta.current_page >= meta.last_page || caricamentoElenco" @click="filtri.page = meta.current_page + 1">Successiva →</button>
                                </div>
                            </div>
                        </section>
                        <aside v-if="elementoScelto" :class="CARTA" class="order-first min-w-0 scroll-mt-20 p-4 lg:order-none lg:sticky lg:top-4" data-test="portale-anteprima">
                            <SchedaElemento :elemento-id="elementoScelto" :navigazione-url="navigazioneUrl" compatta @chiudi="elementoScelto = null" @mappa="apriSullaMappa" @lavoro="apriLavoro" />
                        </aside>
                    </div>
                </template>

                <!-- Lavori -->
                <template v-else-if="scheda === 'lavori'">
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="flex flex-wrap gap-2 md:inline-flex md:gap-0 md:overflow-hidden md:rounded-lg md:border md:border-gray-300 md:bg-white">
                            <button
                                v-for="[chiave, etichetta, n] in [['aperti', 'In programma e in corso', conteggi.open_orders], ['fatti', 'Fatti', conteggi.completed_orders], ['tutti', 'Tutti', null]]"
                                :key="chiave"
                                type="button"
                                class="inline-flex min-h-11 items-center whitespace-nowrap rounded-lg border px-3 text-sm font-semibold transition md:min-h-9 md:rounded-none md:border-0 md:border-r md:border-gray-200 md:last:border-r-0"
                                :class="filtroLavori === chiave ? 'border-green-700 bg-green-700 text-white' : 'border-gray-300 text-gray-700 hover:bg-gray-50'"
                                :aria-pressed="filtroLavori === chiave"
                                :data-test="`portale-lavori-${chiave}`"
                                @click="filtroLavori = chiave"
                            >{{ etichetta }}<template v-if="n !== null"> · {{ n }}</template></button>
                        </div>
                        <span v-if="caricamentoLavori" class="text-sm text-gray-500">Carico…</span>
                    </div>

                    <AvvisoErrore :messaggio="erroreLavori" @riprova="caricaLavori" />

                    <div class="grid gap-4" :class="lavoroScelto ? 'lg:grid-cols-[minmax(0,1fr)_420px] lg:items-start' : ''">
                        <section :class="CARTA" class="min-w-0">
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm" data-test="portale-lavori">
                                    <thead>
                                        <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500">
                                            <th class="min-w-[14rem] px-4 py-2.5 font-semibold">Intervento</th>
                                            <th class="px-3 py-2.5 font-semibold">Area</th>
                                            <th class="px-3 py-2.5 font-semibold">Quando</th>
                                            <th class="px-3 py-2.5 font-semibold">Stato</th>
                                            <th class="px-3 py-2.5 text-right font-semibold">Elementi</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <tr v-for="l in lavori" :key="l.id" class="cursor-pointer hover:bg-gray-50" :class="lavoroScelto === l.id ? 'bg-green-50' : ''" data-test="portale-lavoro-riga" @click="apriLavoro(l.id)">
                                            <td class="px-4 py-2.5">
                                                <button type="button" class="text-left font-semibold text-gray-900 underline-offset-2 hover:underline" @click.stop="apriLavoro(l.id)">{{ l.title }}</button>
                                                <div class="text-[13px] text-gray-500">{{ l.code }}<template v-if="l.tipo"> · {{ l.tipo }}</template><template v-if="l.richiesta"> · dalla vostra richiesta {{ l.richiesta.code }}</template></div>
                                            </td>
                                            <td class="whitespace-nowrap px-3 py-2.5 text-gray-700">{{ l.area ?? '—' }}</td>
                                            <td class="px-3 py-2.5 whitespace-nowrap tabular-nums text-gray-700">{{ periodoLavoro(l) }}</td>
                                            <td class="px-3 py-2.5"><span :class="STATO_LAVORO[l.status]">{{ l.status_etichetta }}</span></td>
                                            <td class="px-3 py-2.5 text-right tabular-nums text-gray-700">{{ l.elementi_totali ? `${l.elementi_fatti}/${l.elementi_totali}` : '—' }}</td>
                                        </tr>
                                        <tr v-if="! lavori.length && ! caricamentoLavori">
                                            <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500">{{ filtroLavori === 'aperti' ? 'Nessun lavoro in programma o in corso.' : 'Nessun lavoro.' }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div v-if="metaLavori && metaLavori.last_page > 1" class="flex flex-wrap items-center justify-between gap-2 border-t border-gray-200 px-4 py-2 text-[13px] text-gray-600">
                                <span>Righe {{ metaLavori.from }}–{{ metaLavori.to }} di {{ num(metaLavori.total, 0) }}</span>
                                <div class="flex gap-2">
                                    <button type="button" :class="BOTTONE_PICCOLO" :disabled="metaLavori.current_page <= 1" @click="paginaLavori = metaLavori.current_page - 1">← Precedente</button>
                                    <button type="button" :class="BOTTONE_PICCOLO" :disabled="metaLavori.current_page >= metaLavori.last_page" @click="paginaLavori = metaLavori.current_page + 1">Successiva →</button>
                                </div>
                            </div>
                        </section>

                        <aside v-if="lavoroScelto" :class="CARTA" class="order-first min-w-0 scroll-mt-20 p-4 lg:order-none lg:sticky lg:top-4" data-test="portale-lavoro-dettaglio">
                            <AvvisoErrore :messaggio="dettaglioLavoro.errore" @riprova="apriLavoro(lavoroScelto)" />
                            <p v-if="dettaglioLavoro.caricamento" class="text-sm text-gray-500">Carico il lavoro…</p>
                            <template v-if="dettaglioLavoro.dati">
                                <header class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2"><h2 class="text-xl font-bold text-gray-900">{{ dettaglioLavoro.dati.title }}</h2><span :class="STATO_LAVORO[dettaglioLavoro.dati.status]">{{ dettaglioLavoro.dati.status_etichetta }}</span></div>
                                        <p class="text-[13px] text-gray-500">{{ [dettaglioLavoro.dati.code, dettaglioLavoro.dati.tipo, dettaglioLavoro.dati.area].filter(Boolean).join(' · ') }}</p>
                                    </div>
                                    <button type="button" class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg text-gray-600 hover:bg-gray-100 md:min-h-9 md:min-w-9" aria-label="Chiudi" @click="lavoroScelto = null; dettaglioLavoro.dati = null">✕</button>
                                </header>
                                <p class="mt-2 text-sm tabular-nums text-gray-900">{{ periodoLavoro(dettaglioLavoro.dati).replace(/^./, (c) => c.toUpperCase()) }}</p>
                                <p v-if="dettaglioLavoro.dati.richiesta" class="mt-1 text-sm text-gray-700">Nato dalla vostra richiesta <b>{{ dettaglioLavoro.dati.richiesta.code }}</b>.</p>
                                <p v-if="dettaglioLavoro.dati.description" class="mt-3 whitespace-pre-line text-sm text-gray-800">{{ dettaglioLavoro.dati.description }}</p>

                                <div v-if="dettaglioLavoro.dati.foto.length" class="mt-3 grid grid-cols-3 gap-1.5">
                                    <a v-for="f in dettaglioLavoro.dati.foto.slice(0, 9)" :key="f.id" :href="f.url" target="_blank" rel="noopener" class="aspect-square overflow-hidden rounded-md bg-gray-100"><img :src="f.url" :alt="`Fotografia del lavoro del ${formatData(f.taken_at ?? f.created_at)}`" class="h-full w-full object-cover" loading="lazy"></a>
                                </div>

                                <h3 class="mt-4" :class="ETICHETTA">Elementi interessati</h3>
                                <ul v-if="dettaglioLavoro.dati.elementi.length" class="mt-1 divide-y divide-gray-100 text-sm">
                                    <li v-for="e in dettaglioLavoro.dati.elementi" :key="e.asset_id" class="flex flex-wrap items-center gap-x-2 py-1.5">
                                        <button type="button" class="font-semibold text-gray-900 underline-offset-2 hover:underline" @click="scheda = 'patrimonio'; elementoScelto = e.asset_id">{{ e.census_code ?? 'senza cartellino' }}</button>
                                        <span class="text-gray-600"><i v-if="e.specie">{{ e.specie }}</i><template v-else>{{ e.tipo }}</template></span>
                                        <span v-if="e.quantita" class="tabular-nums text-gray-500">{{ num(e.quantita) }} {{ e.unita }}</span>
                                        <span :class="e.fatto ? CHIP.ok : CHIP.neutra">{{ e.fatto ? 'fatto' : 'da fare' }}</span>
                                    </li>
                                </ul>
                                <p v-else class="mt-1 text-sm text-gray-600">Il lavoro non è riferito a singoli elementi.</p>
                            </template>
                        </aside>
                    </div>
                </template>

                <!-- Segnalazioni e richieste -->
                <template v-else-if="scheda === 'segnalazioni'">
                    <ModuloRichiesta v-if="moduloAperto" :aree="riepilogo.areas" @inviata="(r) => { richieste.unshift(r); caricaRiepilogo(); }" @annulla="moduloAperto = false" />
                    <div v-else><button type="button" :class="BOTTONE" @click="moduloAperto = true">Nuova richiesta</button></div>

                    <section :class="CARTA">
                        <h2 class="border-b border-gray-200 px-4 py-3 text-base font-bold text-gray-900">Le vostre richieste</h2>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm" data-test="portal-requests">
                                <thead>
                                    <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500">
                                        <th class="px-4 py-2.5 font-semibold">Codice</th>
                                        <th class="px-3 py-2.5 font-semibold">Inviata il</th>
                                        <th class="px-3 py-2.5 font-semibold">Stato</th>
                                        <th class="px-3 py-2.5 font-semibold">Descrizione</th>
                                        <th class="px-3 py-2.5 font-semibold">Lavoro</th>
                                        <th class="px-3 py-2.5 font-semibold">Esito</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <tr v-for="r in richieste" :key="r.id" data-test="req-row">
                                        <td class="px-4 py-2.5 font-semibold text-gray-900">{{ r.code }}</td>
                                        <td class="px-3 py-2.5 whitespace-nowrap tabular-nums text-gray-700">{{ formatData(r.created_at) }}</td>
                                        <td class="px-3 py-2.5"><span :class="STATO_RICHIESTA[r.status]?.chip">{{ STATO_RICHIESTA[r.status]?.etichetta ?? r.status }}</span></td>
                                        <td class="max-w-72 px-3 py-2.5 text-gray-800"><div class="truncate" :title="r.description">{{ r.description }}</div><div class="text-[13px] text-gray-500">{{ [r.area, r.photos_count ? `${r.photos_count} foto` : null, URGENZA[r.severity] ? `urgenza ${URGENZA[r.severity].toLowerCase()}` : null].filter(Boolean).join(' · ') }}</div></td>
                                        <td class="px-3 py-2.5 text-gray-700"><button v-if="r.lavoro" type="button" class="text-left underline-offset-2 hover:underline" @click="apriLavoro(r.lavoro.id)">{{ r.lavoro.code }} · {{ r.lavoro.status_etichetta.toLowerCase() }}</button><template v-else>—</template></td>
                                        <td class="max-w-56 px-3 py-2.5 text-gray-700"><div class="truncate" :title="r.resolution_notes ?? ''">{{ r.resolution_notes ?? '—' }}</div></td>
                                    </tr>
                                    <tr v-if="! richieste.length"><td colspan="6" class="px-4 py-8 text-center text-sm text-gray-500">Nessuna richiesta inviata finora.</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section :class="CARTA" data-test="portal-issues">
                        <h2 class="border-b border-gray-200 px-4 py-3 text-base font-bold text-gray-900">Altre segnalazioni sul territorio</h2>
                        <p class="px-4 pt-3 text-[13px] text-gray-500">Segnalazioni arrivate dai cittadini, dal portale pubblico o dal personale in campo sulle vostre aree.</p>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500">
                                        <th class="px-4 py-2.5 font-semibold">Codice</th>
                                        <th class="px-3 py-2.5 font-semibold">Aperta il</th>
                                        <th class="px-3 py-2.5 font-semibold">Stato</th>
                                        <th class="px-3 py-2.5 font-semibold">Gravità</th>
                                        <th class="px-3 py-2.5 font-semibold">Descrizione</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <tr v-for="i in altreSegnalazioni" :key="i.code">
                                        <td class="px-4 py-2.5 font-semibold text-gray-900">{{ i.code }}</td>
                                        <td class="px-3 py-2.5 whitespace-nowrap tabular-nums text-gray-700">{{ formatData(i.created_at) }}</td>
                                        <td class="px-3 py-2.5"><span :class="STATO_RICHIESTA[i.status]?.chip">{{ STATO_RICHIESTA[i.status]?.etichetta ?? i.status }}</span></td>
                                        <td class="px-3 py-2.5 text-gray-700">{{ URGENZA[i.severity] ?? i.severity }}</td>
                                        <td class="max-w-96 px-3 py-2.5 text-gray-800"><div class="truncate" :title="i.description">{{ i.description }}</div><div v-if="i.area" class="text-[13px] text-gray-500">{{ i.area }}</div></td>
                                    </tr>
                                    <tr v-if="! altreSegnalazioni.length"><td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500">Nessun'altra segnalazione sul territorio.</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </template>

                <!-- Documenti -->
                <template v-else-if="scheda === 'documenti'">
                    <p class="max-w-[78ch] text-sm text-gray-700">
                        I documenti emessi per il vostro territorio, da scaricare in PDF: le perizie di stabilità degli alberi e i verbali
                        delle ispezioni. Una perizia <b>validata</b> è un atto chiuso: non si corregge e ristampata è identica.
                    </p>
                    <section :class="CARTA" data-test="portale-perizie">
                        <h2 class="border-b border-gray-200 px-4 py-3 text-base font-bold text-gray-900">Perizie di stabilità<template v-if="documenti"> · {{ documenti.perizie.length }}</template></h2>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500">
                                        <th class="px-4 py-2.5 font-semibold">Numero</th>
                                        <th class="px-3 py-2.5 font-semibold">Albero</th>
                                        <th class="px-3 py-2.5 font-semibold">Sopralluogo</th>
                                        <th class="px-3 py-2.5 font-semibold">Classe ed esito</th>
                                        <th class="px-3 py-2.5 font-semibold">Emessa</th>
                                        <th class="px-3 py-2.5 font-semibold"><span class="sr-only">Scarica</span></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <tr v-for="d in documenti?.perizie ?? []" :key="d.id" data-test="portale-perizia">
                                        <td class="px-4 py-2.5 font-semibold text-gray-900">{{ d.report_number }}</td>
                                        <td class="px-3 py-2.5"><button type="button" class="font-semibold text-gray-900 underline-offset-2 hover:underline" @click="scheda = 'patrimonio'; elementoScelto = d.asset_id">{{ d.census_code ?? 'senza cartellino' }}</button><div v-if="d.specie" class="text-[13px] text-gray-500"><i>{{ d.specie }}</i></div></td>
                                        <td class="px-3 py-2.5 whitespace-nowrap tabular-nums text-gray-700">{{ formatData(d.assessed_on) }}</td>
                                        <td class="px-3 py-2.5 text-gray-800"><b>{{ d.failure_class ? `Classe ${d.failure_class}` : '—' }}</b><div class="text-[13px] text-gray-600">{{ d.outcome_etichetta }}</div></td>
                                        <td class="px-3 py-2.5 whitespace-nowrap tabular-nums text-gray-700">{{ formatData(d.report_issued_at) }}<div v-if="d.validated_at" class="text-[13px] text-green-800">validata il {{ formatData(d.validated_at) }}</div></td>
                                        <td class="px-3 py-2.5 text-right"><a :href="d.pdf" target="_blank" rel="noopener" :class="BOTTONE_PICCOLO">PDF</a></td>
                                    </tr>
                                    <tr v-if="documenti && ! documenti.perizie.length"><td colspan="6" class="px-4 py-8 text-center text-sm text-gray-500">Nessuna perizia emessa finora.</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                    <section :class="CARTA" data-test="portale-verbali">
                        <h2 class="border-b border-gray-200 px-4 py-3 text-base font-bold text-gray-900">Verbali di ispezione<template v-if="documenti"> · {{ documenti.verbali.length }}</template></h2>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500">
                                        <th class="px-4 py-2.5 font-semibold">Ispezione</th>
                                        <th class="px-3 py-2.5 font-semibold">Oggetto</th>
                                        <th class="px-3 py-2.5 font-semibold">Data</th>
                                        <th class="px-3 py-2.5 font-semibold">Esito</th>
                                        <th class="px-3 py-2.5 font-semibold"><span class="sr-only">Scarica</span></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <tr v-for="v in documenti?.verbali ?? []" :key="v.id">
                                        <td class="px-4 py-2.5 font-semibold text-gray-900">{{ v.modello ?? 'Ispezione' }}</td>
                                        <td class="px-3 py-2.5 text-gray-700">{{ v.oggetto ?? '—' }}</td>
                                        <td class="px-3 py-2.5 whitespace-nowrap tabular-nums text-gray-700">{{ formatData(v.completata_il) }}</td>
                                        <td class="px-3 py-2.5"><span :class="v.esito === 'failed' ? CHIP.errore : (v.esito === 'passed' ? CHIP.ok : CHIP.attenzione)">{{ v.esito_etichetta }}</span></td>
                                        <td class="px-3 py-2.5 text-right"><a :href="v.pdf" target="_blank" rel="noopener" :class="BOTTONE_PICCOLO">PDF</a></td>
                                    </tr>
                                    <tr v-if="documenti && ! documenti.verbali.length"><td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500">Nessun verbale di ispezione chiuso.</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </template>
            </template>
        </div>
    </AppLayout>
</template>
