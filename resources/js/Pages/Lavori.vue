<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import WorkAgenda from '@/Components/WorkAgenda.vue';
import WorkReport from '@/Components/WorkReport.vue';
import QualityBoard from '@/Components/QualityBoard.vue';
import QuotesPanel from '@/Components/QuotesPanel.vue';
import ScegliCommittente from '@/Components/ScegliCommittente.vue';
import ScegliVoce from '@/Components/ScegliVoce.vue';
import AvvisoErrore from '@/Components/AvvisoErrore.vue';
import VisteSalvate from '@/Components/VisteSalvate.vue';
import { usaCaricamento } from '@/caricamento';
import { WORK_STATUS_LABELS } from '@/workStatus';

const page = usePage();
const canManage = computed(() => (page.props.auth?.user?.permissions ?? []).includes('works.manage'));

const STATUS_LABELS = WORK_STATUS_LABELS;
const STATUS_COLORS = {
    draft: 'bg-gray-100 text-gray-700',
    planned: 'bg-blue-100 text-blue-800',
    assigned: 'bg-indigo-100 text-indigo-800',
    in_progress: 'bg-amber-100 text-amber-800',
    suspended: 'bg-orange-100 text-orange-800',
    completed: 'bg-green-100 text-green-800',
    cancelled: 'bg-gray-200 text-gray-500',
};
const PRIORITY_LABELS = { low: 'Bassa', normal: 'Normale', high: 'Alta', urgent: 'Urgente' };

const rows = ref([]);

// L'elenco vuoto per un errore di rete e l'elenco vuoto perche' non ci sono
// ordini si somigliano troppo: qui il primo caso si dichiara
const { avviso, riprovaInCorso, carica, riprova } = usaCaricamento();

// --- Azioni su piu' ordini insieme -----------------------------------------
const selezionati = ref([]);
const chiusuraInCorso = ref(false);
const esitoMultiplo = ref('');
const saltatiMultiplo = ref([]);

const tuttiSelezionati = computed(() =>
    rows.value.length > 0 && selezionati.value.length === rows.value.length);

function commutaTutti() {
    selezionati.value = tuttiSelezionati.value ? [] : rows.value.map((r) => r.id);
}

watch(selezionati, () => { anteprimaChiusura.value = null; });

/**
 * Chiude gli ordini selezionati. Quelli che non si possono chiudere non
 * vengono forzati: si riportano con il motivo, perche' credere di aver
 * concluso un lavoro rimasto a meta' e' peggio che vedere un errore.
 */
// Prima di eseguire si conta: "N verranno chiusi, M esclusi perche'...".
// Un'azione su cinquanta righe non deve essere una sorpresa a cose fatte.
const anteprimaChiusura = ref(null);

async function preparaChiusura() {
    chiusuraInCorso.value = true;
    esitoMultiplo.value = '';
    saltatiMultiplo.value = [];
    try {
        const { data } = await axios.post('/api/v1/azioni/chiudi-lavori', {
            ids: selezionati.value, prova: 1,
        });
        anteprimaChiusura.value = data.data;
    } catch (err) {
        esitoMultiplo.value = err.response?.data?.message ?? 'Errore nella verifica.';
    } finally {
        chiusuraInCorso.value = false;
    }
}

async function chiudiSelezionati() {
    chiusuraInCorso.value = true;
    esitoMultiplo.value = '';
    saltatiMultiplo.value = [];
    try {
        const { data } = await axios.post('/api/v1/azioni/chiudi-lavori', { ids: selezionati.value });
        const fatti = data.data.completati.length;
        saltatiMultiplo.value = data.data.saltati;
        esitoMultiplo.value = fatti === 1 ? '1 ordine completato.' : `${fatti} ordini completati.`;
        selezionati.value = [];
        anteprimaChiusura.value = null;
        await load();
    } catch (err) {
        esitoMultiplo.value = err.response?.data?.message ?? 'Errore nella chiusura.';
    } finally {
        chiusuraInCorso.value = false;
    }
}
const meta = reactive({ total: 0, current_page: 1, last_page: 1 });
const filters = reactive({ status: '', q: '', page: 1 });

// --- Viste salvate ----------------------------------------------------------
const filtriCorrenti = computed(() => ({ status: filters.status, q: filters.q }));

function applicaVista(filtri) {
    filters.status = filtri.status ?? '';
    filters.q = filtri.q ?? '';
    filters.page = 1;
    carica(load);
}
const loading = ref(false);
// La vista iniziale può arrivare dall'URL (es. il cruscotto Oggi linka
// direttamente la Qualità): valori sconosciuti ricadono sull'elenco
const VIEWS = ['elenco', 'agenda', 'rendiconto', 'qualita', 'preventivi'];
const requested = new URLSearchParams(window.location.search).get('vista');
const view = ref(VIEWS.includes(requested) ? requested : 'elenco');
// La predefinita delle viste salvate si applica solo alla prima apertura
// dell'elenco: tornando dall'Agenda il componente si rimonta e non deve
// scartare i filtri che l'utente ha impostato a mano nel frattempo
const visteAuto = ref(true);
watch(view, (nuova, vecchia) => {
    if (vecchia === 'elenco') visteAuto.value = false;
});
const agendaRef = ref(null);
const reportRef = ref(null);
const qualityRef = ref(null);

const NC_STATUS_LABELS = { open: 'Aperta', action: 'In azione', verified: 'Verificata', closed: 'Chiusa' };
const NC_SEVERITY_LABELS = { minor: 'Lieve', major: 'Grave', critical: 'Critica' };

// Verbale di controllo qualità (dal dettaglio ordine)
const checkModal = reactive({
    open: false, busy: false, error: '', outcome: 'passed', notes: '',
    nc: { severity: 'minor', description: '', due_on: '', responsible_id: '', create_corrective_order: true },
});

function openCheckModal() {
    Object.assign(checkModal, { open: true, busy: false, error: '', outcome: 'passed', notes: '' });
    checkModal.nc = { severity: 'minor', description: '', due_on: '', responsible_id: '', create_corrective_order: true };
}

async function submitCheck() {
    if (checkModal.outcome === 'failed' && ! checkModal.nc.description.trim()) {
        checkModal.error = 'Descrivi la non conformità riscontrata.';
        return;
    }
    checkModal.busy = true;
    checkModal.error = '';
    try {
        const { data } = await axios.post(`/api/v1/work-orders/${detail.value.id}/checks`, {
            outcome: checkModal.outcome,
            notes: checkModal.notes.trim() || null,
            ...(checkModal.outcome === 'failed' ? { non_conformity: {
                severity: checkModal.nc.severity,
                description: checkModal.nc.description.trim(),
                due_on: checkModal.nc.due_on || null,
                responsible_id: checkModal.nc.responsible_id || null,
                create_corrective_order: checkModal.nc.create_corrective_order,
            } } : {}),
        });
        detail.value = data.data;
        checkModal.open = false;
        // L'eventuale ordine correttivo compare in elenco, agenda e qualità
        await load();
        await agendaRef.value?.reload();
        await qualityRef.value?.reload();
    } catch (err) {
        checkModal.error = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
            ?? err.response?.data?.message ?? 'Registrazione non riuscita';
    } finally {
        checkModal.busy = false;
    }
}

const teams = ref([]);
const personnel = ref([]);
const workTypes = ref([]);
const clients = ref([]);
const areas = ref([]);
const priceLists = ref([]);

const creator = reactive({ open: false, busy: false, error: '', form: {} });
const detail = ref(null);
const detailBusy = ref(false);
const detailError = ref('');
const assetSearch = reactive({ q: '', results: [], adding: false });

function resetForm() {
    creator.form = {
        title: '', description: '', client_id: '', area_id: '', work_type_id: '',
        priority: 'normal', planned_start: '', planned_end: '', team_id: '', assigned_to: '',
        price_list_id: '',
    };
}
resetForm();

async function load() {
    selezionati.value = [];
    loading.value = true;
    try {
        const { data } = await axios.get('/api/v1/work-orders', {
            params: {
                page: filters.page,
                status: filters.status || undefined,
                q: filters.q || undefined,
            },
        });
        rows.value = data.data;
        Object.assign(meta, { total: data.total, current_page: data.current_page, last_page: data.last_page });
    } finally {
        loading.value = false;
    }
}

// L'anagrafica clienti è paginata (massimo 100 per pagina): si scorrono
// tutte le pagine, o i clienti oltre il centesimo non sarebbero selezionabili
async function fetchAllClients() {
    const all = [];
    for (let p = 1; p <= 20; p++) {
        const { data } = await axios.get('/api/v1/clients', { params: { per_page: 100, page: p } });
        all.push(...(data.data ?? []));
        if (! data.next_page_url) break;
    }
    return all;
}

async function loadLookups() {
    const requests = [
        axios.get('/api/v1/teams'),
        axios.get('/api/v1/work-types'),
        axios.get('/api/v1/price-lists'),
    ];
    if (canManage.value) {
        requests.push(axios.get('/api/v1/personnel'));
        requests.push(fetchAllClients());
        requests.push(axios.get('/api/v1/areas', { params: { per_page: 100 } }));
    }
    const [t, wt, pl, p, c, a] = await Promise.all(requests);
    teams.value = t.data.data;
    workTypes.value = wt.data.data;
    priceLists.value = pl.data.data;
    if (p) personnel.value = p.data.data;
    if (c) clients.value = c;
    if (a) areas.value = a.data.data;
}

async function createOrder() {
    creator.busy = true;
    creator.error = '';
    try {
        const payload = Object.fromEntries(
            Object.entries(creator.form).filter(([, v]) => v !== '' && v !== null),
        );
        const { data } = await axios.post('/api/v1/work-orders', payload);
        creator.open = false;
        resetForm();
        await load();
        await agendaRef.value?.reload();
        await openDetail(data.data.id);
    } catch (err) {
        creator.error = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
            ?? err.response?.data?.message ?? 'Errore nella creazione';
    } finally {
        creator.busy = false;
    }
}

async function openDetail(id) {
    try {
        const { data } = await axios.get(`/api/v1/work-orders/${id}`);
        detail.value = data.data;
        detailError.value = '';
        assetSearch.q = '';
        assetSearch.results = [];
    } catch {
        detailError.value = 'Ordine non trovato o non accessibile.';
    }
}

// L'annullamento riguarda l'ordine intero (anche se dura piu' giorni) e non
// si torna indietro: si chiede conferma dicendo esattamente cosa comporta
function confirmCancel() {
    const fmt = (d) => (d ? String(d).slice(0, 10).split('-').reverse().join('/') : null);
    const start = fmt(detail.value.planned_start);
    const end = fmt(detail.value.planned_end);
    const periodo = start && end && start !== end
        ? ` previsto dal ${start} al ${end}`
        : (start ? ` previsto per il ${start}` : '');

    return window.confirm(
        `Annullare l'ordine ${detail.value.code} "${detail.value.title}"${periodo}?\n\n`
        + "L'annullamento riguarda l'intero ordine, non il singolo giorno, e non si può tornare indietro.",
    );
}

async function doTransition(status) {
    if (status === 'cancelled' && ! confirmCancel()) return;

    detailBusy.value = true;
    detailError.value = '';
    try {
        const { data } = await axios.post(`/api/v1/work-orders/${detail.value.id}/transition`, {
            status,
            version: detail.value.version,
        });
        detail.value = data.data;
        await load();
        // Agenda e rendiconto, se aperti, devono riflettere subito il nuovo stato
        await agendaRef.value?.reload();
        await reportRef.value?.reload();
    } catch (err) {
        detailError.value = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
            ?? err.response?.data?.message ?? 'Errore nel cambio di stato';
    } finally {
        detailBusy.value = false;
    }
}

let searchDebounce = null;
function searchAssets() {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(async () => {
        if (assetSearch.q.trim().length < 2) {
            assetSearch.results = [];
            return;
        }
        const { data } = await axios.get('/api/v1/assets', { params: { q: assetSearch.q, per_page: 8 } });
        assetSearch.results = data.data;
    }, 250);
}

async function attachAsset(asset) {
    assetSearch.adding = true;
    detailError.value = '';
    try {
        const { data } = await axios.post(`/api/v1/work-orders/${detail.value.id}/assets`, {
            asset_id: asset.id,
        });
        detail.value = data.data;
        assetSearch.q = '';
        assetSearch.results = [];
        await load();
    } catch (err) {
        detailError.value = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
            ?? err.response?.data?.message ?? 'Errore nell\'aggiunta';
    } finally {
        assetSearch.adding = false;
    }
}

async function detachAsset(rowId) {
    detailError.value = '';
    try {
        const { data } = await axios.delete(`/api/v1/work-orders/${detail.value.id}/assets/${rowId}`);
        detail.value = data.data;
        await load();
    } catch (err) {
        detailError.value = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
            ?? err.response?.data?.message ?? 'Errore nella rimozione';
    }
}

// --- Quantità previste delle righe elemento ---------------------------------
const righeQuantita = reactive({});

function preparaRigheQuantita() {
    Object.keys(righeQuantita).forEach((k) => delete righeQuantita[k]);
    for (const row of detail.value?.assets ?? []) {
        righeQuantita[row.id] = {
            quantity: row.planned_quantity != null ? Number(row.planned_quantity) : null,
            unit: row.unit ?? '',
            dirty: false,
            busy: false,
        };
    }
}
// Ogni azione sul dettaglio torna dal server con le righe fresche: le
// caselle ripartono da quelle, perché lo stato vero è quello del server
watch(() => detail.value?.assets, preparaRigheQuantita);

async function salvaQuantitaRiga(row) {
    const mod = righeQuantita[row.id];
    if (! mod) return;
    mod.busy = true;
    detailError.value = '';
    try {
        const { data } = await axios.patch(`/api/v1/work-orders/${detail.value.id}/assets/${row.id}`, {
            planned_quantity: mod.quantity === '' || mod.quantity == null ? null : mod.quantity,
            unit: mod.unit.trim() || null,
        });
        detail.value = data.data;
    } catch (err) {
        detailError.value = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
            ?? err.response?.data?.message ?? 'Errore nel salvataggio della quantità';
        mod.busy = false;
    }
}

// Dopo un ridisegno la quantità agganciata resta quella vecchia: questo
// pulsante la riallinea alla geometria di oggi (il calcolo sta sul server)
async function riprendiDallaMappa(row) {
    detailError.value = '';
    try {
        const { data } = await axios.patch(`/api/v1/work-orders/${detail.value.id}/assets/${row.id}`, { ricalcola: true });
        detail.value = data.data;
    } catch (err) {
        detailError.value = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
            ?? err.response?.data?.message ?? 'Errore nel ricalcolo';
    }
}

// "Riprendi dalla mappa" compare solo quando servirebbe davvero: la geometria
// dà una misura e la quantità agganciata non le corrisponde già
function mostraRiprendi(row) {
    if (row.quantita_geometrica == null) return false;
    if (row.planned_quantity == null) return true;

    return Number(row.planned_quantity) !== Number(row.quantita_geometrica)
        || (row.unit ?? '') !== (row.unita_geometrica ?? '');
}

const ordineChiuso = computed(() => ['completed', 'cancelled'].includes(detail.value?.status));

// Totale previsto per unità di misura: la stessa somma che valorizza l'importo
const totaliPrevisti = computed(() =>
    Object.entries(detail.value?.previsto?.quantities ?? {})
        .map(([unit, qty]) => `${fmtQty(qty)} ${unit || 'senza unità'}`));

const fmt = (d) => (d ? new Date(d).toLocaleDateString('it-IT') : '—');
// Oggi in data locale (mai toISOString: a cavallo della mezzanotte sbaglia giorno)
const todayLocalYmd = (() => {
    const d = new Date();
    const p = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
})();
const fmtEuro = (v) => (v == null ? '—' : Number(v).toLocaleString('it-IT', { style: 'currency', currency: 'EUR' }));
const fmtDateTime = (iso) => (iso ? new Date(iso).toLocaleString('it-IT', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '—');
const fmtQty = (v) => Number(v).toLocaleString('it-IT');

// Pubblicazione dell'ordine come atto sul portale del committente
async function setPubblico(valore) {
    detailBusy.value = true;
    detailError.value = '';
    try {
        const { data } = await axios.patch(`/api/v1/work-orders/${detail.value.id}`, {
            is_public: valore,
            version: detail.value.version,
        });
        detail.value = data.data;
    } catch (err) {
        const message = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
            ?? err.response?.data?.message ?? 'Errore nel cambio di visibilità';
        if (err.response?.status === 409) await openDetail(detail.value.id);
        detailError.value = message;
    } finally {
        detailBusy.value = false;
    }
}

async function setPriceList(priceListId) {
    detailBusy.value = true;
    detailError.value = '';
    try {
        const { data } = await axios.patch(`/api/v1/work-orders/${detail.value.id}`, {
            price_list_id: priceListId || null,
            version: detail.value.version,
        });
        detail.value = data.data;
    } catch (err) {
        const message = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
            ?? err.response?.data?.message ?? 'Errore nel cambio di listino';
        // Su conflitto di versione si ricarica l'ordine (il prossimo tentativo
        // vale), ma il messaggio va rimesso DOPO: openDetail lo azzererebbe
        if (err.response?.status === 409) await openDetail(detail.value.id);
        detailError.value = message;
    } finally {
        detailBusy.value = false;
    }
}

// Senza attesa partiva una richiesta per ogni tasto premuto
let attesaRicerca = null;
function cercaConAttesa() {
    clearTimeout(attesaRicerca);
    attesaRicerca = setTimeout(() => {
        filters.page = 1;
        carica(load);
    }, 300);
}

onMounted(async () => {
    await carica(() => Promise.all([load(), loadLookups()]));
    // Arrivando dalla mappa ("Apri l'ordine") il dettaglio si apre da solo
    const ordine = new URLSearchParams(window.location.search).get('ordine');
    if (ordine) await openDetail(ordine);
});
</script>

<template>
    <Head title="Lavori" />

    <AppLayout>
        <div class="p-6">
            <AvvisoErrore :messaggio="avviso" :in-corso="riprovaInCorso" @riprova="riprova" />

            <div class="mb-4 flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-semibold">Ordini di lavoro</h1>
                    <!-- Il conteggio segue i filtri dell'elenco: in agenda sarebbe fuorviante -->
                    <p v-if="view === 'elenco'" class="text-sm text-gray-500">{{ meta.total }} {{ meta.total === 1 ? 'ordine' : 'ordini' }} · flusso: bozza, pianificato, assegnato, in corso, completato</p>
                    <p v-else-if="view === 'agenda'" class="text-sm text-gray-500">Programmazione settimanale per squadra</p>
                    <p v-else-if="view === 'rendiconto'" class="text-sm text-gray-500">Lavori completati per cliente e periodo, con importi da listino</p>
                    <p v-else-if="view === 'preventivi'" class="text-sm text-gray-500">Offerte ai clienti: bozza, invio, esito e trasformazione in ordine di lavoro</p>
                    <p v-else class="text-sm text-gray-500">Controlli qualità e non conformità con flusso correttivo</p>
                </div>
                <button
                    v-if="canManage"
                    class="rounded-lg bg-green-700 px-4 py-2 text-sm font-medium text-white hover:bg-green-800"
                    data-test="new-wo"
                    @click="creator.open = true"
                >Nuovo ordine</button>
            </div>

            <!-- Selettore vista: elenco tabellare o agenda settimanale -->
            <div class="mb-3 flex max-w-full overflow-x-auto rounded-lg border border-gray-300 text-sm">
                <button
                    class="whitespace-nowrap px-4 py-1.5 font-medium"
                    :class="view === 'elenco' ? 'bg-green-700 text-white' : 'bg-white text-gray-600 hover:bg-gray-50'"
                    data-test="view-elenco"
                    @click="view = 'elenco'"
                >Elenco</button>
                <button
                    class="whitespace-nowrap border-l border-gray-300 px-4 py-1.5 font-medium"
                    :class="view === 'agenda' ? 'bg-green-700 text-white' : 'bg-white text-gray-600 hover:bg-gray-50'"
                    data-test="view-agenda"
                    @click="view = 'agenda'"
                >Agenda</button>
                <button
                    class="whitespace-nowrap border-l border-gray-300 px-4 py-1.5 font-medium"
                    :class="view === 'rendiconto' ? 'bg-green-700 text-white' : 'bg-white text-gray-600 hover:bg-gray-50'"
                    data-test="view-rendiconto"
                    @click="view = 'rendiconto'"
                >Rendiconto</button>
                <button
                    class="whitespace-nowrap border-l border-gray-300 px-4 py-1.5 font-medium"
                    :class="view === 'qualita' ? 'bg-green-700 text-white' : 'bg-white text-gray-600 hover:bg-gray-50'"
                    data-test="view-qualita"
                    @click="view = 'qualita'"
                >Qualità</button>
                <button
                    class="whitespace-nowrap border-l border-gray-300 px-4 py-1.5 font-medium"
                    :class="view === 'preventivi' ? 'bg-green-700 text-white' : 'bg-white text-gray-600 hover:bg-gray-50'"
                    data-test="view-preventivi"
                    @click="view = 'preventivi'"
                >Preventivi</button>
            </div>

            <WorkAgenda
                v-if="view === 'agenda'"
                ref="agendaRef"
                :teams="teams"
                :personnel="personnel"
                :can-manage="canManage"
                @open="openDetail"
            />

            <WorkReport
                v-if="view === 'rendiconto'"
                ref="reportRef"
                :clients="clients"
                @open="openDetail"
            />

            <QualityBoard
                v-if="view === 'qualita'"
                ref="qualityRef"
                :personnel="personnel"
                :can-manage="canManage"
                @open-order="openDetail"
            />

            <QuotesPanel
                v-if="view === 'preventivi'"
                :clients="clients"
                :work-types="workTypes"
                :can-manage="canManage"
                @created-order="load"
            />

            <div
                v-if="view === 'elenco' && selezionati.length"
                data-test="lavori-barra-selezione"
                class="mb-3 flex flex-wrap items-center gap-3 rounded-xl border border-green-200 bg-green-50 px-4 py-2.5 text-sm"
            >
                <span class="font-medium">{{ selezionati.length }} selezionati</span>
                <template v-if="! anteprimaChiusura">
                    <button
                        class="rounded-lg bg-green-700 px-3 py-1.5 text-sm font-medium text-white hover:bg-green-800 disabled:opacity-50"
                        :disabled="chiusuraInCorso"
                        data-test="lavori-chiudi-selezionati"
                        @click="preparaChiusura"
                    >{{ chiusuraInCorso ? 'Verifica…' : 'Segna come completati' }}</button>
                </template>
                <template v-else>
                    <span data-test="lavori-anteprima" class="text-gray-800">
                        {{ anteprimaChiusura.completati.length }} {{ anteprimaChiusura.completati.length === 1 ? 'verrà completato' : 'verranno completati' }}<template v-if="anteprimaChiusura.saltati.length">, {{ anteprimaChiusura.saltati.length }} esclus{{ anteprimaChiusura.saltati.length === 1 ? 'o' : 'i' }}</template>.
                    </span>
                    <button
                        class="rounded-lg bg-green-700 px-3 py-1.5 text-sm font-medium text-white hover:bg-green-800 disabled:opacity-50"
                        :disabled="chiusuraInCorso || ! anteprimaChiusura.completati.length"
                        data-test="lavori-conferma-chiusura"
                        @click="chiudiSelezionati"
                    >Conferma</button>
                    <button class="text-sm text-gray-600 hover:underline" @click="anteprimaChiusura = null">Annulla</button>
                </template>
                <button class="text-sm text-gray-600 hover:underline" @click="selezionati = []; anteprimaChiusura = null">Annulla selezione</button>
                <span v-if="esitoMultiplo" class="text-gray-700">{{ esitoMultiplo }}</span>
            </div>

            <ul
                v-if="anteprimaChiusura?.saltati.length"
                data-test="lavori-anteprima-esclusi"
                class="mb-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-2.5 text-sm text-amber-900"
            >
                <li class="mb-1 font-medium">Questi verranno esclusi:</li>
                <li v-for="s in anteprimaChiusura.saltati" :key="s.id" class="text-xs">{{ s.codice || s.id }} - {{ s.motivo }}</li>
            </ul>

            <ul v-if="saltatiMultiplo.length" data-test="lavori-saltati" class="mb-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-2.5 text-sm text-amber-900">
                <li class="mb-1 font-medium">Questi non sono stati chiusi:</li>
                <li v-for="s in saltatiMultiplo" :key="s.id" class="text-xs">{{ s.codice || s.id }} - {{ s.motivo }}</li>
            </ul>

            <div v-if="view === 'elenco'" class="mb-3 flex flex-wrap gap-2">
                <select v-model="filters.status" class="rounded-lg border border-gray-300 px-2.5 py-2 text-sm" @change="filters.page = 1; carica(load)">
                    <option value="">Tutti gli stati</option>
                    <option v-for="(label, value) in STATUS_LABELS" :key="value" :value="value">{{ label }}</option>
                </select>
                <input
                    v-model="filters.q"
                    placeholder="Cerca per codice, titolo o descrizione…"
                    class="w-64 rounded-lg border border-gray-300 px-2.5 py-2 text-sm"
                    @input="cercaConAttesa"
                >

                <VisteSalvate pagina="lavori" :filtri="filtriCorrenti" :auto="visteAuto" @applica="applicaVista" />
            </div>

            <div v-if="view === 'elenco'" class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wide text-gray-400">
                            <th class="px-2 py-2.5 font-medium">
                                <input
                                    type="checkbox"
                                    class="rounded border-gray-300"
                                    aria-label="Seleziona tutti"
                                    :checked="tuttiSelezionati"
                                    @change="commutaTutti"
                                >
                            </th>
                            <th class="px-4 py-2.5 font-medium">Codice</th>
                            <th class="px-4 py-2.5 font-medium">Titolo</th>
                            <th class="px-4 py-2.5 font-medium">Stato</th>
                            <th class="px-4 py-2.5 font-medium">Priorità</th>
                            <th class="px-4 py-2.5 font-medium">Squadra / responsabile</th>
                            <th class="px-4 py-2.5 font-medium">Periodo</th>
                            <th class="px-4 py-2.5 text-right font-medium">Elementi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        <tr
                            v-for="row in rows"
                            :key="row.id"
                            class="cursor-pointer hover:bg-green-50/40"
                            @click="openDetail(row.id)"
                        >
                            <td class="px-2 py-2" @click.stop>
                                <input
                                    v-model="selezionati"
                                    type="checkbox"
                                    :value="row.id"
                                    class="rounded border-gray-300"
                                    :aria-label="`Seleziona ${row.code}`"
                                    data-test="lavoro-casella"
                                >
                            </td>
                            <td class="px-4 py-2 font-medium">{{ row.code }}</td>
                            <td class="max-w-xs truncate px-4 py-2">{{ row.title }}</td>
                            <td class="px-4 py-2">
                                <span class="rounded-full px-2.5 py-0.5 text-xs font-medium" :class="STATUS_COLORS[row.status]">
                                    {{ STATUS_LABELS[row.status] ?? row.status }}
                                </span>
                            </td>
                            <td class="px-4 py-2 text-gray-600">{{ PRIORITY_LABELS[row.priority] }}</td>
                            <td class="px-4 py-2 text-gray-600">{{ row.team?.name || row.assignee?.name || '—' }}</td>
                            <td class="px-4 py-2 text-gray-500">{{ fmt(row.planned_start) }} – {{ fmt(row.planned_end) }}</td>
                            <td class="px-4 py-2 text-right">{{ row.assets_count }}</td>
                        </tr>
                        <tr v-if="! rows.length && ! loading">
                            <td colspan="8" class="px-4 py-8 text-center text-gray-400">Nessun ordine di lavoro.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="view === 'elenco' && meta.last_page > 1" class="mt-3 flex items-center justify-between text-sm">
                <button class="rounded-lg border border-gray-300 px-3 py-1.5 disabled:opacity-40" :disabled="meta.current_page <= 1" @click="filters.page -= 1; carica(load)">← Precedente</button>
                <span class="text-gray-500">Pagina {{ meta.current_page }} di {{ meta.last_page }}</span>
                <button class="rounded-lg border border-gray-300 px-3 py-1.5 disabled:opacity-40" :disabled="meta.current_page >= meta.last_page" @click="filters.page += 1; carica(load)">Successiva →</button>
            </div>

            <!-- Creazione -->
            <Teleport to="body">
                <div v-if="creator.open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4" @click.self="creator.open = false">
                    <div class="w-full max-w-2xl rounded-2xl bg-white p-6 shadow-2xl">
                        <div class="flex items-start justify-between">
                            <h2 class="font-semibold">Nuovo ordine di lavoro</h2>
                            <button class="text-gray-400 hover:text-gray-600" @click="creator.open = false">✕</button>
                        </div>

                        <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2">
                            <label class="col-span-full block text-xs">
                                <span class="text-gray-500">Titolo *</span>
                                <input v-model="creator.form.title" data-test="wo-title" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm" placeholder="es. Potatura filare via Roma">
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Lavorazione</span>
                                <select v-model="creator.form.work_type_id" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-2 text-sm">
                                    <option value="">—</option>
                                    <option v-for="w in workTypes" :key="w.id" :value="w.id">{{ w.name }} ({{ w.unit }})</option>
                                </select>
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Priorità</span>
                                <select v-model="creator.form.priority" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-2 text-sm">
                                    <option v-for="(label, value) in PRIORITY_LABELS" :key="value" :value="value">{{ label }}</option>
                                </select>
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Cliente</span>
                                <ScegliCommittente v-model="creator.form.client_id" class="mt-1 w-full" campo-classe="px-2 py-2 text-sm" :committenti="clients" tutti="—" />
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Area</span>
                                <ScegliVoce v-model="creator.form.area_id" class="mt-1 w-full" campo-classe="px-2 py-2 text-sm" :voci="areas" :campi-ricerca="['name', 'code']" tutti="—" vuoto="Nessuna area trovata." />
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Inizio previsto</span>
                                <input v-model="creator.form.planned_start" type="date" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm">
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Fine prevista</span>
                                <input v-model="creator.form.planned_end" type="date" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm">
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Squadra</span>
                                <select v-model="creator.form.team_id" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-2 text-sm">
                                    <option value="">—</option>
                                    <option v-for="t in teams" :key="t.id" :value="t.id">{{ t.name }}</option>
                                </select>
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Responsabile</span>
                                <select v-model="creator.form.assigned_to" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-2 text-sm">
                                    <option value="">—</option>
                                    <option v-for="p in personnel" :key="p.id" :value="p.id">{{ p.name }}</option>
                                </select>
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Listino prezzi</span>
                                <select v-model="creator.form.price_list_id" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-2 text-sm">
                                    <option value="">—</option>
                                    <option v-for="l in priceLists" :key="l.id" :value="l.id">{{ l.code }} — {{ l.name }}</option>
                                </select>
                            </label>
                            <label class="col-span-full block text-xs">
                                <span class="text-gray-500">Descrizione</span>
                                <textarea v-model="creator.form.description" rows="2" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm" />
                            </label>
                        </div>

                        <p v-if="creator.error" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ creator.error }}</p>

                        <button
                            class="mt-4 w-full rounded-lg bg-green-700 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50"
                            :disabled="creator.busy || ! creator.form.title.trim()"
                            data-test="wo-save"
                            @click="createOrder"
                        >{{ creator.busy ? 'Creazione…' : 'Crea ordine (in bozza)' }}</button>
                    </div>
                </div>
            </Teleport>

            <!-- Verbale di controllo qualità -->
            <Teleport to="body">
                <!-- Con la richiesta in volo la modale non si chiude: l'esito
                     (anche un errore) deve restare visibile a chi la usa -->
                <div v-if="checkModal.open" class="fixed inset-0 z-[60] flex items-center justify-center bg-black/30 p-4" @click.self="! checkModal.busy && (checkModal.open = false)">
                    <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl" data-test="check-modal">
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="text-xs uppercase tracking-wide text-gray-400">{{ detail?.code }}</div>
                                <h2 class="font-semibold">Verbale di controllo qualità</h2>
                            </div>
                            <button class="text-gray-400 hover:text-gray-600 disabled:opacity-40" :disabled="checkModal.busy" @click="checkModal.open = false">✕</button>
                        </div>

                        <div class="mt-4 grid grid-cols-2 gap-2">
                            <button
                                class="rounded-lg border px-3 py-2.5 text-sm font-medium"
                                :class="checkModal.outcome === 'passed' ? 'border-green-700 bg-green-700 text-white' : 'border-gray-300 text-gray-600'"
                                data-test="check-passed"
                                @click="checkModal.outcome = 'passed'"
                            >Esito positivo</button>
                            <button
                                class="rounded-lg border px-3 py-2.5 text-sm font-medium"
                                :class="checkModal.outcome === 'failed' ? 'border-red-700 bg-red-700 text-white' : 'border-gray-300 text-gray-600'"
                                data-test="check-failed"
                                @click="checkModal.outcome = 'failed'"
                            >Esito negativo</button>
                        </div>

                        <label class="mt-3 block text-xs">
                            <span class="text-gray-500">Note del controllo</span>
                            <textarea v-model="checkModal.notes" rows="2" data-test="check-notes" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm" />
                        </label>

                        <div v-if="checkModal.outcome === 'failed'" class="mt-3 space-y-3 rounded-lg bg-red-50/60 p-3">
                            <div class="grid grid-cols-2 gap-3">
                                <label class="block text-xs">
                                    <span class="text-gray-500">Gravità *</span>
                                    <select v-model="checkModal.nc.severity" data-test="check-severity" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-2 text-sm">
                                        <option v-for="(label, value) in NC_SEVERITY_LABELS" :key="value" :value="value">{{ label }}</option>
                                    </select>
                                </label>
                                <label class="block text-xs">
                                    <span class="text-gray-500">Da risolvere entro</span>
                                    <input v-model="checkModal.nc.due_on" type="date" :min="todayLocalYmd" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm">
                                </label>
                            </div>
                            <label class="block text-xs">
                                <span class="text-gray-500">Non conformità riscontrata *</span>
                                <textarea v-model="checkModal.nc.description" rows="2" data-test="check-nc-description" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm" placeholder="es. Fascia non sfalciata lungo il lato nord" />
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Responsabile della risoluzione</span>
                                <select v-model="checkModal.nc.responsible_id" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-2 text-sm">
                                    <option value="">—</option>
                                    <option v-for="p in personnel" :key="p.id" :value="p.id">{{ p.name }}</option>
                                </select>
                            </label>
                            <label class="flex items-center gap-2 text-sm">
                                <input v-model="checkModal.nc.create_corrective_order" type="checkbox" data-test="check-corrective" class="rounded border-gray-300">
                                <span>Genera l'ordine correttivo (in bozza, stessa squadra ed elementi)</span>
                            </label>
                        </div>

                        <p v-if="checkModal.error" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" data-test="check-error">{{ checkModal.error }}</p>

                        <button
                            class="mt-4 w-full rounded-lg bg-green-700 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50"
                            :disabled="checkModal.busy"
                            data-test="check-save"
                            @click="submitCheck"
                        >{{ checkModal.busy ? 'Registrazione…' : 'Registra il verbale' }}</button>
                    </div>
                </div>
            </Teleport>

            <!-- Dettaglio -->
            <Teleport to="body">
                <div v-if="detail" class="fixed inset-0 z-50 flex justify-end bg-black/30" @click.self="detail = null">
                    <div class="h-full w-full max-w-xl overflow-y-auto bg-white p-6 shadow-2xl" data-test="wo-detail">
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="text-xs uppercase tracking-wide text-gray-400">{{ detail.code }}</div>
                                <h2 class="text-lg font-semibold">{{ detail.title }}</h2>
                            </div>
                            <button class="text-gray-400 hover:text-gray-600" @click="detail = null">✕</button>
                        </div>

                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <span class="rounded-full px-3 py-1 text-xs font-medium" :class="STATUS_COLORS[detail.status]" data-test="wo-status">
                                {{ STATUS_LABELS[detail.status] ?? detail.status }}
                            </span>
                            <span class="text-xs text-gray-500">Priorità: {{ PRIORITY_LABELS[detail.priority] }}</span>
                            <span class="text-xs text-gray-400">v{{ detail.version }}</span>
                        </div>

                        <div v-if="canManage && detail.allowed_transitions.length" class="mt-3 flex flex-wrap gap-2">
                            <button
                                v-for="s in detail.allowed_transitions"
                                :key="s"
                                class="rounded-lg border border-green-700 px-3 py-1.5 text-xs font-medium text-green-700 hover:bg-green-50 disabled:opacity-50"
                                :disabled="detailBusy"
                                :data-test="`wo-to-${s}`"
                                @click="doTransition(s)"
                            >{{ STATUS_LABELS[s] }}</button>
                        </div>
                        <p v-if="detailError" class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" data-test="wo-error">{{ detailError }}</p>

                        <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-1.5 text-sm">
                            <div class="flex justify-between gap-3 border-b border-gray-50 py-1"><dt class="text-gray-500">Lavorazione</dt><dd>{{ detail.work_type?.name || '—' }}</dd></div>
                            <div class="flex justify-between gap-3 border-b border-gray-50 py-1"><dt class="text-gray-500">Cliente</dt><dd>{{ detail.client?.name || '—' }}</dd></div>
                            <div class="flex justify-between gap-3 border-b border-gray-50 py-1"><dt class="text-gray-500">Area</dt><dd>{{ detail.area?.name || '—' }}</dd></div>
                            <div class="flex justify-between gap-3 border-b border-gray-50 py-1"><dt class="text-gray-500">Squadra</dt><dd>{{ detail.team?.name || '—' }}</dd></div>
                            <div class="flex justify-between gap-3 border-b border-gray-50 py-1"><dt class="text-gray-500">Responsabile</dt><dd>{{ detail.assignee?.name || '—' }}</dd></div>
                            <div class="flex justify-between gap-3 border-b border-gray-50 py-1"><dt class="text-gray-500">Periodo</dt><dd>{{ fmt(detail.planned_start) }} – {{ fmt(detail.planned_end) }}</dd></div>
                            <div class="flex justify-between gap-3 border-b border-gray-50 py-1"><dt class="text-gray-500">Listino</dt><dd>{{ detail.price_list?.name || '—' }}</dd></div>
                        </dl>

                        <p v-if="detail.description" class="mt-3 whitespace-pre-line rounded-lg bg-gray-50 p-3 text-sm text-gray-700">{{ detail.description }}</p>

                        <label v-if="canManage" class="mt-3 flex items-start gap-2 rounded-lg border border-gray-200 p-3 text-sm">
                            <input
                                type="checkbox"
                                class="mt-0.5 rounded border-gray-300"
                                :checked="detail.is_public"
                                :disabled="detailBusy"
                                data-test="wo-pubblico"
                                @change="setPubblico($event.target.checked)"
                            >
                            <span>
                                Pubblica come atto sul portale del committente
                                <span class="block text-xs text-gray-500">
                                    A lavoro completato compare nella cronologia degli alberi interessati,
                                    come "Ordine di servizio N. {{ detail.code }}".
                                </span>
                            </span>
                        </label>

                        <h3 class="mt-5 text-sm font-semibold">Elementi ({{ detail.assets.length }})</h3>
                        <ul class="mt-2 divide-y divide-gray-50 rounded-lg border border-gray-100">
                            <li v-for="row in detail.assets" :key="row.id" class="px-3 py-2 text-sm" data-test="wo-riga-elemento">
                                <div class="flex items-center gap-3">
                                    <div class="min-w-0 flex-1">
                                        <span class="font-medium">{{ row.asset?.census_code || row.asset_id.slice(0, 8) }}</span>
                                        <span class="ml-2 truncate text-xs text-gray-500">{{ row.asset?.object_type?.name }}</span>
                                    </div>
                                    <span v-if="row.work_type" class="text-xs text-gray-500">{{ row.work_type.name }}</span>
                                    <span v-if="! canManage || ordineChiuso" class="text-xs text-gray-700">
                                        {{ row.planned_quantity != null ? `${fmtQty(row.planned_quantity)} ${row.unit ?? ''}` : '—' }}
                                    </span>
                                    <button
                                        v-if="canManage && ! ordineChiuso"
                                        class="text-xs font-medium text-red-600 hover:underline"
                                        @click="detachAsset(row.id)"
                                    >Rimuovi</button>
                                </div>
                                <div v-if="canManage && ! ordineChiuso && righeQuantita[row.id]" class="mt-1.5 flex flex-wrap items-center gap-2 text-xs">
                                    <span class="text-gray-500">Quantità prevista</span>
                                    <input
                                        v-model.number="righeQuantita[row.id].quantity"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        data-test="wo-riga-quantita"
                                        class="w-24 rounded-lg border border-gray-300 px-2 py-1 text-right text-sm"
                                        @input="righeQuantita[row.id].dirty = true"
                                    >
                                    <input
                                        v-model="righeQuantita[row.id].unit"
                                        maxlength="20"
                                        placeholder="unità"
                                        data-test="wo-riga-unita"
                                        class="w-16 rounded-lg border border-gray-300 px-2 py-1 text-sm"
                                        @input="righeQuantita[row.id].dirty = true"
                                    >
                                    <button
                                        v-if="righeQuantita[row.id].dirty"
                                        class="font-medium text-green-800 hover:underline disabled:opacity-50"
                                        :disabled="righeQuantita[row.id].busy"
                                        data-test="wo-riga-salva"
                                        @click="salvaQuantitaRiga(row)"
                                    >Salva</button>
                                    <button
                                        v-if="mostraRiprendi(row)"
                                        class="text-gray-500 hover:underline"
                                        data-test="wo-riga-ricalcola"
                                        @click="riprendiDallaMappa(row)"
                                    >Riprendi dalla mappa ({{ fmtQty(row.quantita_geometrica) }} {{ row.unita_geometrica ?? '' }})</button>
                                </div>
                            </li>
                            <li v-if="! detail.assets.length" class="px-3 py-3 text-sm text-gray-400">Nessun elemento collegato.</li>
                        </ul>
                        <p v-if="totaliPrevisti.length" class="mt-1.5 text-xs text-gray-600" data-test="wo-totale-previsto">
                            Totale previsto dagli elementi: {{ totaliPrevisti.join(' · ') }}
                        </p>

                        <!-- Consuntivi ed economia -->
                        <h3 class="mt-5 text-sm font-semibold">Consuntivi ({{ (detail.logs ?? []).length }})</h3>
                        <div v-if="canManage" class="mt-2 flex items-center gap-2 text-xs">
                            <span class="text-gray-500">Listino applicato</span>
                            <select
                                :value="detail.price_list_id ?? ''"
                                data-test="wo-price-list"
                                class="rounded-lg border border-gray-300 px-2 py-1.5 text-sm disabled:bg-gray-50"
                                :disabled="detailBusy || detail.status === 'completed' || detail.status === 'cancelled'"
                                @change="setPriceList($event.target.value)"
                            >
                                <option value="">Nessuno</option>
                                <option v-for="l in priceLists" :key="l.id" :value="l.id">{{ l.code }} — {{ l.name }}</option>
                            </select>
                            <span v-if="detail.status === 'completed' || detail.status === 'cancelled'" class="text-gray-400">
                                (ordine chiuso: il listino non si cambia più)
                            </span>
                        </div>
                        <table v-if="(detail.logs ?? []).length" class="mt-2 w-full text-sm" data-test="wo-logs">
                            <thead>
                                <tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wide text-gray-400">
                                    <th class="py-1.5 pr-2 font-medium">Inizio</th>
                                    <th class="py-1.5 pr-2 font-medium">Operatore</th>
                                    <th class="py-1.5 pr-2 text-right font-medium">Ore</th>
                                    <th class="py-1.5 pr-2 text-right font-medium">Quantità</th>
                                    <th class="py-1.5 font-medium">Note</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                <tr v-for="log in detail.logs" :key="log.id">
                                    <td class="py-1.5 pr-2 text-gray-600">{{ fmtDateTime(log.started_at) }}</td>
                                    <td class="py-1.5 pr-2">{{ log.operator?.name ?? '—' }}</td>
                                    <td class="py-1.5 pr-2 text-right">{{ log.man_hours != null ? fmtQty(log.man_hours) : '—' }}</td>
                                    <td class="py-1.5 pr-2 text-right">
                                        {{ log.quantity != null ? `${fmtQty(log.quantity)} ${log.unit ?? ''}` : '—' }}
                                    </td>
                                    <td class="max-w-40 truncate py-1.5 text-xs text-gray-500" :title="log.notes ?? ''">{{ log.notes ?? '' }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <p v-else class="mt-2 text-sm text-gray-400">Nessun consuntivo registrato dal campo.</p>

                        <div v-if="detail.consuntivo" class="mt-3 rounded-lg bg-gray-50 p-3 text-sm" data-test="wo-economia">
                            <div class="flex justify-between">
                                <span class="text-gray-500">Ore uomo totali</span>
                                <span class="font-medium">{{ fmtQty(detail.consuntivo.total_man_hours) }}</span>
                            </div>
                            <div v-for="(qty, unit) in detail.consuntivo.quantities" :key="unit" class="mt-1 flex justify-between">
                                <span class="text-gray-500">Quantità eseguita ({{ unit || 'senza unità' }})</span>
                                <span class="font-medium">{{ fmtQty(qty) }}</span>
                            </div>
                            <div v-if="detail.previsto?.valued && ! detail.previsto.valued.ambiguous && detail.previsto.valued.amount != null" class="mt-2 flex justify-between gap-3 border-t border-gray-200 pt-2">
                                <span class="text-gray-500">
                                    Importo previsto dagli elementi collegati
                                    ({{ fmtQty(detail.previsto.valued.quantity ?? 0) }} {{ detail.previsto.valued.unit }}
                                    × {{ fmtEuro(detail.previsto.valued.unit_price) }}<template v-if="detail.previsto.valued.overhead_pct"> + spese generali {{ fmtQty(detail.previsto.valued.overhead_pct) }}%</template><template v-if="detail.previsto.valued.safety_cost"> + sicurezza {{ fmtEuro(detail.previsto.valued.safety_cost) }}</template>)
                                </span>
                                <span class="font-semibold" data-test="wo-importo-previsto">{{ fmtEuro(detail.previsto.valued.amount) }}</span>
                            </div>
                            <div v-if="detail.consuntivo.valued && ! detail.consuntivo.valued.ambiguous" class="mt-2 flex justify-between gap-3 border-t border-gray-200 pt-2">
                                <span class="text-gray-500">
                                    Valore da listino
                                    ({{ fmtQty(detail.consuntivo.valued.quantity ?? 0) }} {{ detail.consuntivo.valued.unit }}
                                    × {{ fmtEuro(detail.consuntivo.valued.unit_price) }}<template v-if="detail.consuntivo.valued.overhead_pct"> + spese generali {{ fmtQty(detail.consuntivo.valued.overhead_pct) }}%</template><template v-if="detail.consuntivo.valued.safety_cost"> + sicurezza {{ fmtEuro(detail.consuntivo.valued.safety_cost) }}</template>)
                                </span>
                                <span class="font-semibold" data-test="wo-valore">{{ fmtEuro(detail.consuntivo.valued.amount) }}</span>
                            </div>
                            <p v-else-if="detail.consuntivo.valued?.ambiguous" class="mt-2 border-t border-gray-200 pt-2 text-xs text-amber-700" data-test="wo-valore-ambiguo">
                                {{ detail.consuntivo.valued.reason }}
                            </p>
                            <p v-else-if="! detail.price_list_id" class="mt-2 border-t border-gray-200 pt-2 text-xs text-gray-400">
                                Nessun listino applicato: il valore economico non viene calcolato.
                            </p>
                            <p v-else class="mt-2 border-t border-gray-200 pt-2 text-xs text-gray-400">
                                Il listino non ha una voce per la lavorazione di questo ordine.
                            </p>
                        </div>

                        <!-- Controlli qualità e non conformità dell'ordine -->
                        <div class="mt-5 flex items-center justify-between">
                            <h3 class="text-sm font-semibold">Controlli qualità ({{ (detail.checks ?? []).length }})</h3>
                            <button
                                v-if="canManage && ['in_progress', 'suspended', 'completed'].includes(detail.status)"
                                class="rounded-lg border border-green-700 px-3 py-1.5 text-xs font-medium text-green-700 hover:bg-green-50"
                                data-test="wo-new-check"
                                @click="openCheckModal"
                            >Registra controllo</button>
                        </div>
                        <ul v-if="(detail.checks ?? []).length" class="mt-2 divide-y divide-gray-50 rounded-lg border border-gray-100" data-test="wo-checks">
                            <li v-for="check in detail.checks" :key="check.id" class="px-3 py-2 text-sm">
                                <div class="flex items-center justify-between gap-2">
                                    <span
                                        class="rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="check.outcome === 'passed' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'"
                                    >{{ check.outcome === 'passed' ? 'Positivo' : 'Negativo' }}</span>
                                    <span class="text-xs text-gray-500">{{ check.checker?.name ?? '—' }} · {{ fmtDateTime(check.checked_at) }}</span>
                                </div>
                                <p v-if="check.notes" class="mt-1 text-xs text-gray-600">{{ check.notes }}</p>
                            </li>
                        </ul>
                        <p v-else class="mt-2 text-sm text-gray-400">Nessun controllo registrato.</p>

                        <ul v-if="(detail.non_conformities ?? []).length" class="mt-3 space-y-1.5" data-test="wo-ncs">
                            <li
                                v-for="nc in detail.non_conformities"
                                :key="nc.id"
                                class="rounded-lg border border-red-100 bg-red-50/50 px-3 py-2 text-sm"
                            >
                                <div class="flex items-center justify-between gap-2">
                                    <span class="font-medium">{{ nc.code }}</span>
                                    <span class="text-xs text-gray-600">
                                        {{ NC_SEVERITY_LABELS[nc.severity] }} · {{ NC_STATUS_LABELS[nc.status] ?? nc.status }}
                                    </span>
                                </div>
                                <p class="mt-0.5 text-xs text-gray-700">{{ nc.description }}</p>
                            </li>
                        </ul>

                        <div v-if="canManage" class="mt-3">
                            <input
                                v-model="assetSearch.q"
                                placeholder="Aggiungi elemento: cerca per codice censimento…"
                                class="w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm"
                                data-test="wo-asset-search"
                                @input="searchAssets"
                            >
                            <ul v-if="assetSearch.results.length" class="mt-1 divide-y divide-gray-50 rounded-lg border border-gray-200 bg-white shadow">
                                <li
                                    v-for="a in assetSearch.results"
                                    :key="a.id"
                                    class="flex cursor-pointer items-center gap-2 px-3 py-2 text-sm hover:bg-green-50"
                                    @click="attachAsset(a)"
                                >
                                    <span class="font-medium">{{ a.census_code || a.id.slice(0, 8) }}</span>
                                    <span class="truncate text-xs text-gray-500">{{ a.object_type?.name }}</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </Teleport>
        </div>
    </AppLayout>
</template>
