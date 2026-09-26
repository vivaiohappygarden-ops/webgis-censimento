<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import AvvisoErrore from '@/Components/AvvisoErrore.vue';
import ScegliCommittente from '@/Components/ScegliCommittente.vue';
import VisteSalvate from '@/Components/VisteSalvate.vue';
import WorkAgenda from '@/Components/WorkAgenda.vue';
import GanttLavori from '@/Components/GanttLavori.vue';
import CalendarioAbbonamento from '@/Components/CalendarioAbbonamento.vue';
import WorkReport from '@/Components/WorkReport.vue';
import RelazioneAnnuale from '@/Components/RelazioneAnnuale.vue';
import QualityBoard from '@/Components/QualityBoard.vue';
import QuotesPanel from '@/Components/QuotesPanel.vue';
import SalPanel from '@/Components/SalPanel.vue';
import PianiPanel from '@/Components/PianiPanel.vue';
import TestataLavori from '@/Components/Nuovo/TestataLavori.vue';
import NuovoOrdine from '@/Components/Nuovo/NuovoOrdine.vue';
import { usaCaricamento } from '@/caricamento';
import { avvisoCaricamento, messaggioErrore } from '@/avvisi';
import { WORK_STATUS_LABELS } from '@/workStatus';
import { BOTTONE, BOTTONE_PICCOLO, BOTTONE_SECONDARIO, CARTA, CHIP, plurale } from '@/nuovo/stile';

/*
 * Lavori, veste nuova (bozza A, schermata 06): l'elenco degli ordini con
 * ricerca, filtri, la scorciatoia "in ritardo", la selezione con la chiusura
 * in blocco e, a destra, l'anteprima dell'ordine scelto. Agenda, Gantt e le
 * altre viste sono gli stessi componenti di prima; ogni ordine si apre nella
 * sua pagina (/lavori/{id}). Preventivi, SAL, rendiconto, qualita' e piani
 * restano raggiungibili da "Altro" finche' non trovano posto in Documenti.
 */
const page = usePage();
const permessi = computed(() => page.props.auth?.user?.permissions ?? []);
const can = (p) => permessi.value.includes(p);
const canManage = computed(() => can('works.manage'));

const parametri = new URLSearchParams(window.location.search);
const VISTE = ['ordini', 'agenda', 'gantt', 'qualita', 'preventivi', 'sal', 'piani', 'rendiconto'];
const richiesta = parametri.get('vista') === 'elenco' ? 'ordini' : parametri.get('vista');
const vista = VISTE.includes(richiesta) && (richiesta !== 'sal' || canManage.value) ? richiesta : 'ordini';

const { avviso, riprovaInCorso, carica, riprova } = usaCaricamento();

// --- Anagrafiche di contorno -------------------------------------------------
const teams = ref([]);
const personnel = ref([]);
const workTypes = ref([]);
const priceLists = ref([]);
const clients = ref([]);
const areas = ref([]);

async function tutteLePagine(indirizzo, params = {}) {
    const tutte = [];
    for (let p = 1; p <= 20; p++) {
        const { data } = await axios.get(indirizzo, { params: { ...params, per_page: 100, page: p } });
        tutte.push(...(data.data ?? []));
        if (! data.next_page_url) break;
    }

    return tutte;
}

async function caricaAnagrafiche() {
    const [t, wt, pl] = await Promise.all([axios.get('/api/v1/teams'), axios.get('/api/v1/work-types'), axios.get('/api/v1/price-lists')]);
    teams.value = t.data.data;
    workTypes.value = wt.data.data;
    priceLists.value = pl.data.data;
    if (can('clients.view')) clients.value = await tutteLePagine('/api/v1/clients');
    if (canManage.value) {
        const [p, a] = await Promise.all([axios.get('/api/v1/personnel'), tutteLePagine('/api/v1/areas')]);
        personnel.value = p.data.data;
        areas.value = a;
    }
}

// --- Elenco degli ordini ----------------------------------------------------
const righe = ref([]);
const meta = reactive({ total: 0, current_page: 1, last_page: 1, from: 0, to: 0 });
const caricamento = ref(false);
const inRitardo = ref(null);
const filtri = reactive({
    q: parametri.get('q') ?? '', stato: 'aperti', clientId: '', teamId: '', da: '', a: '', soloRitardo: parametri.get('ritardo') === '1', page: 1,
});
const STATI = [['aperti', 'Stato: aperti'], ['tutti', 'Stato: tutti'], ...Object.entries(WORK_STATUS_LABELS).map(([v, l]) => [v, `Stato: ${l.toLowerCase()}`])];

const parametriElenco = computed(() => ({
    q: filtri.q || undefined,
    status: ['aperti', 'tutti'].includes(filtri.stato) ? undefined : filtri.stato,
    aperti: filtri.stato === 'aperti' ? 1 : undefined,
    client_id: filtri.clientId || undefined,
    team_id: filtri.teamId || undefined,
    from: filtri.da && filtri.a ? filtri.da : undefined,
    to: filtri.da && filtri.a ? filtri.a : undefined,
}));

async function caricaElenco() {
    caricamento.value = true;
    try {
        const { data } = await axios.get('/api/v1/work-orders', {
            params: { ...parametriElenco.value, in_ritardo: filtri.soloRitardo ? 1 : undefined, page: filtri.page, per_page: 50 },
        });
        righe.value = data.data;
        Object.assign(meta, { total: data.total, current_page: data.current_page, last_page: data.last_page, from: data.from ?? 0, to: data.to ?? 0 });
        selezionati.value = [];
    } finally {
        caricamento.value = false;
    }
}

async function contaRitardi() {
    try {
        const { data } = await axios.get('/api/v1/work-orders', { params: { ...parametriElenco.value, in_ritardo: 1, per_page: 1 } });
        inRitardo.value = data.total;
    } catch {
        inRitardo.value = null;
    }
}

let attesa = null;
watch(() => [filtri.q, filtri.stato, filtri.clientId, filtri.teamId, filtri.da, filtri.a, filtri.soloRitardo], () => {
    clearTimeout(attesa);
    attesa = setTimeout(() => {
        filtri.page = 1;
        carica(() => Promise.all([caricaElenco(), contaRitardi()]));
    }, 300);
});
watch(() => filtri.page, () => carica(caricaElenco));

const filtriCorrenti = computed(() => ({
    q: filtri.q, status: ['aperti', 'tutti'].includes(filtri.stato) ? '' : filtri.stato, stato: filtri.stato,
    clientId: filtri.clientId, teamId: filtri.teamId, da: filtri.da, a: filtri.a, soloRitardo: filtri.soloRitardo,
}));
function applicaVista(f) {
    filtri.q = f.q ?? '';
    // Le viste della pagina precedente portano solo q e status
    filtri.stato = f.stato ?? (f.status ? f.status : 'aperti');
    filtri.clientId = f.clientId ?? '';
    filtri.teamId = f.teamId ?? '';
    filtri.da = f.da ?? '';
    filtri.a = f.a ?? '';
    filtri.soloRitardo = Boolean(f.soloRitardo);
}
const filtriAttivi = computed(() => [filtri.q, filtri.clientId, filtri.teamId, filtri.da && filtri.a].filter(Boolean).length + (filtri.stato !== 'aperti' ? 1 : 0) + (filtri.soloRitardo ? 1 : 0));
function azzeraFiltri() {
    Object.assign(filtri, { q: '', stato: 'aperti', clientId: '', teamId: '', da: '', a: '', soloRitardo: false });
}

const oggiIso = new Date().toISOString().slice(0, 10);
const IN_LAVORAZIONE = ['planned', 'assigned', 'in_progress', 'suspended'];
function formatData(v, conAnno = true) {
    if (! v) return null;
    const [a, m, g] = String(v).slice(0, 10).split('-');

    return conAnno ? `${g}/${m}/${a}` : `${g}/${m}`;
}
const ritardo = (r) => IN_LAVORAZIONE.includes(r.status) && r.planned_end && String(r.planned_end).slice(0, 10) < oggiIso;
function periodo(r) {
    const inizio = formatData(r.planned_start, false);
    const fine = formatData(r.planned_end, false);
    if (! inizio && ! fine) return 'da pianificare';
    if (inizio && fine && inizio !== fine) return `${inizio} – ${fine}`;

    return formatData(r.planned_start ?? r.planned_end);
}
const TONO_STATO = { draft: 'neutra', planned: 'info', assigned: 'info', in_progress: 'ok', suspended: 'attenzione', completed: 'neutra', cancelled: 'neutra' };
const giorniRitardo = (r) => Math.round((new Date(oggiIso) - new Date(String(r.planned_end).slice(0, 10))) / 86400000);

// --- Selezione e chiusura in blocco -----------------------------------------
const selezionati = ref([]);
const tuttiSelezionati = computed(() => righe.value.length > 0 && selezionati.value.length === righe.value.length);
function commutaTutti() {
    selezionati.value = tuttiSelezionati.value ? [] : righe.value.map((r) => r.id);
}
const chiusura = reactive({ inCorso: false, anteprima: null, esito: '', saltati: [] });
watch(selezionati, () => { chiusura.anteprima = null; });

// Prima si conta ("N verranno chiusi, M esclusi perche'..."), poi si esegue
async function preparaChiusura() {
    chiusura.inCorso = true;
    chiusura.esito = '';
    chiusura.saltati = [];
    try {
        const { data } = await axios.post('/api/v1/azioni/chiudi-lavori', { ids: selezionati.value, prova: 1 });
        chiusura.anteprima = data.data;
    } catch (err) {
        chiusura.esito = messaggioErrore(err, 'Errore nella verifica.');
    } finally {
        chiusura.inCorso = false;
    }
}
async function chiudiSelezionati() {
    chiusura.inCorso = true;
    chiusura.esito = '';
    try {
        const { data } = await axios.post('/api/v1/azioni/chiudi-lavori', { ids: selezionati.value });
        const fatti = data.data.completati.length;
        chiusura.saltati = data.data.saltati ?? [];
        chiusura.esito = fatti === 1 ? '1 ordine completato.' : `${fatti} ordini completati.`;
        chiusura.anteprima = null;
        await carica(() => Promise.all([caricaElenco(), contaRitardi()]));
    } catch (err) {
        chiusura.esito = messaggioErrore(err, 'Errore nella chiusura.');
    } finally {
        chiusura.inCorso = false;
    }
}

// --- Richieste di riprogrammazione delle imprese ----------------------------
const richieste = ref([]);
const richiesteErrore = ref('');
const rispostaNota = reactive({});
const erroriRichieste = reactive({});
const decisioneInCorso = ref(null);
async function caricaRichieste() {
    if (! canManage.value) return;
    richiesteErrore.value = '';
    try {
        const { data } = await axios.get('/api/v1/riprogrammazioni', { params: { stato: 'aperta' } });
        richieste.value = data.data;
    } catch (err) {
        richiesteErrore.value = `Richieste delle imprese non caricate. ${avvisoCaricamento(err)}`;
    }
}
async function decidiRichiesta(r, esito) {
    decisioneInCorso.value = r.id;
    erroriRichieste[r.id] = '';
    try {
        await axios.post(`/api/v1/riprogrammazioni/${r.id}/decidi`, { esito, response_note: rispostaNota[r.id] || null });
        await Promise.all([caricaRichieste(), caricaElenco()]);
    } catch (err) {
        erroriRichieste[r.id] = messaggioErrore(err, 'Decisione non registrata');
    } finally {
        decisioneInCorso.value = null;
    }
}

// --- Anteprima --------------------------------------------------------------
const anteprima = reactive({ id: null, ordine: null, cronologia: null, caricamento: false, errore: '', busy: false });
async function apriAnteprima(id) {
    if (anteprima.id === id) return;
    Object.assign(anteprima, { id, ordine: null, cronologia: null, errore: '', caricamento: true });
    try {
        const [o, c] = await Promise.all([axios.get(`/api/v1/work-orders/${id}`), axios.get(`/api/v1/work-orders/${id}/cronologia`)]);
        if (anteprima.id !== id) return;
        anteprima.ordine = o.data.data;
        anteprima.cronologia = c.data.data;
    } catch (err) {
        if (anteprima.id === id) anteprima.errore = avvisoCaricamento(err);
    } finally {
        if (anteprima.id === id) anteprima.caricamento = false;
    }
}
const chiudiAnteprima = () => Object.assign(anteprima, { id: null, ordine: null, cronologia: null, errore: '' });
const fmtEuro = (v) => (v == null ? '—' : Number(v).toLocaleString('it-IT', { style: 'currency', currency: 'EUR' }));

async function chiudiDallAnteprima() {
    const o = anteprima.ordine;
    if (! o || ! window.confirm(`Chiudere l'ordine ${o.code} "${o.title}" come completato?`)) return;
    anteprima.busy = true;
    anteprima.errore = '';
    try {
        await axios.post(`/api/v1/work-orders/${o.id}/transition`, { status: 'completed', version: o.version });
        const id = o.id;
        anteprima.id = null;
        await Promise.all([apriAnteprima(id), carica(() => Promise.all([caricaElenco(), contaRitardi()]))]);
    } catch (err) {
        anteprima.errore = messaggioErrore(err, 'Errore nel cambio di stato');
    } finally {
        anteprima.busy = false;
    }
}

// --- Nuovo ordine e collegamenti in ingresso -------------------------------
const nuovo = reactive({ aperto: parametri.get('nuovo') === '1' && canManage.value, elementi: (parametri.get('elementi') ?? '').split(',').map((s) => s.trim()).filter((s) => /^[0-9a-f-]{36}$/i.test(s)) });
const apri = (x) => router.visit(`/lavori/${x !== null && typeof x === 'object' ? x.id : x}`);
function ordineCreato({ ordine, nonAgganciati }) {
    nuovo.aperto = false;
    router.visit(`/lavori/${ordine.id}${nonAgganciati ? `?non_collegati=${nonAgganciati}` : ''}`);
}

// I collegamenti di prima (?ordine=CODICE) portano alla pagina dell'ordine
const ordineRichiesto = parametri.get('ordine');
const ordineNonTrovato = ref('');
async function risolviOrdine() {
    if (! ordineRichiesto) return;
    try {
        const { data } = await axios.get('/api/v1/work-orders', { params: { q: ordineRichiesto, per_page: 10 } });
        const trovato = (data.data ?? []).find((o) => o.code === ordineRichiesto) ?? data.data?.[0];
        if (trovato) router.visit(`/lavori/${trovato.id}`);
        else ordineNonTrovato.value = `L'ordine ${ordineRichiesto} non c'è più (eliminato o di un altro studio).`;
    } catch (err) {
        ordineNonTrovato.value = avvisoCaricamento(err);
    }
}

onMounted(async () => {
    await risolviOrdine();
    await carica(async () => {
        await caricaAnagrafiche();
        if (vista === 'ordini') await Promise.all([caricaElenco(), contaRitardi(), caricaRichieste()]);
    });
});
</script>

<template>
    <Head title="Lavori" />

    <AppLayout>
        <div class="mx-auto flex max-w-[1640px] flex-col gap-4 p-4 md:p-6 lg:px-7">
            <TestataLavori :attiva="vista === 'ordini' || ! ['agenda', 'gantt'].includes(vista) ? (['agenda', 'gantt'].includes(vista) ? vista : 'ordini') : vista" :conteggi="vista === 'ordini' && meta.total ? { ordini: meta.total } : {}">
                <button v-if="canManage" type="button" :class="BOTTONE" data-test="nuovo-ordine-apri" @click="nuovo.aperto = true">Nuovo ordine</button>
                <Link href="/segnalazioni?nuova=1" :class="BOTTONE_SECONDARIO">Nuova segnalazione</Link>
                <details class="relative">
                    <summary :class="BOTTONE_SECONDARIO" class="cursor-pointer list-none">Altro</summary>
                    <div :class="CARTA" class="absolute right-0 z-20 mt-1 w-72 p-2 text-sm shadow-lg" data-test="menu-altro-lavori">
                        <Link href="/lavori?vista=qualita" class="flex min-h-11 items-center rounded-lg px-3 hover:bg-gray-50 md:min-h-9">Qualità e non conformità</Link>
                        <Link href="/lavori?vista=preventivi" class="flex min-h-11 items-center rounded-lg px-3 hover:bg-gray-50 md:min-h-9">Preventivi</Link>
                        <Link v-if="canManage" href="/lavori?vista=sal" class="flex min-h-11 items-center rounded-lg px-3 hover:bg-gray-50 md:min-h-9">Stati di avanzamento (SAL)</Link>
                        <Link href="/lavori?vista=rendiconto" class="flex min-h-11 items-center rounded-lg px-3 hover:bg-gray-50 md:min-h-9">Rendiconto e relazione annuale</Link>
                        <Link href="/lavori?vista=piani" class="flex min-h-11 items-center rounded-lg px-3 hover:bg-gray-50 md:min-h-9">Piani di manutenzione</Link>
                        <Link href="/listini" class="flex min-h-11 items-center rounded-lg px-3 hover:bg-gray-50 md:min-h-9">Listini</Link>
                        <Link href="/lavori?precedente=1" class="flex min-h-11 items-center rounded-lg border-t border-gray-100 px-3 text-gray-600 hover:bg-gray-50 md:min-h-9">Pagina della veste precedente</Link>
                    </div>
                </details>
            </TestataLavori>

            <AvvisoErrore :messaggio="avviso" :in-corso="riprovaInCorso" @riprova="riprova" />
            <p v-if="ordineNonTrovato" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" data-test="ordine-non-trovato">{{ ordineNonTrovato }}</p>

            <!-- Le altre viste: gli stessi componenti di prima -->
            <template v-if="vista === 'agenda'">
                <WorkAgenda :teams="teams" :personnel="personnel" :can-manage="canManage" @open="apri" />
                <CalendarioAbbonamento />
            </template>
            <GanttLavori v-else-if="vista === 'gantt'" :teams="teams" :committenti="clients" @open="apri" />
            <template v-else-if="vista === 'rendiconto'">
                <WorkReport :clients="clients" @open="apri" />
                <RelazioneAnnuale :clients="clients" />
            </template>
            <QualityBoard v-else-if="vista === 'qualita'" :personnel="personnel" :can-manage="canManage" @open-order="apri" />
            <QuotesPanel v-else-if="vista === 'preventivi'" :clients="clients" :work-types="workTypes" :can-manage="canManage" @created-order="apri" />
            <SalPanel v-else-if="vista === 'sal' && canManage" :clients="clients" />
            <PianiPanel v-else-if="vista === 'piani'" :clients="clients" :work-types="workTypes" :teams="teams" :can-manage="canManage" />

            <!-- Ordini -->
            <template v-else>
                <p v-if="richiesteErrore" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" data-test="richieste-errore">{{ richiesteErrore }}</p>
                <section v-if="canManage && richieste.length" :class="CARTA" class="border-blue-200 bg-blue-50/60 p-4" data-test="richieste-imprese">
                    <h2 class="text-sm font-bold text-blue-900">Richieste di spostamento dalle imprese ({{ richieste.length }})</h2>
                    <ul class="mt-2 space-y-2">
                        <li v-for="r in richieste" :key="r.id" class="rounded-lg bg-white p-3 text-sm">
                            <div class="flex flex-wrap items-center gap-2">
                                <Link v-if="r.ordine" :href="`/lavori/${r.ordine.id}`" class="font-semibold text-green-800 hover:underline">{{ r.ordine.code }}</Link>
                                <span v-else class="text-xs text-gray-500">ordine eliminato</span>
                                <span class="font-semibold">{{ r.ordine?.title }}</span>
                                <span class="text-gray-600">— {{ r.impresa }}</span>
                                <span v-if="r.ordine && ['completed', 'cancelled'].includes(r.ordine.status)" :class="CHIP.neutra" data-test="richiesta-ordine-chiuso">{{ WORK_STATUS_LABELS[r.ordine.status] ?? r.ordine.status }}</span>
                            </div>
                            <p class="mt-1 text-[13px] text-gray-700">
                                Motivo: <strong>{{ r.motivo }}</strong><template v-if="r.proposed_start"> · data proposta {{ formatData(r.proposed_start) }}</template>
                                <template v-if="r.ordine?.planned_start"> (oggi prevista dal {{ formatData(r.ordine.planned_start) }})</template>
                                <span v-if="r.notes" class="block">Note: {{ r.notes }}</span>
                            </p>
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <input v-model="rispostaNota[r.id]" placeholder="Risposta per l'impresa (facoltativa)" maxlength="1000" class="w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm sm:w-72" :data-test="`risposta-${r.id}`">
                                <button type="button" :class="BOTTONE_PICCOLO" :disabled="decisioneInCorso === r.id" data-test="richiesta-accetta" @click="decidiRichiesta(r, 'accettata')">{{ r.proposed_start ? 'Accetta e sposta le date' : 'Accetta' }}</button>
                                <button type="button" :class="BOTTONE_PICCOLO" class="!border-gray-300 !text-gray-700" :disabled="decisioneInCorso === r.id" data-test="richiesta-rifiuta" @click="decidiRichiesta(r, 'rifiutata')">Non accogliere</button>
                            </div>
                            <p v-if="erroriRichieste[r.id]" class="mt-1 text-xs text-red-700">{{ erroriRichieste[r.id] }}</p>
                        </li>
                    </ul>
                </section>

                <div class="flex flex-col gap-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <label class="relative min-w-0 flex-1 basis-64">
                            <span class="sr-only">Cerca</span>
                            <input v-model="filtri.q" type="search" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm placeholder:text-gray-400 focus:border-green-700 focus:outline-none focus:ring-1 focus:ring-green-700" placeholder="Cerca: codice, titolo, descrizione" data-test="lavori-ricerca">
                        </label>
                        <select v-model="filtri.stato" class="w-full rounded-lg border border-gray-300 bg-white px-2.5 py-2 text-sm sm:w-auto" aria-label="Stato" data-test="lavori-stato">
                            <option v-for="[v, l] in STATI" :key="v" :value="v">{{ l }}</option>
                        </select>
                        <ScegliCommittente v-if="can('clients.view')" v-model="filtri.clientId" class="w-full sm:w-56" :committenti="clients" tutti="Committente: tutti" />
                        <select v-model="filtri.teamId" class="w-full rounded-lg border border-gray-300 bg-white px-2.5 py-2 text-sm sm:w-auto" aria-label="Squadra" data-test="lavori-squadra">
                            <option value="">Squadra: tutte</option>
                            <option v-for="t in teams" :key="t.id" :value="t.id">{{ t.name }}</option>
                        </select>
                        <label class="flex items-center gap-1 text-sm text-gray-600"><span class="sr-only">Periodo dal</span><input v-model="filtri.da" type="date" class="rounded-lg border border-gray-300 bg-white px-2 py-2 text-sm" aria-label="Periodo dal"> – <input v-model="filtri.a" type="date" class="rounded-lg border border-gray-300 bg-white px-2 py-2 text-sm" aria-label="Periodo al"></label>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            v-if="inRitardo !== null"
                            type="button"
                            class="inline-flex min-h-9 items-center gap-1.5 rounded-full border px-3 text-[13px] font-semibold transition"
                            :class="filtri.soloRitardo ? 'border-red-800 bg-red-800 text-white' : 'border-gray-300 bg-white text-gray-700 hover:border-red-300'"
                            :aria-pressed="filtri.soloRitardo"
                            data-test="scorciatoia-ritardo"
                            @click="filtri.soloRitardo = ! filtri.soloRitardo"
                        >In ritardo <span :class="filtri.soloRitardo ? '' : 'text-red-800'">{{ inRitardo }}</span></button>
                        <button v-if="filtriAttivi" type="button" class="min-h-9 px-2 text-[13px] font-medium text-gray-600 underline-offset-2 hover:underline" @click="azzeraFiltri">Togli i filtri ({{ filtriAttivi }})</button>
                        <div class="ml-auto"><VisteSalvate pagina="lavori" :filtri="filtriCorrenti" @applica="applicaVista" /></div>
                    </div>
                </div>

                <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_380px] lg:items-start">
                    <section :class="CARTA" class="min-w-0" data-test="lavori-elenco">
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-gray-200 px-4 py-2.5 text-sm">
                            <span class="font-semibold text-gray-900" data-test="lavori-contatore">{{ meta.total }} {{ plurale(meta.total, 'ordine', 'ordini') }}{{ filtri.stato === 'aperti' ? (meta.total === 1 ? ' aperto' : ' aperti') : '' }}</span>
                            <template v-if="selezionati.length">
                                <span class="text-gray-600">· {{ selezionati.length }} {{ plurale(selezionati.length, 'selezionato', 'selezionati') }}</span>
                                <button v-if="canManage" type="button" :class="BOTTONE_PICCOLO" :disabled="chiusura.inCorso" data-test="lavori-chiudi-selezionati" @click="preparaChiusura">Chiudi con consuntivo</button>
                            </template>
                            <span v-if="caricamento" class="ml-auto text-xs text-gray-500">Aggiorno…</span>
                        </div>
                        <div v-if="chiusura.anteprima || chiusura.esito || chiusura.saltati.length" class="space-y-2 border-b border-gray-200 bg-gray-50 px-4 py-3 text-sm" data-test="lavori-chiusura">
                            <div v-if="chiusura.anteprima" class="flex flex-wrap items-center gap-2">
                                <span>{{ (chiusura.anteprima.completati ?? []).length }} {{ plurale((chiusura.anteprima.completati ?? []).length, 'verrà chiuso', 'verranno chiusi') }}<template v-if="(chiusura.anteprima.saltati ?? []).length">, {{ chiusura.anteprima.saltati.length }} {{ plurale(chiusura.anteprima.saltati.length, 'escluso', 'esclusi') }}</template>.</span>
                                <button type="button" :class="BOTTONE_PICCOLO" :disabled="chiusura.inCorso || ! (chiusura.anteprima.completati ?? []).length" data-test="lavori-conferma-chiusura" @click="chiudiSelezionati">Conferma</button>
                                <button type="button" class="text-[13px] text-gray-600 underline-offset-2 hover:underline" @click="chiusura.anteprima = null">Annulla</button>
                                <ul v-if="(chiusura.anteprima.saltati ?? []).length" class="w-full text-xs text-gray-600"><li v-for="(s, i) in chiusura.anteprima.saltati.slice(0, 5)" :key="i">{{ s.code ?? s.id }}: {{ s.motivo }}</li></ul>
                            </div>
                            <p v-if="chiusura.esito" data-test="lavori-esito">{{ chiusura.esito }}</p>
                            <ul v-if="chiusura.saltati.length" class="text-xs text-amber-900"><li v-for="(s, i) in chiusura.saltati.slice(0, 5)" :key="i">{{ s.code ?? s.id }}: {{ s.motivo }}</li></ul>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                        <th v-if="canManage" class="w-10 px-3 py-2.5"><input type="checkbox" class="rounded border-gray-300" :checked="tuttiSelezionati" aria-label="Seleziona tutti" @change="commutaTutti"></th>
                                        <th class="px-3 py-2.5">Codice</th><th class="px-3 py-2.5">Che cosa</th><th class="px-3 py-2.5">Dove</th><th class="px-3 py-2.5">Stato</th><th class="px-3 py-2.5">Squadra</th><th class="px-3 py-2.5">Periodo</th><th class="px-3 py-2.5 text-right">Elementi</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <tr v-for="r in righe" :key="r.id" class="cursor-pointer transition hover:bg-gray-50" :class="anteprima.id === r.id ? 'bg-green-50' : ''" data-test="lavori-riga" @click="apriAnteprima(r.id)">
                                        <td v-if="canManage" class="px-3 py-2.5" @click.stop><input v-model="selezionati" type="checkbox" :value="r.id" class="rounded border-gray-300" :aria-label="`Seleziona ${r.code}`" data-test="lavoro-casella"></td>
                                        <td class="whitespace-nowrap px-3 py-2.5"><Link :href="`/lavori/${r.id}`" class="font-semibold text-gray-900 underline-offset-2 hover:underline" @click.stop>{{ r.code }}</Link></td>
                                        <td class="min-w-[12rem] px-3 py-2.5 text-gray-900">{{ r.title }}<div v-if="r.work_type" class="text-xs text-gray-500">{{ r.work_type.name }}</div></td>
                                        <td class="min-w-[12rem] px-3 py-2.5 text-gray-700">{{ r.client?.name ?? '' }}<div v-if="r.area" class="text-xs text-gray-500">{{ r.area.name }}</div><template v-if="! r.client && ! r.area">—</template></td>
                                        <td class="px-3 py-2.5"><span :class="CHIP[TONO_STATO[r.status] ?? 'neutra']">{{ WORK_STATUS_LABELS[r.status] ?? r.status }}</span></td>
                                        <td class="px-3 py-2.5 text-gray-700">{{ r.team?.name ?? r.assignee?.name ?? '—' }}</td>
                                        <td class="px-3 py-2.5" :class="ritardo(r) ? 'font-semibold text-red-800' : 'text-gray-700'"><div class="whitespace-nowrap">{{ periodo(r) }}</div><div v-if="ritardo(r)" class="text-xs">in ritardo</div></td>
                                        <td class="px-3 py-2.5 text-right text-gray-700">{{ r.assets_count ?? 0 }}</td>
                                    </tr>
                                    <tr v-if="! righe.length && ! caricamento"><td :colspan="canManage ? 8 : 7" class="px-4 py-8 text-center text-sm text-gray-500" data-test="lavori-vuoto">{{ filtriAttivi ? 'Nessun ordine con questi filtri.' : 'Nessun ordine aperto.' }}</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="flex flex-wrap items-center justify-between gap-2 border-t border-gray-200 px-4 py-2.5 text-[13px] text-gray-600">
                            <span>Righe {{ meta.from }}–{{ meta.to }} di {{ meta.total }} · pagina {{ meta.current_page }} di {{ meta.last_page }}</span>
                            <div class="flex gap-2">
                                <button type="button" :class="BOTTONE_PICCOLO" :disabled="meta.current_page <= 1 || caricamento" @click="filtri.page = meta.current_page - 1">← Precedente</button>
                                <button type="button" :class="BOTTONE_PICCOLO" :disabled="meta.current_page >= meta.last_page || caricamento" @click="filtri.page = meta.current_page + 1">Successiva →</button>
                            </div>
                        </div>
                        <p class="border-t border-gray-100 px-4 py-2.5 text-[13px] text-gray-500">Preventivi, SAL e rendiconti stanno in "Altro" finché non trovano posto in Documenti; qualità e ispezioni nella scheda Ispezioni.</p>
                    </section>

                    <aside :class="[CARTA, anteprima.id ? 'order-first lg:order-none' : 'hidden lg:block']" class="min-w-0 p-4 lg:sticky lg:top-4" data-test="lavori-anteprima">
                        <p v-if="! anteprima.id" class="text-sm text-gray-500">Scegli una riga per vedere l'ordine in breve: squadra, periodo, importo previsto e cronologia.</p>
                        <template v-else>
                            <div class="mb-2 flex items-center justify-between lg:hidden"><span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Anteprima</span><button type="button" class="min-h-11 px-2 text-sm text-gray-600" @click="chiudiAnteprima">✕ Chiudi</button></div>
                            <AvvisoErrore :messaggio="anteprima.errore" @riprova="() => { const id = anteprima.id; anteprima.id = null; apriAnteprima(id); }" />
                            <p v-if="anteprima.caricamento" class="text-sm text-gray-500">Carico l'ordine…</p>
                            <template v-if="anteprima.ordine">
                                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Ordine di lavoro {{ anteprima.ordine.code }}</div>
                                <div class="text-lg font-bold text-gray-900">{{ anteprima.ordine.title }}</div>
                                <div class="mt-1 flex flex-wrap items-center gap-2 text-[13px]">
                                    <span :class="CHIP[TONO_STATO[anteprima.ordine.status] ?? 'neutra']">{{ WORK_STATUS_LABELS[anteprima.ordine.status] }}</span>
                                    <span v-if="ritardo(anteprima.ordine)" :class="CHIP.errore">in ritardo di {{ giorniRitardo(anteprima.ordine) }} {{ plurale(giorniRitardo(anteprima.ordine), 'giorno', 'giorni') }}</span>
                                    <span class="text-gray-500">{{ anteprima.ordine.assets?.length ?? 0 }} {{ plurale(anteprima.ordine.assets?.length ?? 0, 'elemento', 'elementi') }}</span>
                                </div>
                                <dl class="mt-3 grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
                                    <div><dt class="text-xs text-gray-500">Squadra</dt><dd class="font-semibold text-gray-900">{{ anteprima.ordine.team?.name ?? '—' }}<span v-if="anteprima.ordine.assignee" class="font-normal text-gray-500"> · {{ anteprima.ordine.assignee.name }}</span></dd></div>
                                    <div><dt class="text-xs text-gray-500">Periodo</dt><dd class="font-semibold text-gray-900">{{ periodo(anteprima.ordine) }}</dd></div>
                                    <div><dt class="text-xs text-gray-500">Dove</dt><dd class="font-semibold text-gray-900">{{ [anteprima.ordine.client?.name, anteprima.ordine.area?.name].filter(Boolean).join(' · ') || '—' }}</dd></div>
                                    <div><dt class="text-xs text-gray-500">Lavorazione</dt><dd class="font-semibold text-gray-900">{{ anteprima.ordine.work_type?.name ?? '—' }}</dd></div>
                                    <div v-if="anteprima.ordine.price_list"><dt class="text-xs text-gray-500">Listino</dt><dd class="font-semibold text-gray-900">{{ anteprima.ordine.price_list.name }}</dd></div>
                                    <div v-if="anteprima.ordine.previsto?.valued && ! anteprima.ordine.previsto.valued.ambiguous && anteprima.ordine.previsto.valued.amount != null"><dt class="text-xs text-gray-500">Importo previsto</dt><dd class="font-semibold text-gray-900">{{ fmtEuro(anteprima.ordine.previsto.valued.amount) }}</dd></div>
                                </dl>
                                <div class="mt-4 flex flex-wrap gap-2">
                                    <Link :href="`/lavori/${anteprima.ordine.id}`" :class="BOTTONE" data-test="anteprima-apri-ordine">Apri l'ordine</Link>
                                    <button v-if="canManage && (anteprima.ordine.allowed_transitions ?? []).includes('completed')" type="button" :class="BOTTONE_SECONDARIO" :disabled="anteprima.busy" @click="chiudiDallAnteprima">Chiudi con consuntivo</button>
                                    <Link v-if="canManage && ! ['completed', 'cancelled'].includes(anteprima.ordine.status)" :href="`/lavori/${anteprima.ordine.id}?modifica=1`" :class="BOTTONE_SECONDARIO">Riprogramma</Link>
                                </div>
                                <div v-if="anteprima.cronologia" class="mt-4">
                                    <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Cronologia</h2>
                                    <ul v-if="anteprima.cronologia.eventi.length" class="mt-1 divide-y divide-gray-100">
                                        <li v-for="(e, i) in anteprima.cronologia.eventi.slice(0, 6)" :key="i" class="flex gap-3 py-2">
                                            <span class="w-14 shrink-0 pt-0.5 text-xs text-gray-500">{{ formatData(e.data, false) }}</span>
                                            <div class="min-w-0"><div class="text-sm font-semibold text-gray-900">{{ e.titolo }}</div><div class="text-[13px] text-gray-500">{{ e.dettaglio }}</div></div>
                                        </li>
                                    </ul>
                                    <p v-else class="mt-1 text-[13px] text-gray-500">Nessun evento registrato.</p>
                                </div>
                            </template>
                        </template>
                    </aside>
                </div>
            </template>
        </div>

        <NuovoOrdine
            v-if="nuovo.aperto"
            :elementi="nuovo.elementi"
            :teams="teams" :personnel="personnel" :work-types="workTypes" :clients="clients" :areas="areas" :price-lists="priceLists"
            @close="nuovo.aperto = false"
            @created="ordineCreato"
        />
    </AppLayout>
</template>
