<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import * as maplibregl from 'maplibre-gl';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import AvvisoErrore from '@/Components/AvvisoErrore.vue';
import ScegliVoce from '@/Components/ScegliVoce.vue';
import { usaCaricamento } from '@/caricamento';
import { avvisoCaricamento, messaggioErrore } from '@/avvisi';
import { WORK_STATUS_LABELS } from '@/workStatus';
import { BOTTONE, BOTTONE_PICCOLO, BOTTONE_SECONDARIO, CARTA, CHIP, ETICHETTA, plurale } from '@/nuovo/stile';

/*
 * La pagina dell'ordine di lavoro (bozza A, schermata 07). Prima non c'era:
 * l'ordine si apriva in un cassetto sopra l'elenco. Qui ha il suo indirizzo
 * (/lavori/{id}), la testata con lo stato e i passaggi ammessi, le carte "Che
 * cosa si fa", "Elementi", "Consuntivo", "Controlli qualita'", e a fianco la
 * mappa degli elementi, i documenti collegati e la cronologia. Le chiamate
 * sono quelle del cassetto di prima.
 */
const props = defineProps({ ordineId: { type: String, required: true } });

const page = usePage();
const permessi = computed(() => page.props.auth?.user?.permissions ?? []);
const can = (p) => permessi.value.includes(p);
const canManage = computed(() => can('works.manage'));
const parametri = new URLSearchParams(window.location.search);

const { avviso, riprovaInCorso, carica, riprova } = usaCaricamento();
const ordine = ref(null);
const cronologia = ref(null);
const teams = ref([]);
const personnel = ref([]);
const workTypes = ref([]);
const priceLists = ref([]);
const azione = reactive({ errore: '', inCorso: false });
const nonCollegati = Number(parametri.get('non_collegati') ?? 0);

const PRIORITA = { low: 'Bassa', normal: 'Normale', high: 'Alta', urgent: 'Urgente' };
const ORIGINI = {
    manual: 'creato a mano', estimate: 'da un preventivo accettato', inspection: "da un'ispezione", issue: 'da una segnalazione',
    maintenance_plan: 'da un piano di manutenzione', non_conformity: 'da una non conformità', vta_recheck: 'dallo scadenzario VTA', work_check: 'da un controllo qualità',
};
const TONO_STATO = { draft: 'neutra', planned: 'info', assigned: 'info', in_progress: 'ok', suspended: 'attenzione', completed: 'neutra', cancelled: 'neutra' };
// Il verbo di ogni passaggio, come lo direbbe chi lavora
const VERBI = { draft: 'Riporta in bozza', planned: 'Pianifica', assigned: 'Assegna', in_progress: 'Avvia', suspended: 'Sospendi', completed: 'Chiudi con consuntivo', cancelled: 'Annulla l\'ordine' };
const NC_STATO = { open: 'Aperta', action: 'In azione', verified: 'Verificata', closed: 'Chiusa' };
const NC_GRAVITA = { minor: 'Lieve', major: 'Grave', critical: 'Critica' };
const TIPO_EVENTO = {
    creato: 'Creazione', stato: 'Stato', elementi: 'Elementi', giorno: 'Giornata', modifica: 'Modifica', consuntivo: 'Consuntivo',
    foto: 'Foto', controllo: 'Controllo', non_conformita: 'Non conformità', riprogrammazione: 'Impresa', ritardo: 'Ritardo',
};

// --- Dati -------------------------------------------------------------------
async function load() {
    const [o, c] = await Promise.all([axios.get(`/api/v1/work-orders/${props.ordineId}`), axios.get(`/api/v1/work-orders/${props.ordineId}/cronologia`)]);
    ordine.value = o.data.data;
    cronologia.value = c.data.data;
}

async function caricaAnagrafiche() {
    const [t, wt, pl] = await Promise.all([axios.get('/api/v1/teams'), axios.get('/api/v1/work-types'), axios.get('/api/v1/price-lists')]);
    teams.value = t.data.data;
    workTypes.value = wt.data.data;
    priceLists.value = pl.data.data;
    if (canManage.value) personnel.value = (await axios.get('/api/v1/personnel')).data.data;
}

async function ricarica() {
    await carica(load);
    aggiornaMappa();
}

// --- Testi ------------------------------------------------------------------
const oggiIso = new Date().toISOString().slice(0, 10);
const IN_LAVORAZIONE = ['planned', 'assigned', 'in_progress', 'suspended'];
function formatData(v, conAnno = true) {
    if (! v) return null;
    const [a, m, g] = String(v).slice(0, 10).split('-');

    return conAnno ? `${g}/${m}/${a}` : `${g}/${m}`;
}
const fmtOra = (iso) => (iso ? new Date(iso).toLocaleString('it-IT', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '—');
const fmtEuro = (v) => (v == null ? '—' : Number(v).toLocaleString('it-IT', { style: 'currency', currency: 'EUR' }));
const fmtQta = (v) => (v == null ? '—' : Number(v).toLocaleString('it-IT'));
const chiuso = computed(() => ['completed', 'cancelled'].includes(ordine.value?.status));
const inRitardo = computed(() => ordine.value && IN_LAVORAZIONE.includes(ordine.value.status) && ordine.value.planned_end && String(ordine.value.planned_end).slice(0, 10) < oggiIso);
const periodo = computed(() => {
    const o = ordine.value;
    if (! o) return '';
    const i = formatData(o.planned_start);
    const f = formatData(o.planned_end);
    if (! i && ! f) return 'da pianificare';

    return i && f && i !== f ? `${i} – ${f}` : (i ?? f);
});
const transizioni = computed(() => ordine.value?.allowed_transitions ?? []);
// Il passaggio "in avanti" e' il pulsante principale; gli altri stanno accanto o in "Altro"
const avanti = computed(() => ['completed', 'in_progress', 'assigned', 'planned'].find((s) => transizioni.value.includes(s)) ?? null);
const secondari = computed(() => transizioni.value.filter((s) => s !== avanti.value && ! ['cancelled', 'draft'].includes(s)));
const inAltro = computed(() => transizioni.value.filter((s) => ['cancelled', 'draft'].includes(s)));
const documenti = computed(() => cronologia.value?.documenti ?? []);
const perElemento = computed(() => cronologia.value?.per_elemento ?? {});
const fatti = computed(() => (ordine.value?.assets ?? []).filter((r) => perElemento.value[r.asset_id]?.fatti).length);

// --- Passaggi di stato e altre azioni ---------------------------------------
async function conAzione(fn, predefinito = 'Operazione non riuscita') {
    azione.inCorso = true;
    azione.errore = '';
    try {
        await fn();
        await ricarica();
    } catch (err) {
        azione.errore = messaggioErrore(err, predefinito);
    } finally {
        azione.inCorso = false;
    }
}

function confermaAnnullamento() {
    const o = ordine.value;
    const i = formatData(o.planned_start);
    const f = formatData(o.planned_end);
    const quando = i && f && i !== f ? ` previsto dal ${i} al ${f}` : (i ? ` previsto per il ${i}` : '');

    return window.confirm(`Annullare l'ordine ${o.code} "${o.title}"${quando}?\n\nL'annullamento riguarda l'intero ordine e non si può tornare indietro.`);
}

const cambiaStato = (stato) => {
    if (stato === 'cancelled' && ! confermaAnnullamento()) return;
    if (stato === 'completed' && ! window.confirm(`Chiudere l'ordine ${ordine.value.code} come completato? Il consuntivo registrato finora è quello che vale.`)) return;
    conAzione(() => axios.post(`/api/v1/work-orders/${props.ordineId}/transition`, { status: stato, version: ordine.value.version }), 'Errore nel cambio di stato');
};
const setPubblico = (valore) => conAzione(() => axios.patch(`/api/v1/work-orders/${props.ordineId}`, { is_public: valore, version: ordine.value.version }));
const setListino = (id) => conAzione(() => axios.patch(`/api/v1/work-orders/${props.ordineId}`, { price_list_id: id || null, version: ordine.value.version }));

// --- Modifica di "che cosa si fa" ------------------------------------------
const modifica = reactive({ aperta: parametri.get('modifica') === '1', busy: false, errore: '', form: {} });
function apriModifica() {
    const o = ordine.value;
    modifica.form = {
        title: o.title ?? '', description: o.description ?? '', work_type_id: o.work_type_id ?? '', priority: o.priority ?? 'normal',
        planned_start: o.planned_start ? String(o.planned_start).slice(0, 10) : '', planned_end: o.planned_end ? String(o.planned_end).slice(0, 10) : '',
        team_id: o.team_id ?? '', assigned_to: o.assigned_to ?? '', estimated_duration_min: o.estimated_duration_min ?? '', risks: o.risks ?? '',
    };
    modifica.errore = '';
    modifica.aperta = true;
}
const squadreAmmesse = computed(() => teams.value.filter((t) => ! t.is_external || (t.client_id && t.client_id === ordine.value?.client_id)));
async function salvaModifica() {
    modifica.busy = true;
    modifica.errore = '';
    try {
        const f = modifica.form;
        await axios.patch(`/api/v1/work-orders/${props.ordineId}`, {
            version: ordine.value.version,
            title: f.title, description: f.description || null, work_type_id: f.work_type_id || null, priority: f.priority,
            planned_start: f.planned_start || null, planned_end: f.planned_end || null, team_id: f.team_id || null, assigned_to: f.assigned_to || null,
            estimated_duration_min: f.estimated_duration_min === '' ? null : Number(f.estimated_duration_min), risks: f.risks || null,
        });
        modifica.aperta = false;
        await ricarica();
    } catch (err) {
        modifica.errore = messaggioErrore(err, 'Modifica non salvata');
    } finally {
        modifica.busy = false;
    }
}
watch(ordine, (o) => { if (o && modifica.aperta && ! modifica.form.title && ! modifica.busy) apriModifica(); });

// --- Elementi ---------------------------------------------------------------
const ricerca = reactive({ q: '', risultati: [], busy: false });
let attesaRicerca = null;
function cercaElementi() {
    clearTimeout(attesaRicerca);
    attesaRicerca = setTimeout(async () => {
        if (ricerca.q.trim().length < 2) {
            ricerca.risultati = [];

            return;
        }
        const { data } = await axios.get('/api/v1/assets', { params: { q: ricerca.q, per_page: 8, archivio: 0 } });
        ricerca.risultati = data.data;
    }, 250);
}
const aggiungiElemento = (a) => conAzione(async () => {
    await axios.post(`/api/v1/work-orders/${props.ordineId}/assets`, { asset_id: a.id });
    ricerca.q = '';
    ricerca.risultati = [];
}, "Errore nell'aggiunta");
const togliElemento = (riga) => {
    if (! window.confirm(`Togliere ${riga.asset?.census_code ?? 'questo elemento'} dall'ordine?`)) return;
    conAzione(() => axios.delete(`/api/v1/work-orders/${props.ordineId}/assets/${riga.id}`), 'Errore nella rimozione');
};

// Quantita' previste per riga: si modificano una alla volta
const righeQuantita = reactive({});
watch(() => ordine.value?.assets, (righe) => {
    const correnti = new Set((righe ?? []).map((r) => r.id));
    Object.keys(righeQuantita).forEach((k) => { if (! correnti.has(k)) delete righeQuantita[k]; });
    (righe ?? []).forEach((r) => {
        if (righeQuantita[r.id]?.dirty) return;
        righeQuantita[r.id] = { quantity: r.planned_quantity != null ? Number(r.planned_quantity) : null, unit: r.unit ?? '', dirty: false, busy: false };
    });
});
async function salvaQuantita(riga) {
    const m = righeQuantita[riga.id];
    if (! m) return;
    m.busy = true;
    azione.errore = '';
    try {
        await axios.patch(`/api/v1/work-orders/${props.ordineId}/assets/${riga.id}`, {
            planned_quantity: m.quantity === '' || m.quantity == null ? null : m.quantity, unit: m.unit.trim() || null, version: ordine.value.version,
        });
        m.dirty = false;
        await ricarica();
    } catch (err) {
        azione.errore = messaggioErrore(err, 'Quantità non salvata');
    } finally {
        m.busy = false;
    }
}
const riprendiDallaMappa = (riga) => conAzione(() => axios.patch(`/api/v1/work-orders/${props.ordineId}/assets/${riga.id}`, { ricalcola: true, version: ordine.value.version }), 'Misura non ripresa');
const mostraRiprendi = (riga) => canManage.value && ! chiuso.value && riga.quantita_geometrica != null
    && Number(riga.quantita_geometrica) !== Number(riga.planned_quantity ?? NaN);
const totaliPrevisti = computed(() => {
    const perUnita = {};
    (ordine.value?.assets ?? []).forEach((r) => {
        if (r.planned_quantity == null) return;
        const u = r.unit ?? ordine.value?.work_type?.unit ?? '';
        perUnita[u] = (perUnita[u] ?? 0) + Number(r.planned_quantity);
    });

    return Object.entries(perUnita).map(([u, q]) => `${fmtQta(q)} ${u}`.trim());
});

// --- Controllo qualita' -----------------------------------------------------
const controllo = reactive({ aperto: false, busy: false, errore: '', outcome: 'passed', notes: '', nc: { severity: 'minor', description: '', due_on: '', responsible_id: '', create_corrective_order: true } });
function apriControllo() {
    Object.assign(controllo, { aperto: true, busy: false, errore: '', outcome: 'passed', notes: '' });
    controllo.nc = { severity: 'minor', description: '', due_on: '', responsible_id: '', create_corrective_order: true };
}
async function registraControllo() {
    if (controllo.outcome === 'failed' && ! controllo.nc.description.trim()) {
        controllo.errore = 'Descrivi la non conformità riscontrata.';

        return;
    }
    controllo.busy = true;
    controllo.errore = '';
    try {
        await axios.post(`/api/v1/work-orders/${props.ordineId}/checks`, {
            outcome: controllo.outcome,
            notes: controllo.notes.trim() || null,
            ...(controllo.outcome === 'failed' ? { non_conformity: {
                severity: controllo.nc.severity, description: controllo.nc.description.trim(), due_on: controllo.nc.due_on || null,
                responsible_id: controllo.nc.responsible_id || null, create_corrective_order: controllo.nc.create_corrective_order,
            } } : {}),
        });
        controllo.aperto = false;
        await ricarica();
    } catch (err) {
        controllo.errore = messaggioErrore(err, 'Registrazione non riuscita');
    } finally {
        controllo.busy = false;
    }
}

// --- Mappa degli elementi: verde fatto, arancione da fare -------------------
const mapEl = ref(null);
let map = null;
function geojsonElementi() {
    return {
        type: 'FeatureCollection',
        features: (ordine.value?.assets ?? []).filter((r) => r.asset?.geom_geojson).map((r) => ({
            type: 'Feature', geometry: r.asset.geom_geojson,
            properties: { fatto: Boolean(perElemento.value[r.asset_id]?.fatti), codice: r.asset.census_code ?? '' },
        })),
    };
}
function aggiornaMappa() {
    if (! map || ! map.getSource('elementi')) return;
    map.getSource('elementi').setData(geojsonElementi());
}
function initMap() {
    if (map || ! mapEl.value) return;
    const dati = geojsonElementi();
    if (! dati.features.length) return;
    map = new maplibregl.Map({
        container: mapEl.value, zoom: 16, interactive: true, attributionControl: false,
        style: {
            version: 8,
            sources: { osm: { type: 'raster', tiles: ['https://tile.openstreetmap.org/{z}/{x}/{y}.png'], tileSize: 256, maxzoom: 19 } },
            layers: [{ id: 'sfondo', type: 'background', paint: { 'background-color': '#e8ede9' } }, { id: 'osm', type: 'raster', source: 'osm' }],
        },
    });
    map.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');
    map.on('load', () => {
        map.addSource('elementi', { type: 'geojson', data: dati });
        const colore = ['case', ['get', 'fatto'], '#15803d', '#d97706'];
        map.addLayer({ id: 'el-fill', type: 'fill', source: 'elementi', filter: ['==', ['geometry-type'], 'Polygon'], paint: { 'fill-color': colore, 'fill-opacity': 0.3 } });
        map.addLayer({ id: 'el-line', type: 'line', source: 'elementi', filter: ['!=', ['geometry-type'], 'Point'], paint: { 'line-color': colore, 'line-width': 2.5 } });
        map.addLayer({ id: 'el-point', type: 'circle', source: 'elementi', filter: ['==', ['geometry-type'], 'Point'], paint: { 'circle-color': colore, 'circle-radius': 7, 'circle-stroke-width': 2, 'circle-stroke-color': '#fff' } });
        const bounds = new maplibregl.LngLatBounds();
        const extend = (c) => { if (typeof c[0] === 'number') bounds.extend(c); else c.forEach(extend); };
        dati.features.forEach((f) => extend(f.geometry.coordinates));
        map.fitBounds(bounds, { padding: 40, maxZoom: 18 });
    });
}

onMounted(async () => {
    const ok = await carica(async () => { await Promise.all([load(), caricaAnagrafiche()]); });
    if (ok) initMap();
});
onBeforeUnmount(() => map?.remove());
</script>

<template>
    <Head :title="ordine ? `${ordine.code} · ${ordine.title}` : 'Ordine di lavoro'" />

    <AppLayout>
        <div class="mx-auto flex max-w-[1640px] flex-col gap-4 p-4 md:p-6 lg:px-7">
            <nav class="text-[13px] text-gray-500" aria-label="Percorso">
                <Link href="/lavori" class="hover:underline">Lavori</Link> › <Link href="/lavori" class="hover:underline">Ordini</Link>
                <template v-if="ordine"> › <span class="text-gray-900">{{ ordine.code }}</span></template>
            </nav>

            <AvvisoErrore :messaggio="avviso" :in-corso="riprovaInCorso" @riprova="riprova" />
            <p v-if="! ordine && ! avviso" class="text-sm text-gray-500">Carico l'ordine…</p>
            <p v-if="nonCollegati" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900" data-test="ordine-non-collegati">
                {{ nonCollegati }} {{ plurale(nonCollegati, 'elemento scelto non è stato collegato', 'elementi scelti non sono stati collegati') }} (in archivio o già presenti).
            </p>

            <template v-if="ordine">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div :class="ETICHETTA">Ordine di lavoro {{ ordine.code }}</div>
                        <h1 class="text-2xl font-bold text-gray-900" data-test="ordine-titolo">{{ ordine.title }}</h1>
                        <div class="mt-2 flex flex-wrap items-center gap-2 text-[13px]">
                            <span :class="CHIP[TONO_STATO[ordine.status] ?? 'neutra']" data-test="ordine-stato">{{ WORK_STATUS_LABELS[ordine.status] ?? ordine.status }}</span>
                            <span v-if="inRitardo" :class="CHIP.errore" data-test="ordine-ritardo">In ritardo: doveva chiudersi il {{ formatData(ordine.planned_end) }}</span>
                            <span v-if="['high', 'urgent'].includes(ordine.priority)" :class="CHIP.attenzione">priorità {{ PRIORITA[ordine.priority].toLowerCase() }}</span>
                            <span v-if="ordine.is_public" :class="CHIP.info">pubblicato sul portale</span>
                            <span class="text-gray-600">{{ [ordine.client?.name, ordine.area?.name].filter(Boolean).join(' · ') }}<template v-if="ordine.team || ordine.assignee"> · {{ ordine.team?.name ?? '' }}{{ ordine.team && ordine.assignee ? ' / ' : '' }}{{ ordine.assignee?.name ?? '' }}</template></span>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <template v-if="canManage">
                            <button v-if="avanti" type="button" :class="BOTTONE" :disabled="azione.inCorso" :data-test="`ordine-a-${avanti}`" @click="cambiaStato(avanti)">{{ VERBI[avanti] }}</button>
                            <button v-for="s in secondari" :key="s" type="button" :class="BOTTONE_SECONDARIO" :disabled="azione.inCorso" :data-test="`ordine-a-${s}`" @click="cambiaStato(s)">{{ VERBI[s] }}</button>
                            <button v-if="! chiuso" type="button" :class="BOTTONE_SECONDARIO" data-test="ordine-riprogramma" @click="apriModifica">Riprogramma</button>
                        </template>
                        <details class="relative">
                            <summary :class="BOTTONE_SECONDARIO" class="cursor-pointer list-none">Altro</summary>
                            <div :class="CARTA" class="absolute right-0 z-20 mt-1 w-80 p-2 text-sm shadow-lg" data-test="menu-altro-ordine">
                                <label v-if="canManage" class="flex min-h-11 items-center gap-2 rounded-lg px-3 hover:bg-gray-50 md:min-h-9">
                                    <input type="checkbox" class="rounded border-gray-300" :checked="ordine.is_public" :disabled="azione.inCorso" data-test="ordine-pubblico" @change="setPubblico($event.target.checked)">
                                    Pubblica come atto sul portale del committente
                                </label>
                                <button v-if="canManage && ['in_progress', 'suspended', 'completed'].includes(ordine.status)" type="button" class="flex min-h-11 w-full items-center rounded-lg px-3 text-left hover:bg-gray-50 md:min-h-9" data-test="ordine-nuovo-controllo" @click="apriControllo">Registra un controllo qualità</button>
                                <button v-for="s in (canManage ? inAltro : [])" :key="s" type="button" class="flex min-h-11 w-full items-center rounded-lg px-3 text-left hover:bg-gray-50 md:min-h-9" :class="s === 'cancelled' ? 'text-red-700' : ''" :disabled="azione.inCorso" :data-test="`ordine-a-${s}`" @click="cambiaStato(s)">{{ VERBI[s] }}</button>
                                <Link :href="`/lavori?precedente=1&ordine=${ordine.code}`" class="flex min-h-11 items-center rounded-lg border-t border-gray-100 px-3 text-gray-600 hover:bg-gray-50 md:min-h-9">Cassetto della veste precedente</Link>
                            </div>
                        </details>
                    </div>
                </div>
                <p v-if="azione.errore" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" data-test="ordine-errore">{{ azione.errore }}</p>

                <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_380px] lg:items-start">
                    <div class="flex min-w-0 flex-col gap-4">
                        <!-- Che cosa si fa -->
                        <section :class="CARTA" class="p-4" data-test="sezione-cosa">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <h2 class="text-base font-bold text-gray-900">Che cosa si fa</h2>
                                <button v-if="canManage && ! chiuso && ! modifica.aperta" type="button" :class="BOTTONE_PICCOLO" data-test="ordine-modifica" @click="apriModifica">Modifica</button>
                            </div>
                            <form v-if="modifica.aperta" class="mt-3 rounded-lg border border-green-200 bg-green-50/40 p-3" data-test="ordine-modulo" @submit.prevent="salvaModifica">
                                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                    <label class="col-span-full block text-xs"><span class="text-gray-600">Titolo</span><input v-model="modifica.form.title" class="mt-1 w-full rounded-lg border border-gray-300 bg-white px-2.5 py-2 text-sm" required></label>
                                    <label class="block text-xs"><span class="text-gray-600">Lavorazione</span>
                                        <select v-model="modifica.form.work_type_id" class="mt-1 w-full rounded-lg border border-gray-300 bg-white px-2 py-2 text-sm"><option value="">—</option><option v-for="w in workTypes" :key="w.id" :value="w.id">{{ w.name }} ({{ w.unit }})</option></select>
                                    </label>
                                    <label class="block text-xs"><span class="text-gray-600">Priorità</span>
                                        <select v-model="modifica.form.priority" class="mt-1 w-full rounded-lg border border-gray-300 bg-white px-2 py-2 text-sm"><option v-for="(l, v) in PRIORITA" :key="v" :value="v">{{ l }}</option></select>
                                    </label>
                                    <label class="block text-xs"><span class="text-gray-600">Durata stimata (minuti)</span><input v-model="modifica.form.estimated_duration_min" type="number" min="0" class="mt-1 w-full rounded-lg border border-gray-300 bg-white px-2.5 py-2 text-sm"></label>
                                    <label class="block text-xs"><span class="text-gray-600">Inizio previsto</span><input v-model="modifica.form.planned_start" type="date" class="mt-1 w-full rounded-lg border border-gray-300 bg-white px-2.5 py-2 text-sm" data-test="ordine-inizio"></label>
                                    <label class="block text-xs"><span class="text-gray-600">Fine prevista</span><input v-model="modifica.form.planned_end" type="date" class="mt-1 w-full rounded-lg border border-gray-300 bg-white px-2.5 py-2 text-sm" data-test="ordine-fine"></label>
                                    <label class="block text-xs"><span class="text-gray-600">Squadra</span>
                                        <select v-model="modifica.form.team_id" class="mt-1 w-full rounded-lg border border-gray-300 bg-white px-2 py-2 text-sm"><option value="">—</option><option v-for="t in squadreAmmesse" :key="t.id" :value="t.id">{{ t.name }}{{ t.is_external ? ' — impresa' : '' }}</option></select>
                                    </label>
                                    <label class="block text-xs"><span class="text-gray-600">Responsabile</span>
                                        <select v-model="modifica.form.assigned_to" class="mt-1 w-full rounded-lg border border-gray-300 bg-white px-2 py-2 text-sm"><option value="">—</option><option v-for="p in personnel" :key="p.id" :value="p.id">{{ p.name }}</option></select>
                                    </label>
                                    <label class="col-span-full block text-xs"><span class="text-gray-600">Descrizione e note per la squadra</span><textarea v-model="modifica.form.description" rows="2" class="mt-1 w-full rounded-lg border border-gray-300 bg-white px-2.5 py-2 text-sm" /></label>
                                    <label class="col-span-full block text-xs"><span class="text-gray-600">Rischi e prescrizioni di sicurezza</span><textarea v-model="modifica.form.risks" rows="2" class="mt-1 w-full rounded-lg border border-gray-300 bg-white px-2.5 py-2 text-sm" /></label>
                                </div>
                                <p v-if="modifica.errore" class="mt-2 text-sm text-red-700">{{ modifica.errore }}</p>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <button type="submit" :class="BOTTONE" :disabled="modifica.busy" data-test="ordine-salva">{{ modifica.busy ? 'Salvataggio…' : 'Salva' }}</button>
                                    <button type="button" :class="BOTTONE_SECONDARIO" @click="modifica.aperta = false">Annulla</button>
                                </div>
                            </form>
                            <dl v-else class="mt-3 grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                                <div><dt class="text-xs text-gray-500">Lavorazione</dt><dd class="font-semibold text-gray-900">{{ ordine.work_type?.name ?? '—' }}<span v-if="ordine.work_type?.unit" class="font-normal text-gray-500"> · {{ ordine.work_type.unit }}</span></dd></div>
                                <div><dt class="text-xs text-gray-500">Priorità</dt><dd class="font-semibold text-gray-900">{{ PRIORITA[ordine.priority] ?? ordine.priority }}</dd></div>
                                <div><dt class="text-xs text-gray-500">Periodo</dt><dd class="font-semibold" :class="inRitardo ? 'text-red-800' : 'text-gray-900'">{{ periodo }}</dd></div>
                                <div><dt class="text-xs text-gray-500">Origine</dt><dd class="font-semibold text-gray-900">{{ ORIGINI[ordine.origin] ?? ordine.origin ?? '—' }}</dd></div>
                                <div><dt class="text-xs text-gray-500">Squadra e responsabile</dt><dd class="font-semibold text-gray-900">{{ [ordine.team?.name, ordine.assignee?.name].filter(Boolean).join(' · ') || '—' }}</dd></div>
                                <div v-if="ordine.estimated_duration_min"><dt class="text-xs text-gray-500">Durata stimata</dt><dd class="font-semibold text-gray-900">{{ ordine.estimated_duration_min }} minuti</dd></div>
                                <div><dt class="text-xs text-gray-500">Listino</dt><dd class="font-semibold text-gray-900">{{ ordine.price_list?.name ?? 'nessuno' }}</dd></div>
                                <div v-if="ordine.previsto?.valued && ! ordine.previsto.valued.ambiguous && ordine.previsto.valued.amount != null"><dt class="text-xs text-gray-500">Importo previsto</dt><dd class="font-semibold text-gray-900" data-test="ordine-importo-previsto">{{ fmtEuro(ordine.previsto.valued.amount) }}<span class="font-normal text-gray-500"> · {{ fmtQta(ordine.previsto.valued.quantity ?? 0) }} {{ ordine.previsto.valued.unit }} × {{ fmtEuro(ordine.previsto.valued.unit_price) }}</span></dd></div>
                                <div v-for="d in documenti.filter((x) => x.tipo === 'preventivo')" :key="d.codice"><dt class="text-xs text-gray-500">Preventivo</dt><dd class="font-semibold text-gray-900"><Link :href="d.href" class="underline-offset-2 hover:underline">{{ d.codice }}</Link> · {{ d.stato }}</dd></div>
                            </dl>
                            <div v-if="! modifica.aperta && ordine.description" class="mt-3"><div class="text-xs text-gray-500">Note per la squadra</div><p class="whitespace-pre-line text-sm text-gray-900">{{ ordine.description }}</p></div>
                            <div v-if="! modifica.aperta && ordine.risks" class="mt-3"><div class="text-xs text-gray-500">Rischi e prescrizioni</div><p class="whitespace-pre-line text-sm text-gray-900">{{ ordine.risks }}</p></div>
                        </section>

                        <!-- Elementi -->
                        <section :class="CARTA" data-test="sezione-elementi">
                            <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3">
                                <h2 class="text-base font-bold text-gray-900">Elementi ({{ ordine.assets.length }})<span v-if="ordine.assets.length" class="ml-2 text-sm font-normal text-gray-500">{{ fatti }} {{ plurale(fatti, 'fatto', 'fatti') }} su {{ ordine.assets.length }}</span></h2>
                                <div v-if="canManage && ! chiuso" class="relative w-full sm:w-80">
                                    <input v-model="ricerca.q" placeholder="Aggiungi: cerca per cartellino o specie…" class="w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm" data-test="ordine-cerca-elemento" @input="cercaElementi">
                                    <ul v-if="ricerca.risultati.length" class="absolute left-0 right-0 z-10 mt-1 divide-y divide-gray-100 rounded-lg border border-gray-200 bg-white shadow-lg">
                                        <li v-for="a in ricerca.risultati" :key="a.id">
                                            <button type="button" class="flex min-h-11 w-full items-center gap-2 px-3 text-left text-sm hover:bg-green-50" @click="aggiungiElemento(a)">
                                                <span class="font-semibold">{{ a.census_code || 'senza cartellino' }}</span><span class="truncate text-xs text-gray-500">{{ a.object_type?.name }}</span>
                                            </button>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                            <div v-if="ordine.assets.length" class="overflow-x-auto border-t border-gray-100">
                                <table class="w-full text-sm">
                                    <thead><tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500"><th class="px-4 py-2">Cartellino</th><th class="px-3 py-2">Specie o tipo</th><th class="px-3 py-2">Quantità prevista</th><th class="px-3 py-2">Stato</th><th class="px-3 py-2">Fatto il</th><th class="px-3 py-2 text-right">Foto</th><th v-if="canManage && ! chiuso" class="px-3 py-2"></th></tr></thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <tr v-for="r in ordine.assets" :key="r.id" data-test="ordine-riga-elemento">
                                            <td class="whitespace-nowrap px-4 py-2 font-semibold text-gray-900"><Link :href="`/censimento/${r.asset_id}`" class="underline-offset-2 hover:underline">{{ r.asset?.census_code || r.asset_id.slice(0, 8) }}</Link></td>
                                            <td class="px-3 py-2 text-gray-700">{{ r.asset?.tree?.species || r.asset?.object_type?.name || '—' }}<span v-if="r.work_type" class="block text-xs text-gray-500">{{ r.work_type.name }}</span></td>
                                            <td class="px-3 py-2">
                                                <div v-if="canManage && ! chiuso && righeQuantita[r.id]" class="flex flex-wrap items-center gap-1.5">
                                                    <input v-model.number="righeQuantita[r.id].quantity" type="number" step="0.01" min="0" class="w-24 rounded-lg border border-gray-300 px-2 py-1.5 text-right text-sm" aria-label="Quantità prevista" data-test="ordine-riga-quantita" @input="righeQuantita[r.id].dirty = true">
                                                    <input v-model="righeQuantita[r.id].unit" maxlength="20" placeholder="unità" class="w-20 rounded-lg border border-gray-300 px-2 py-1.5 text-sm" aria-label="Unità" @input="righeQuantita[r.id].dirty = true">
                                                    <button v-if="righeQuantita[r.id].dirty" type="button" class="min-h-9 text-[13px] font-semibold text-green-800 underline-offset-2 hover:underline" :disabled="righeQuantita[r.id].busy" data-test="ordine-riga-salva" @click="salvaQuantita(r)">Salva</button>
                                                    <button v-if="mostraRiprendi(r)" type="button" class="min-h-9 text-[13px] text-gray-600 underline-offset-2 hover:underline" @click="riprendiDallaMappa(r)">Riprendi dalla mappa ({{ fmtQta(r.quantita_geometrica) }} {{ r.unita_geometrica ?? '' }})</button>
                                                </div>
                                                <span v-else class="text-gray-700">{{ r.planned_quantity != null ? `${fmtQta(r.planned_quantity)} ${r.unit ?? ''}` : '—' }}</span>
                                            </td>
                                            <td class="whitespace-nowrap px-3 py-2"><span :class="perElemento[r.asset_id]?.fatti ? CHIP.ok : CHIP.attenzione">{{ perElemento[r.asset_id]?.fatti ? 'Fatto' : 'Da fare' }}</span></td>
                                            <td class="whitespace-nowrap px-3 py-2 text-gray-700">{{ formatData(perElemento[r.asset_id]?.ultimo) ?? '—' }}</td>
                                            <td class="px-3 py-2 text-right text-gray-700">{{ perElemento[r.asset_id]?.foto ?? 0 }}</td>
                                            <td v-if="canManage && ! chiuso" class="px-3 py-2 text-right"><button type="button" class="min-h-9 text-[13px] text-red-700 underline-offset-2 hover:underline" @click="togliElemento(r)">Togli</button></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <p v-else class="border-t border-gray-100 px-4 py-3 text-sm text-gray-500">Nessun elemento collegato: si aggiungono da qui cercando il cartellino, o da Patrimonio selezionando le righe.</p>
                            <p v-if="totaliPrevisti.length" class="border-t border-gray-100 px-4 py-2 text-[13px] text-gray-600" data-test="ordine-totale-previsto">Totale previsto dagli elementi: {{ totaliPrevisti.join(' · ') }}</p>
                        </section>

                        <!-- Consuntivo -->
                        <section :class="CARTA" class="p-4" data-test="sezione-consuntivo">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <h2 class="text-base font-bold text-gray-900">Consuntivo</h2>
                                <label v-if="canManage" class="flex items-center gap-2 text-[13px] text-gray-600">Listino applicato
                                    <select :value="ordine.price_list_id ?? ''" class="rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-sm disabled:bg-gray-50" :disabled="azione.inCorso || chiuso" data-test="ordine-listino" @change="setListino($event.target.value)">
                                        <option value="">Nessuno</option><option v-for="l in priceLists" :key="l.id" :value="l.id">{{ l.code }} — {{ l.name }}</option>
                                    </select>
                                </label>
                            </div>
                            <div v-if="(ordine.logs ?? []).length" class="mt-2 overflow-x-auto">
                                <table class="w-full text-sm" data-test="ordine-consuntivi">
                                    <thead><tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500"><th class="py-1.5 pr-2">Quando</th><th class="py-1.5 pr-2">Operatore</th><th class="py-1.5 pr-2 text-right">Ore</th><th class="py-1.5 pr-2 text-right">Quantità</th><th class="py-1.5">Note</th></tr></thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <tr v-for="l in ordine.logs" :key="l.id">
                                            <td class="whitespace-nowrap py-1.5 pr-2 text-gray-700">{{ fmtOra(l.started_at) }}</td>
                                            <td class="py-1.5 pr-2 text-gray-900">{{ l.operator?.name ?? '—' }}</td>
                                            <td class="py-1.5 pr-2 text-right text-gray-900">{{ l.man_hours != null ? fmtQta(l.man_hours) : '—' }}</td>
                                            <td class="whitespace-nowrap py-1.5 pr-2 text-right text-gray-900">{{ l.quantity != null ? `${fmtQta(l.quantity)} ${l.unit ?? ''}` : '—' }}</td>
                                            <td class="py-1.5 text-[13px] text-gray-500">{{ l.notes ?? '' }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <p v-else class="mt-2 text-sm text-gray-600">Ancora vuoto: ore, quantità, materiali e mezzi si compilano dal campo con l'app operatore. Con il consuntivo si genera il rendiconto e, se serve, il SAL.</p>
                            <div v-if="ordine.consuntivo" class="mt-3 rounded-lg bg-gray-50 p-3 text-sm" data-test="ordine-economia">
                                <div class="flex justify-between"><span class="text-gray-600">Ore uomo totali</span><span class="font-semibold">{{ fmtQta(ordine.consuntivo.total_man_hours) }}</span></div>
                                <div v-for="(q, u) in ordine.consuntivo.quantities" :key="u" class="mt-1 flex justify-between"><span class="text-gray-600">Quantità eseguita ({{ u || 'senza unità' }})</span><span class="font-semibold">{{ fmtQta(q) }}</span></div>
                                <div v-if="ordine.consuntivo.valued && ! ordine.consuntivo.valued.ambiguous" class="mt-2 flex justify-between gap-3 border-t border-gray-200 pt-2"><span class="text-gray-600">Valore da listino ({{ fmtQta(ordine.consuntivo.valued.quantity ?? 0) }} {{ ordine.consuntivo.valued.unit }} × {{ fmtEuro(ordine.consuntivo.valued.unit_price) }}<template v-if="ordine.consuntivo.valued.overhead_pct"> + spese generali {{ fmtQta(ordine.consuntivo.valued.overhead_pct) }}%</template>)</span><span class="font-semibold" data-test="ordine-valore">{{ fmtEuro(ordine.consuntivo.valued.amount) }}</span></div>
                                <p v-else-if="ordine.consuntivo.valued?.ambiguous" class="mt-2 border-t border-gray-200 pt-2 text-xs text-amber-800">{{ ordine.consuntivo.valued.reason }}</p>
                                <p v-else-if="! ordine.price_list_id" class="mt-2 border-t border-gray-200 pt-2 text-xs text-gray-500">Nessun listino applicato: il valore economico non viene calcolato.</p>
                                <p v-else class="mt-2 border-t border-gray-200 pt-2 text-xs text-gray-500">Il listino non ha una voce per la lavorazione di questo ordine.</p>
                            </div>
                        </section>

                        <!-- Controlli qualita' -->
                        <section :class="CARTA" class="p-4" data-test="sezione-controlli">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <h2 class="text-base font-bold text-gray-900">Controlli qualità ({{ (ordine.checks ?? []).length }})</h2>
                                <button v-if="canManage && ['in_progress', 'suspended', 'completed'].includes(ordine.status)" type="button" :class="BOTTONE_PICCOLO" data-test="ordine-registra-controllo" @click="apriControllo">Registra controllo</button>
                            </div>
                            <ul v-if="(ordine.checks ?? []).length" class="mt-2 divide-y divide-gray-100">
                                <li v-for="c in ordine.checks" :key="c.id" class="py-2 text-sm">
                                    <div class="flex flex-wrap items-center justify-between gap-2"><span :class="c.outcome === 'passed' ? CHIP.ok : CHIP.errore">{{ c.outcome === 'passed' ? 'Positivo' : 'Negativo' }}</span><span class="text-[13px] text-gray-500">{{ c.checker?.name ?? '—' }} · {{ fmtOra(c.checked_at) }}</span></div>
                                    <p v-if="c.notes" class="mt-1 text-[13px] text-gray-700">{{ c.notes }}</p>
                                </li>
                            </ul>
                            <p v-else class="mt-2 text-sm text-gray-500">Nessun controllo registrato.</p>
                            <ul v-if="(ordine.non_conformities ?? []).length" class="mt-3 space-y-1.5" data-test="ordine-nc">
                                <li v-for="nc in ordine.non_conformities" :key="nc.id" class="rounded-lg border border-red-100 bg-red-50/50 px-3 py-2 text-sm">
                                    <div class="flex flex-wrap items-center justify-between gap-2"><span class="font-semibold">{{ nc.code }}</span><span class="text-[13px] text-gray-600">{{ NC_GRAVITA[nc.severity] ?? nc.severity }} · {{ NC_STATO[nc.status] ?? nc.status }}</span></div>
                                    <p class="mt-0.5 text-[13px] text-gray-700">{{ nc.description }}</p>
                                </li>
                            </ul>
                        </section>
                    </div>

                    <aside class="flex min-w-0 flex-col gap-4">
                        <section :class="CARTA" class="overflow-hidden" data-test="sezione-mappa-ordine">
                            <div v-if="ordine.assets.some((r) => r.asset?.geom_geojson)" ref="mapEl" class="h-64 w-full bg-[#e8ede9]" />
                            <p v-else class="px-4 pt-3 text-sm text-gray-500">Nessun elemento con posizione da mostrare.</p>
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2.5 text-[13px] text-gray-600">
                                <span><span class="inline-block h-2.5 w-2.5 rounded-full bg-green-700"></span> fatto</span>
                                <span><span class="inline-block h-2.5 w-2.5 rounded-full bg-amber-600"></span> da fare</span>
                                <Link href="/mappa" class="ml-auto min-h-9 py-2 font-semibold text-green-800 underline-offset-2 hover:underline">Apri la mappa</Link>
                            </div>
                        </section>

                        <section :class="CARTA" class="p-4" data-test="sezione-documenti">
                            <h2 class="text-base font-bold text-gray-900">Documenti collegati</h2>
                            <ul v-if="documenti.length" class="mt-1 divide-y divide-gray-100">
                                <li v-for="(d, i) in documenti" :key="i" class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2">
                                    <div class="min-w-0 flex-1 basis-40"><div class="font-semibold text-gray-900">{{ d.titolo }}</div><div class="text-[13px] text-gray-500">{{ [d.stato, d.data ? formatData(d.data) : null, d.importo != null ? fmtEuro(d.importo) : null].filter(Boolean).join(' · ') }}</div></div>
                                    <Link :href="d.href" :class="BOTTONE_PICCOLO">Apri</Link>
                                </li>
                            </ul>
                            <p v-else class="mt-1 text-[13px] text-gray-500">Nessun preventivo né SAL collegato. Il rendiconto si genera alla chiusura.</p>
                        </section>

                        <section :class="CARTA" class="p-4" data-test="sezione-cronologia-ordine">
                            <h2 class="text-base font-bold text-gray-900">Cronologia</h2>
                            <ul v-if="cronologia?.eventi?.length" class="mt-1 divide-y divide-gray-100">
                                <li v-for="(e, i) in cronologia.eventi" :key="i" class="flex gap-3 py-2">
                                    <span class="w-14 shrink-0 pt-0.5 text-xs text-gray-500">{{ formatData(e.data, false) }}</span>
                                    <div class="min-w-0 flex-1">
                                        <div class="text-sm font-semibold" :class="e.tipo === 'ritardo' ? 'text-red-800' : 'text-gray-900'">{{ e.titolo }}</div>
                                        <div class="text-[13px] text-gray-500">{{ TIPO_EVENTO[e.tipo] ?? e.tipo }}{{ e.dettaglio ? ` · ${e.dettaglio}` : '' }}</div>
                                        <div v-if="e.foto?.length" class="mt-1 flex flex-wrap gap-1">
                                            <a v-for="f in e.foto" :key="f.id" :href="f.url" target="_blank" rel="noopener" class="h-11 w-11 overflow-hidden rounded bg-gray-100"><img :src="f.url" alt="" class="h-full w-full object-cover" loading="lazy"></a>
                                        </div>
                                    </div>
                                </li>
                            </ul>
                            <p v-else class="mt-1 text-[13px] text-gray-500">Nessun evento registrato.</p>
                        </section>
                    </aside>
                </div>
            </template>
        </div>

        <!-- Verbale di controllo qualita' -->
        <Teleport to="body">
            <div v-if="controllo.aperto" class="fixed inset-0 z-50 flex items-end justify-center bg-black/30 sm:items-center sm:p-4" @click.self="controllo.aperto = false">
                <form class="max-h-full w-full max-w-md overflow-y-auto rounded-t-2xl bg-white p-4 shadow-2xl sm:rounded-2xl sm:p-6" data-test="controllo-modulo" @submit.prevent="registraControllo">
                    <div class="flex items-start justify-between gap-3"><h2 class="text-lg font-bold text-gray-900">Verbale di controllo qualità</h2><button type="button" class="min-h-11 min-w-11 text-gray-500" aria-label="Chiudi" @click="controllo.aperto = false">✕</button></div>
                    <div class="mt-3 flex flex-wrap gap-4 text-sm">
                        <label class="flex min-h-9 items-center gap-2"><input v-model="controllo.outcome" type="radio" value="passed" data-test="controllo-positivo"> Esito positivo</label>
                        <label class="flex min-h-9 items-center gap-2"><input v-model="controllo.outcome" type="radio" value="failed" data-test="controllo-negativo"> Esito negativo</label>
                    </div>
                    <label class="mt-3 block text-xs"><span class="text-gray-600">Note</span><textarea v-model="controllo.notes" rows="2" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm" /></label>
                    <div v-if="controllo.outcome === 'failed'" class="mt-3 space-y-2 rounded-lg border border-red-100 bg-red-50/40 p-3">
                        <label class="block text-xs"><span class="text-gray-600">Gravità</span><select v-model="controllo.nc.severity" class="mt-1 w-full rounded-lg border border-gray-300 bg-white px-2 py-2 text-sm"><option value="minor">Lieve</option><option value="major">Grave</option><option value="critical">Critica</option></select></label>
                        <label class="block text-xs"><span class="text-gray-600">Non conformità riscontrata *</span><textarea v-model="controllo.nc.description" rows="2" class="mt-1 w-full rounded-lg border border-gray-300 bg-white px-2.5 py-2 text-sm" data-test="controllo-nc" /></label>
                        <label class="block text-xs"><span class="text-gray-600">Da chiudere entro</span><input v-model="controllo.nc.due_on" type="date" class="mt-1 w-full rounded-lg border border-gray-300 bg-white px-2.5 py-2 text-sm"></label>
                        <label class="block text-xs"><span class="text-gray-600">Responsabile</span><select v-model="controllo.nc.responsible_id" class="mt-1 w-full rounded-lg border border-gray-300 bg-white px-2 py-2 text-sm"><option value="">—</option><option v-for="p in personnel" :key="p.id" :value="p.id">{{ p.name }}</option></select></label>
                        <label class="flex min-h-9 items-center gap-2 text-sm"><input v-model="controllo.nc.create_corrective_order" type="checkbox" class="rounded border-gray-300"> Crea subito l'ordine correttivo</label>
                    </div>
                    <p v-if="controllo.errore" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ controllo.errore }}</p>
                    <div class="mt-4 flex flex-wrap gap-2"><button type="submit" :class="BOTTONE" :disabled="controllo.busy" data-test="controllo-salva">{{ controllo.busy ? 'Registrazione…' : 'Registra il controllo' }}</button><button type="button" :class="BOTTONE_SECONDARIO" @click="controllo.aperto = false">Annulla</button></div>
                </form>
            </div>
        </Teleport>
    </AppLayout>
</template>
