<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import AvvisoErrore from '@/Components/AvvisoErrore.vue';
import ScegliCommittente from '@/Components/ScegliCommittente.vue';
import ScegliVoce from '@/Components/ScegliVoce.vue';
import VisteSalvate from '@/Components/VisteSalvate.vue';
import TestataPatrimonio from '@/Components/Nuovo/TestataPatrimonio.vue';
import { usaCaricamento } from '@/caricamento';
import { avvisoCaricamento } from '@/avvisi';
import { STATUS_LABELS, inArchivio, statusLabel } from '@/assetStatus';
import { BOTTONE, BOTTONE_PICCOLO, BOTTONE_SECONDARIO, CARTA, CHIP, plurale } from '@/nuovo/stile';

/*
 * Patrimonio, elenco con anteprima (bozza A, schermata 02). L'elenco a
 * sinistra con ricerca, filtri e le scorciatoie che contano (ricontrolli VTA
 * scaduti, schede senza specie); a destra l'anteprima della riga scelta con
 * fotografia, misure e cronologia, e i tre pulsanti che servono davvero.
 * Selezionando piu' righe compaiono le azioni in blocco, con la prova a vuoto
 * prima di scrivere (stessa regola di AzioniMultiple).
 */
const page = usePage();
const permessi = computed(() => page.props.auth?.user?.permissions ?? []);
const can = (permesso) => permessi.value.includes(permesso);
const agronomia = computed(() => page.props.agronomia ?? {});

const parametri = new URLSearchParams(window.location.search);
const { avviso, riprovaInCorso, carica, riprova } = usaCaricamento();

const righe = ref([]);
const meta = reactive({ total: 0, current_page: 1, last_page: 1, from: 0, to: 0 });
const caricamento = ref(false);
const riepilogo = ref(null);

const VTA_AMMESSI = ['scaduta', 'in_scadenza', 'mai', 'valutato'];
const filtri = reactive({
    q: parametri.get('q') ?? '',
    clientId: '',
    areaId: '',
    tipoId: '',
    status: '',
    vista: 'censimento',
    vta: VTA_AMMESSI.includes(parametri.get('vta')) ? parametri.get('vta') : '',
    senzaSpecie: parametri.get('senza_specie') === '1',
    page: 1,
});

const clients = ref([]);
const areas = ref([]);
const tipi = ref([]);

// Lo stato a tendina: "in gestione" (tutto tranne l'archivio), uno stato
// preciso, oppure l'archivio intero. Vista e stato restano quelli delle viste
// salvate della pagina precedente, cosi' le viste condivise valgono in
// tutte e due
const STATI = [
    { valore: 'gestione', etichetta: 'In gestione' },
    ...Object.entries(STATUS_LABELS).filter(([v]) => ! inArchivio(v)).map(([valore, etichetta]) => ({ valore, etichetta })),
    { valore: 'archivio', etichetta: 'Archivio: abbattuti e dismessi' },
    ...Object.entries(STATUS_LABELS).filter(([v]) => inArchivio(v)).map(([valore, etichetta]) => ({ valore, etichetta: `Archivio: ${etichetta.toLowerCase()}` })),
];
const statoScelto = computed({
    get: () => filtri.status || (filtri.vista === 'archivio' ? 'archivio' : 'gestione'),
    set: (v) => {
        if (v === 'gestione') {
            filtri.status = '';
            filtri.vista = 'censimento';
        } else if (v === 'archivio') {
            filtri.status = '';
            filtri.vista = 'archivio';
        } else {
            filtri.status = v;
            filtri.vista = inArchivio(v) ? 'archivio' : 'censimento';
        }
    },
});

const parametriElenco = computed(() => ({
    q: filtri.q || undefined,
    client_id: filtri.clientId || undefined,
    area_id: filtri.areaId || undefined,
    object_type_id: filtri.tipoId || undefined,
    status: filtri.status || undefined,
    archivio: filtri.vista === 'archivio' ? 1 : 0,
}));
const parametriCompleti = computed(() => ({
    ...parametriElenco.value,
    vta: filtri.vta || undefined,
    senza_specie: filtri.senzaSpecie ? 1 : undefined,
}));

async function tutteLePagine(indirizzo, params = {}) {
    const tutte = [];
    for (let p = 1; p <= 20; p++) {
        const { data } = await axios.get(indirizzo, { params: { ...params, per_page: 100, page: p } });
        tutte.push(...data.data);
        if (! data.next_page_url) break;
    }

    return tutte;
}

async function caricaCommittenti() {
    if (! can('clients.view')) return;
    clients.value = await tutteLePagine('/api/v1/clients');
}

async function caricaAree() {
    areas.value = await tutteLePagine('/api/v1/areas', { client_id: filtri.clientId || undefined });
    if (filtri.areaId && ! areas.value.some((a) => a.id === filtri.areaId)) filtri.areaId = '';
}

async function caricaTipi() {
    if (! can('catalog.view')) return;
    try {
        const { data } = await axios.get('/api/v1/catalog');
        tipi.value = (data.data ?? []).flatMap((m) => (m.sub_types ?? [])
            .flatMap((s) => (s.object_types ?? []).map((t) => ({ id: t.id, name: `${t.code} · ${t.name}` }))));
    } catch {
        tipi.value = [];
    }
}

async function caricaElenco() {
    caricamento.value = true;
    try {
        const { data } = await axios.get('/api/v1/assets', {
            params: { ...parametriCompleti.value, dettagli: 1, ordina: 'cartellino', per_page: 50, page: filtri.page },
        });
        righe.value = data.data;
        Object.assign(meta, {
            total: data.total, current_page: data.current_page, last_page: data.last_page, from: data.from ?? 0, to: data.to ?? 0,
        });
        selezionati.value = [];
    } finally {
        caricamento.value = false;
    }
}

// Le scorciatoie si contano senza il proprio filtro: cosi' dicono sempre
// quante ce ne sono, anche mentre una e' accesa
async function caricaRiepilogo() {
    try {
        const { data } = await axios.get('/api/v1/assets/riepilogo', { params: parametriElenco.value });
        riepilogo.value = data.data;
    } catch {
        riepilogo.value = null;
    }
}

let attesa = null;
watch(() => [filtri.q, filtri.clientId, filtri.areaId, filtri.tipoId, filtri.status, filtri.vista, filtri.vta, filtri.senzaSpecie], () => {
    clearTimeout(attesa);
    attesa = setTimeout(() => {
        filtri.page = 1;
        carica(() => Promise.all([caricaElenco(), caricaRiepilogo()]));
    }, 300);
});
watch(() => filtri.clientId, caricaAree);
watch(() => filtri.page, () => carica(caricaElenco));

onMounted(() => carica(() => Promise.all([caricaElenco(), caricaRiepilogo(), caricaCommittenti(), caricaAree(), caricaTipi()])));

// --- Viste salvate (stessa pagina e stessa forma della veste precedente) ---
const filtriCorrenti = computed(() => ({
    q: filtri.q, status: filtri.status, clientId: filtri.clientId, areaId: filtri.areaId, vista: filtri.vista,
    tipoId: filtri.tipoId, vta: filtri.vta, senzaSpecie: filtri.senzaSpecie,
}));

function applicaVista(f) {
    filtri.q = f.q ?? '';
    filtri.status = f.status ?? '';
    filtri.clientId = f.clientId ?? '';
    filtri.areaId = f.areaId ?? '';
    filtri.tipoId = f.tipoId ?? '';
    filtri.vta = VTA_AMMESSI.includes(f.vta) ? f.vta : '';
    filtri.senzaSpecie = Boolean(f.senzaSpecie);
    // Le viste salvate prima dell'archivio portano ancora showRemoved o uno
    // stato d'archivio: tutte e due le intenzioni oggi vivono nella vista Archivio
    filtri.vista = f.vista === 'archivio' || (! f.vista && (f.showRemoved || inArchivio(f.status))) ? 'archivio' : 'censimento';
}

function azzeraFiltri() {
    Object.assign(filtri, { q: '', clientId: '', areaId: '', tipoId: '', status: '', vista: 'censimento', vta: '', senzaSpecie: false });
}

const filtriAttivi = computed(() => [filtri.q, filtri.clientId, filtri.areaId, filtri.tipoId, filtri.status, filtri.vta].filter(Boolean).length
    + (filtri.senzaSpecie ? 1 : 0) + (filtri.vista === 'archivio' ? 1 : 0));

// --- Righe -------------------------------------------------------------------
function formatData(valore) {
    if (! valore) return '—';
    const [a, m, g] = String(valore).slice(0, 10).split('-');

    return `${g}/${m}/${a}`;
}

function meseAnno(valore) {
    const [a, m] = String(valore).slice(0, 10).split('-');

    return `${m}/${a}`;
}

const oggiIso = new Date().toISOString().slice(0, 10);

function specie(r) {
    if (! r.tree) return r.object_type?.name ?? '—';
    const nomi = [r.tree.species, r.tree.common_name].filter(Boolean).join(' · ');

    return nomi || 'specie da indicare';
}

function vta(r) {
    if (! r.tree || r.tree.removed_on) return null;
    if (! r.vta_data && ! r.vta_classe) return { testo: 'mai valutato', tono: 'text-gray-500' };
    const classe = r.vta_classe ?? 'n.d.';
    if (! r.vta_scadenza) return { testo: `${classe} · senza ricontrollo`, tono: 'text-gray-600' };
    if (String(r.vta_scadenza).slice(0, 10) < oggiIso) return { testo: `${classe} · scaduta ${formatData(r.vta_scadenza)}`, tono: 'font-semibold text-red-800' };

    return { testo: `${classe} · ${meseAnno(r.vta_scadenza)}`, tono: 'text-gray-700' };
}

const committenteDi = (r) => r.area?.locality?.site?.client?.name ?? '';

// --- Selezione e azioni in blocco -------------------------------------------
const selezionati = ref([]);
const tuttiSelezionati = computed(() => righe.value.length > 0 && selezionati.value.length === righe.value.length);
const selezionabile = computed(() => can('assets.update') && filtri.vista !== 'archivio');

function commutaTutti() {
    selezionati.value = tuttiSelezionati.value ? [] : righe.value.map((r) => r.id);
}

const modificaAperta = ref(false);
const azioneInCorso = ref(false);
const esitoMultiplo = ref('');
const saltatiMultiplo = ref([]);
const anteprimaMultipla = ref(null);
const dataRilievoMultipla = ref('');
const ordini = ref([]);
const ordineScelto = ref('');

async function apriModifica() {
    modificaAperta.value = ! modificaAperta.value;
    if (modificaAperta.value && ! ordini.value.length) {
        try {
            const { data } = await axios.get('/api/v1/work-orders', { params: { per_page: 100 } });
            ordini.value = (data.data ?? []).filter((o) => ! ['completed', 'cancelled'].includes(o.status));
        } catch {
            ordini.value = [];
        }
    }
}
const ordiniVoci = computed(() => ordini.value.map((o) => ({ id: o.id, name: `${o.code} · ${o.title}`, code: o.code, title: o.title })));

/*
 * Ogni azione in blocco passa da una prova a vuoto: prima di toccare i dati
 * si legge "N verranno elaborati, M esclusi perche'...". La conferma esegue la
 * stessa chiamata senza la prova.
 */
function messaggioErrore(err) {
    return Object.values(err.response?.data?.errors ?? {})[0]?.[0] ?? err.response?.data?.message ?? 'Errore nell\'operazione.';
}

async function azioneMultipla(chiamata, riuscita, contaFatti) {
    azioneInCorso.value = true;
    esitoMultiplo.value = '';
    saltatiMultiplo.value = [];
    try {
        const { data } = await chiamata(true);
        anteprimaMultipla.value = {
            fatti: contaFatti(data.data),
            saltati: data.data.saltati ?? [],
            conferma: async () => {
                azioneInCorso.value = true;
                esitoMultiplo.value = '';
                try {
                    const esito = await chiamata(false);
                    saltatiMultiplo.value = esito.data.data.saltati ?? [];
                    esitoMultiplo.value = riuscita(esito.data.data);
                    anteprimaMultipla.value = null;
                    await carica(() => Promise.all([caricaElenco(), caricaRiepilogo()]));
                } catch (err) {
                    esitoMultiplo.value = messaggioErrore(err);
                } finally {
                    azioneInCorso.value = false;
                }
            },
        };
    } catch (err) {
        esitoMultiplo.value = messaggioErrore(err);
    } finally {
        azioneInCorso.value = false;
    }
}

watch(selezionati, () => { anteprimaMultipla.value = null; });

const nascondiSelezionati = (nascosto) => azioneMultipla(
    (prova) => axios.post('/api/v1/azioni/modifica-elementi', { ids: selezionati.value, public_hidden: nascosto, prova: prova ? 1 : 0 }),
    (d) => `${d.modificati.length} element${d.modificati.length === 1 ? 'o' : 'i'} ${nascosto ? 'nascost' : 'rimess'}${d.modificati.length === 1 ? 'o' : 'i'} ${nascosto ? '' : 'in vista '}sul portale.`,
    (d) => d.modificati.length,
);

const applicaDataRilievo = () => azioneMultipla(
    (prova) => axios.post('/api/v1/azioni/modifica-elementi', { ids: selezionati.value, surveyed_at: dataRilievoMultipla.value || null, prova: prova ? 1 : 0 }),
    (d) => `Data di rilievo aggiornata su ${d.modificati.length}.`,
    (d) => d.modificati.length,
);

const collegaAOrdine = () => azioneMultipla(
    (prova) => axios.post(`/api/v1/azioni/lavori/${ordineScelto.value}/collega-elementi`, { ids: selezionati.value, prova: prova ? 1 : 0 }),
    (d) => `${d.collegati.length} collegat${d.collegati.length === 1 ? 'o' : 'i'} all'ordine di lavoro.`,
    (d) => d.collegati.length,
);

// Specie e misure su piu' alberi: si sceglie campo per campo e di serie si
// riempiono solo i vuoti, cosi' completare un censimento importato non
// cancella il lavoro di nessuno
const CAMPI_SCHEDA = [
    { chiave: 'genus', etichetta: 'Genere', tipo: 'text' },
    { chiave: 'species', etichetta: 'Specie', tipo: 'text' },
    { chiave: 'cultivar', etichetta: 'Cultivar', tipo: 'text' },
    { chiave: 'common_name', etichetta: 'Nome comune', tipo: 'text' },
    { chiave: 'height_m', etichetta: 'Altezza (m)', tipo: 'number' },
    { chiave: 'dbh_cm', etichetta: 'Diametro del tronco (cm)', tipo: 'number' },
    { chiave: 'trunk_circumference_cm', etichetta: 'Circonferenza del tronco (cm)', tipo: 'number' },
    { chiave: 'crown_diameter_m', etichetta: 'Diametro della chioma (m)', tipo: 'number' },
    { chiave: 'crown_insertion_m', etichetta: 'Inserzione della chioma (m)', tipo: 'number' },
    { chiave: 'trunk_count', etichetta: 'Numero di fusti', tipo: 'number' },
    { chiave: 'age_years_est', etichetta: 'Età stimata (anni)', tipo: 'number' },
    { chiave: 'age_qualifier', etichetta: "Qualificatore dell'età", tipo: 'select', voci: 'qualificatore_eta' },
    { chiave: 'age_class', etichetta: 'Fase fisiologica', tipo: 'select', voci: 'fase_fisiologica' },
    { chiave: 'vegetative_state', etichetta: 'Stato vegetativo', tipo: 'select', voci: 'stato_vegetativo' },
    { chiave: 'social_position', etichetta: 'Posizione sociale', tipo: 'select', voci: 'posizione_sociale' },
    { chiave: 'growth_site', etichetta: 'Sito di crescita', tipo: 'select', voci: 'sito_di_crescita' },
    { chiave: 'target', etichetta: 'Bersaglio', tipo: 'select', voci: 'bersaglio' },
];
const scheda = reactive({ aperta: false, soloVuoti: true, scelti: {}, valori: {} });
const campiScelti = computed(() => Object.fromEntries(
    CAMPI_SCHEDA.filter((c) => scheda.scelti[c.chiave])
        .map((c) => [c.chiave, scheda.valori[c.chiave] === '' || scheda.valori[c.chiave] === undefined ? null : scheda.valori[c.chiave]]),
));
const applicaScheda = () => azioneMultipla(
    (prova) => axios.post('/api/v1/azioni/alberi', { ids: selezionati.value, campi: campiScelti.value, solo_vuoti: scheda.soloVuoti ? 1 : 0, prova: prova ? 1 : 0 }),
    (d) => `Scheda aggiornata su ${d.modificati.length} alber${d.modificati.length === 1 ? 'o' : 'i'}.`,
    (d) => d.modificati.length,
);

// --- Esportazioni -----------------------------------------------------------
const CAM_LAYERS = {
    P1: 'P1 Vegetazione (punti: alberi)',
    L1: 'L1 Vegetazione (linee: siepi, filari)',
    S1: 'S1 Vegetazione (superfici: prati, aiuole)',
    P2: 'P2 Arredo urbano (punti)',
    L2: 'L2 Arredo urbano (linee)',
    S2: 'S2 Arredo urbano (superfici)',
    P3: 'P3 Fruizione e gestione (punti)',
    L3: 'L3 Fruizione e gestione (linee)',
    S3: 'S3 Fruizione e gestione (superfici e perimetri aree)',
    S4: 'S4 Fattori ambientali (superfici)',
};
const exportLayer = ref('P1');
const esportazione = reactive({ busy: false, error: '', deliveryFallback: false });

async function scarica(url, nomeBase, estensione) {
    esportazione.busy = true;
    esportazione.error = '';
    try {
        const r = await fetch(url, { headers: { Accept: 'application/json' } });
        if (! r.ok) {
            let messaggio = 'Esportazione non riuscita.';
            try {
                const corpo = await r.json();
                messaggio = Object.values(corpo.errors ?? {})[0]?.[0] ?? corpo.message ?? messaggio;
            } catch { /* risposta non JSON */ }
            esportazione.error = `${messaggio} (errore ${r.status})`;

            return false;
        }
        const blob = await r.blob();
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        const giorno = new Date().toISOString().slice(0, 10).replaceAll('-', '');
        const servito = r.headers.get('Content-Disposition')?.match(/filename="?([^";]+)"?/)?.[1];
        link.download = servito ?? `${nomeBase}_${giorno}.${estensione}`;
        link.click();
        URL.revokeObjectURL(link.href);

        return true;
    } catch {
        esportazione.error = 'Esportazione non riuscita: problema di rete.';

        return false;
    } finally {
        esportazione.busy = false;
    }
}

// L'elenco che si sta guardando: stessi filtri, stesse colonne
async function esportaElenco(formato) {
    const params = new URLSearchParams();
    Object.entries(parametriCompleti.value).forEach(([k, v]) => { if (v !== undefined) params.set(k, String(v)); });
    await scarica(`/api/v1/exports/assets.${formato}?${params.toString()}`, 'censimento', formato);
}

const esportaCam = (formato) => scarica(`/api/v1/exports/cam?layer=${exportLayer.value}&format=${formato}`, `cam_${exportLayer.value}`, formato === 'shapefile' ? 'zip' : 'geojson');

async function esportaConsegna(formato = 'shapefile') {
    esportazione.deliveryFallback = false;
    const ok = await scarica(`/api/v1/exports/cam/delivery?format=${formato}`, 'consegna_cam', 'zip');
    if (! ok && formato === 'shapefile') esportazione.deliveryFallback = true;
}

// --- Anteprima --------------------------------------------------------------
const anteprima = reactive({ id: null, dettaglio: null, cronologia: null, caricamento: false, errore: '' });

async function apriAnteprima(id) {
    if (anteprima.id === id) return;
    Object.assign(anteprima, { id, dettaglio: null, cronologia: null, errore: '', caricamento: true });
    try {
        const [d, c] = await Promise.all([axios.get(`/api/v1/assets/${id}`), axios.get(`/api/v1/assets/${id}/cronologia`)]);
        if (anteprima.id !== id) return;
        anteprima.dettaglio = d.data.data;
        anteprima.cronologia = c.data.data;
    } catch (err) {
        if (anteprima.id === id) anteprima.errore = avvisoCaricamento(err);
    } finally {
        if (anteprima.id === id) anteprima.caricamento = false;
    }
}

function chiudiAnteprima() {
    Object.assign(anteprima, { id: null, dettaglio: null, cronologia: null, errore: '' });
}

const fotoAnteprima = computed(() => anteprima.dettaglio?.photos?.[0] ?? null);
const misure = computed(() => {
    const t = anteprima.dettaglio?.tree;
    if (! t) return [];
    const n = (v, unita, decimali = 1) => (v === null || v === undefined ? '—' : `${Number(v).toLocaleString('it-IT', { maximumFractionDigits: decimali })} ${unita}`);
    const ultima = anteprima.cronologia?.eventi?.find((e) => e.tipo === 'valutazione');

    return [
        ['Altezza', n(t.height_m, 'm')],
        ['Diametro fusto', n(t.dbh_cm, 'cm', 0)],
        ['Chioma', n(t.crown_diameter_m, 'm')],
        ['Stabilità', ultima ? ultima.titolo.replace('Valutazione VTA · ', '') : 'mai valutato'],
    ];
});
const TIPO_EVENTO = {
    rilievo: 'Rilievo', modifica: 'Modifica', valutazione: 'VTA', lavoro: 'Lavoro', segnalazione: 'Segnalazione',
    foto: 'Foto', abbattimento: 'Abbattimento',
};
</script>

<template>
    <Head title="Patrimonio" />

    <AppLayout>
        <div class="mx-auto flex max-w-[1640px] flex-col gap-4 p-4 md:p-6 lg:px-7">
            <TestataPatrimonio attiva="elenco">
                <Link v-if="can('assets.create')" href="/mappa" :class="BOTTONE">Nuovo elemento</Link>
                <details class="relative">
                    <summary :class="BOTTONE_SECONDARIO" class="cursor-pointer list-none">Esporta</summary>
                    <div :class="CARTA" class="absolute right-0 z-20 mt-1 w-80 p-3 text-sm shadow-lg" data-test="menu-esporta">
                        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">L'elenco filtrato</div>
                        <div class="mt-1 flex flex-wrap gap-2">
                            <button type="button" :class="BOTTONE_PICCOLO" :disabled="esportazione.busy" data-test="esporta-csv" @click="esportaElenco('csv')">CSV</button>
                            <button type="button" :class="BOTTONE_PICCOLO" :disabled="esportazione.busy" data-test="esporta-xlsx" @click="esportaElenco('xlsx')">Excel</button>
                        </div>
                        <div class="mt-3 text-xs font-semibold uppercase tracking-wide text-gray-500">Modello Dati CAM</div>
                        <select v-model="exportLayer" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm" aria-label="Livello CAM">
                            <option v-for="(nome, codice) in CAM_LAYERS" :key="codice" :value="codice">{{ nome }}</option>
                        </select>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <button type="button" :class="BOTTONE_PICCOLO" :disabled="esportazione.busy" @click="esportaCam('geojson')">GeoJSON</button>
                            <button type="button" :class="BOTTONE_PICCOLO" :disabled="esportazione.busy" @click="esportaCam('shapefile')">Shapefile</button>
                            <button type="button" :class="BOTTONE_PICCOLO" :disabled="esportazione.busy" @click="esportaConsegna()">Consegna CAM completa</button>
                        </div>
                        <p v-if="esportazione.deliveryFallback" class="mt-2 text-xs text-amber-800">
                            Lo shapefile non è disponibile sul server:
                            <button type="button" class="font-semibold underline" @click="esportaConsegna('geojson')">scarica la consegna in GeoJSON</button>.
                        </p>
                        <p v-if="esportazione.error" class="mt-2 text-xs text-red-700" data-test="esporta-errore">{{ esportazione.error }}</p>
                    </div>
                </details>
                <details class="relative">
                    <summary :class="BOTTONE_SECONDARIO" class="cursor-pointer list-none">Altro</summary>
                    <div :class="CARTA" class="absolute right-0 z-20 mt-1 w-72 p-2 text-sm shadow-lg">
                        <Link v-if="can('assets.create')" href="/censimento?importa=1" class="flex min-h-11 items-center rounded-lg px-3 hover:bg-gray-50 md:min-h-9">Importa da file (CSV, Excel, GeoJSON, shapefile)</Link>
                        <Link href="/censimento" class="flex min-h-11 items-center rounded-lg px-3 hover:bg-gray-50 md:min-h-9">Elenco della veste precedente</Link>
                    </div>
                </details>
            </TestataPatrimonio>

            <AvvisoErrore :messaggio="avviso" :in-corso="riprovaInCorso" @riprova="riprova" />

            <!-- Ricerca e filtri -->
            <div class="flex flex-col gap-2">
                <div class="flex flex-wrap items-center gap-2">
                    <label class="relative min-w-0 flex-1 basis-72">
                        <span class="sr-only">Cerca</span>
                        <input
                            v-model="filtri.q"
                            type="search"
                            class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm placeholder:text-gray-400 focus:border-green-700 focus:outline-none focus:ring-1 focus:ring-green-700"
                            placeholder="Cerca: cartellino, specie, area, committente"
                            data-test="patrimonio-ricerca"
                        >
                    </label>
                    <ScegliCommittente
                        v-if="can('clients.view')"
                        v-model="filtri.clientId"
                        class="w-full sm:w-56"
                        :committenti="clients"
                        tutti="Committente: tutti"
                        data-test="patrimonio-committente"
                    />
                    <ScegliVoce
                        v-model="filtri.areaId"
                        class="w-full sm:w-56"
                        :voci="areas"
                        :campi-ricerca="['name', 'code']"
                        tutti="Area: tutte"
                        vuoto="Nessuna area trovata."
                        data-test="patrimonio-area"
                    />
                    <ScegliVoce
                        v-if="tipi.length"
                        v-model="filtri.tipoId"
                        class="w-full sm:w-56"
                        :voci="tipi"
                        tutti="Tipo: tutti"
                        vuoto="Nessun tipo trovato."
                        data-test="patrimonio-tipo"
                    />
                    <select v-model="statoScelto" class="w-full rounded-lg border border-gray-300 bg-white px-2.5 py-2 text-sm sm:w-auto" aria-label="Stato" data-test="patrimonio-stato">
                        <option v-for="s in STATI" :key="s.valore" :value="s.valore">{{ s.valore === 'gestione' || s.valore === 'archivio' ? s.etichetta : `Stato: ${s.etichetta.toLowerCase()}` }}</option>
                    </select>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button
                        v-if="riepilogo"
                        type="button"
                        class="inline-flex min-h-9 items-center gap-1.5 rounded-full border px-3 text-[13px] font-semibold transition"
                        :class="filtri.vta === 'scaduta' ? 'border-red-800 bg-red-800 text-white' : 'border-gray-300 bg-white text-gray-700 hover:border-red-300'"
                        :aria-pressed="filtri.vta === 'scaduta'"
                        data-test="scorciatoia-vta"
                        @click="filtri.vta = filtri.vta === 'scaduta' ? '' : 'scaduta'"
                    >VTA scaduta <span :class="filtri.vta === 'scaduta' ? '' : 'text-red-800'">{{ riepilogo.vta_scadute }}</span></button>
                    <button
                        v-if="riepilogo"
                        type="button"
                        class="inline-flex min-h-9 items-center gap-1.5 rounded-full border px-3 text-[13px] font-semibold transition"
                        :class="filtri.vta === 'mai' ? 'border-green-800 bg-green-800 text-white' : 'border-gray-300 bg-white text-gray-700 hover:border-gray-400'"
                        :aria-pressed="filtri.vta === 'mai'"
                        data-test="scorciatoia-mai"
                        @click="filtri.vta = filtri.vta === 'mai' ? '' : 'mai'"
                    >Mai valutati <span>{{ riepilogo.vta_mai }}</span></button>
                    <button
                        v-if="riepilogo"
                        type="button"
                        class="inline-flex min-h-9 items-center gap-1.5 rounded-full border px-3 text-[13px] font-semibold transition"
                        :class="filtri.senzaSpecie ? 'border-green-800 bg-green-800 text-white' : 'border-gray-300 bg-white text-gray-700 hover:border-gray-400'"
                        :aria-pressed="filtri.senzaSpecie"
                        data-test="scorciatoia-specie"
                        @click="filtri.senzaSpecie = ! filtri.senzaSpecie"
                    >Senza specie <span>{{ riepilogo.senza_specie }}</span></button>
                    <button v-if="filtriAttivi" type="button" class="min-h-9 px-2 text-[13px] font-medium text-gray-600 underline-offset-2 hover:underline" data-test="azzera-filtri" @click="azzeraFiltri">Togli i filtri ({{ filtriAttivi }})</button>
                    <div class="ml-auto">
                        <VisteSalvate pagina="censimento" :filtri="filtriCorrenti" @applica="applicaVista" />
                    </div>
                </div>
            </div>

            <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_380px] lg:items-start">
                <!-- Elenco -->
                <section :class="CARTA" class="min-w-0" data-test="patrimonio-elenco">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-gray-200 px-4 py-2.5 text-sm">
                        <span class="font-semibold text-gray-900" data-test="patrimonio-contatore">
                            {{ meta.total.toLocaleString('it-IT') }} {{ plurale(meta.total, 'elemento', 'elementi') }}<template v-if="riepilogo && riepilogo.alberi !== meta.total"> · {{ riepilogo.alberi }} {{ plurale(riepilogo.alberi, 'albero', 'alberi') }}</template>
                        </span>
                        <template v-if="selezionati.length">
                            <span class="text-gray-600">· {{ selezionati.length }} {{ plurale(selezionati.length, 'selezionato', 'selezionati') }}</span>
                            <Link v-if="can('works.manage')" :href="`/lavori?nuovo=1&elementi=${selezionati.join(',')}`" :class="BOTTONE_PICCOLO" data-test="crea-lavoro-selezionati">Crea un lavoro con i selezionati</Link>
                            <button v-if="selezionabile" type="button" :class="BOTTONE_PICCOLO" :aria-expanded="modificaAperta" data-test="modifica-insieme" @click="apriModifica">Modifica insieme</button>
                        </template>
                        <span v-if="caricamento" class="ml-auto text-xs text-gray-500">Aggiorno…</span>
                    </div>

                    <!-- Azioni in blocco -->
                    <div v-if="modificaAperta && selezionati.length" class="space-y-3 border-b border-gray-200 bg-gray-50 px-4 py-3 text-sm" data-test="pannello-modifica">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-gray-600">Portale pubblico:</span>
                            <button type="button" :class="BOTTONE_PICCOLO" :disabled="azioneInCorso" data-test="multipla-nascondi" @click="nascondiSelezionati(true)">Nascondi</button>
                            <button type="button" :class="BOTTONE_PICCOLO" :disabled="azioneInCorso" @click="nascondiSelezionati(false)">Rimetti in vista</button>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <label class="text-gray-600" for="data-rilievo-multipla">Data di rilievo:</label>
                            <input id="data-rilievo-multipla" v-model="dataRilievoMultipla" type="date" class="rounded-lg border border-gray-300 px-2 py-1.5 text-sm" data-test="multipla-data">
                            <button type="button" :class="BOTTONE_PICCOLO" :disabled="azioneInCorso || ! dataRilievoMultipla" @click="applicaDataRilievo">Applica</button>
                        </div>
                        <div v-if="can('works.manage')" class="flex flex-wrap items-center gap-2">
                            <span class="text-gray-600">Collega a un ordine aperto:</span>
                            <ScegliVoce v-model="ordineScelto" class="w-full sm:w-80" :voci="ordiniVoci" :campi-ricerca="['code', 'title']" segnaposto="Scegli l'ordine…" vuoto="Nessun ordine aperto." />
                            <button type="button" :class="BOTTONE_PICCOLO" :disabled="azioneInCorso || ! ordineScelto" data-test="multipla-collega" @click="collegaAOrdine">Collega</button>
                        </div>
                        <div>
                            <button type="button" class="text-[13px] font-semibold text-green-800 underline-offset-2 hover:underline" :aria-expanded="scheda.aperta" data-test="multipla-scheda" @click="scheda.aperta = ! scheda.aperta">
                                {{ scheda.aperta ? 'Chiudi specie e misure' : 'Specie e misure degli alberi selezionati' }}
                            </button>
                            <div v-if="scheda.aperta" class="mt-2 rounded-lg border border-gray-200 bg-white p-3" data-test="scheda-multipla">
                                <label class="flex items-center gap-2 text-[13px] text-gray-700">
                                    <input v-model="scheda.soloVuoti" type="checkbox" class="rounded border-gray-300" data-test="scheda-solo-vuoti">
                                    Scrivi solo dove il campo è vuoto (consigliato)
                                </label>
                                <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                    <label v-for="c in CAMPI_SCHEDA" :key="c.chiave" class="flex items-center gap-2 text-[13px]">
                                        <input v-model="scheda.scelti[c.chiave]" type="checkbox" class="rounded border-gray-300" :data-test="`campo-${c.chiave}`">
                                        <span class="w-44 shrink-0 text-gray-700">{{ c.etichetta }}</span>
                                        <select v-if="c.tipo === 'select'" v-model="scheda.valori[c.chiave]" class="min-w-0 flex-1 rounded-lg border border-gray-300 px-2 py-1 text-sm" :disabled="! scheda.scelti[c.chiave]">
                                            <option value="">—</option>
                                            <option v-for="v in (agronomia[c.voci] ?? [])" :key="v" :value="v">{{ v }}</option>
                                        </select>
                                        <input v-else v-model="scheda.valori[c.chiave]" :type="c.tipo" step="any" class="min-w-0 flex-1 rounded-lg border border-gray-300 px-2 py-1 text-sm" :disabled="! scheda.scelti[c.chiave]">
                                    </label>
                                </div>
                                <button type="button" :class="BOTTONE_PICCOLO" class="mt-2" :disabled="azioneInCorso || ! Object.keys(campiScelti).length" data-test="scheda-anteprima" @click="applicaScheda">Anteprima delle modifiche</button>
                            </div>
                        </div>
                        <div v-if="anteprimaMultipla" class="flex flex-wrap items-center gap-2 rounded-lg border border-green-200 bg-green-50 px-3 py-2" data-test="multipla-anteprima">
                            <span class="text-gray-800">{{ anteprimaMultipla.fatti }} {{ plurale(anteprimaMultipla.fatti, 'verrà elaborato', 'verranno elaborati') }}<template v-if="anteprimaMultipla.saltati.length">, {{ anteprimaMultipla.saltati.length }} {{ plurale(anteprimaMultipla.saltati.length, 'escluso', 'esclusi') }}</template>.</span>
                            <button type="button" :class="BOTTONE_PICCOLO" :disabled="azioneInCorso || ! anteprimaMultipla.fatti" data-test="multipla-conferma" @click="anteprimaMultipla.conferma">Conferma</button>
                            <button type="button" class="text-[13px] text-gray-600 underline-offset-2 hover:underline" @click="anteprimaMultipla = null">Annulla</button>
                            <ul v-if="anteprimaMultipla.saltati.length" class="w-full text-xs text-gray-600" data-test="multipla-anteprima-esclusi">
                                <li v-for="(s, i) in anteprimaMultipla.saltati.slice(0, 5)" :key="i">{{ s.census_code ?? s.id }}: {{ s.motivo }}</li>
                            </ul>
                        </div>
                        <p v-if="esitoMultiplo" class="rounded-lg bg-white px-3 py-2 text-[13px] text-gray-800" data-test="multipla-esito">{{ esitoMultiplo }}</p>
                        <ul v-if="saltatiMultiplo.length" class="text-xs text-amber-900" data-test="multipla-saltati">
                            <li v-for="(s, i) in saltatiMultiplo.slice(0, 5)" :key="i">{{ s.census_code ?? s.id }}: {{ s.motivo }}</li>
                        </ul>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                    <th v-if="selezionabile" class="w-10 px-3 py-2.5">
                                        <input type="checkbox" class="rounded border-gray-300" :checked="tuttiSelezionati" aria-label="Seleziona tutti" data-test="seleziona-tutti" @change="commutaTutti">
                                    </th>
                                    <th class="px-3 py-2.5">Cartellino</th>
                                    <th class="px-3 py-2.5">Specie</th>
                                    <th class="px-3 py-2.5">Area</th>
                                    <th class="px-3 py-2.5">Stato</th>
                                    <th class="px-3 py-2.5">VTA</th>
                                    <th class="px-3 py-2.5">Ultimo lavoro</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr
                                    v-for="r in righe"
                                    :key="r.id"
                                    class="cursor-pointer transition hover:bg-gray-50"
                                    :class="anteprima.id === r.id ? 'bg-green-50' : ''"
                                    data-test="patrimonio-riga"
                                    @click="apriAnteprima(r.id)"
                                >
                                    <td v-if="selezionabile" class="px-3 py-2.5" @click.stop>
                                        <input v-model="selezionati" type="checkbox" :value="r.id" class="rounded border-gray-300" :aria-label="`Seleziona ${r.census_code ?? 'elemento'}`" data-test="elemento-casella">
                                    </td>
                                    <td class="px-3 py-2.5">
                                        <button type="button" class="font-semibold text-gray-900 underline-offset-2 hover:underline" :aria-pressed="anteprima.id === r.id" @click.stop="apriAnteprima(r.id)">{{ r.census_code ?? 'senza cartellino' }}</button>
                                        <div class="text-xs text-gray-500">{{ r.object_type?.code }}</div>
                                    </td>
                                    <td class="px-3 py-2.5" :class="r.tree && ! r.tree.species ? 'text-amber-800' : 'text-gray-900'">{{ specie(r) }}</td>
                                    <td class="px-3 py-2.5 text-gray-700">
                                        {{ r.area?.name ?? '—' }}
                                        <div v-if="committenteDi(r)" class="text-xs text-gray-500">{{ committenteDi(r) }}</div>
                                    </td>
                                    <td class="px-3 py-2.5">
                                        <span :class="inArchivio(r.status) ? CHIP.neutra : (r.status === 'active' ? CHIP.ok : CHIP.attenzione)">{{ statusLabel(r.status) }}</span>
                                        <span v-if="r.public_hidden" :class="CHIP.neutra" class="ml-1" title="Nascosto sul portale pubblico">nascosto</span>
                                    </td>
                                    <td class="px-3 py-2.5 whitespace-nowrap" :class="vta(r)?.tono ?? 'text-gray-400'">{{ vta(r)?.testo ?? '—' }}</td>
                                    <td class="px-3 py-2.5 text-gray-700">
                                        <template v-if="r.ultimo_lavoro_titolo">{{ r.ultimo_lavoro_titolo }} · {{ formatData(r.ultimo_lavoro_data) }}</template>
                                        <template v-else>—</template>
                                    </td>
                                </tr>
                                <tr v-if="! righe.length && ! caricamento">
                                    <td :colspan="selezionabile ? 7 : 6" class="px-4 py-8 text-center text-sm text-gray-500" data-test="patrimonio-vuoto">
                                        {{ filtriAttivi ? 'Nessun elemento con questi filtri.' : 'Nessun elemento censito, per ora.' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-2 border-t border-gray-200 px-4 py-2.5 text-[13px] text-gray-600">
                        <span data-test="patrimonio-pagine">Righe {{ meta.from }}–{{ meta.to }} di {{ meta.total.toLocaleString('it-IT') }} · pagina {{ meta.current_page }} di {{ meta.last_page }}</span>
                        <div class="flex gap-2">
                            <button type="button" :class="BOTTONE_PICCOLO" :disabled="meta.current_page <= 1 || caricamento" @click="filtri.page = meta.current_page - 1">← Precedente</button>
                            <button type="button" :class="BOTTONE_PICCOLO" :disabled="meta.current_page >= meta.last_page || caricamento" @click="filtri.page = meta.current_page + 1">Successiva →</button>
                        </div>
                    </div>
                </section>

                <!-- Anteprima -->
                <aside :class="[CARTA, anteprima.id ? 'order-first lg:order-none' : 'hidden lg:block']" class="min-w-0 p-4 lg:sticky lg:top-4" data-test="patrimonio-anteprima">
                    <p v-if="! anteprima.id" class="text-sm text-gray-500">Scegli una riga per vederne l'anteprima: fotografia, misure e cronologia, senza aprire la scheda.</p>
                    <template v-else>
                        <div class="mb-2 flex items-center justify-between lg:hidden">
                            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Anteprima</span>
                            <button type="button" class="min-h-11 px-2 text-sm text-gray-600" @click="chiudiAnteprima">✕ Chiudi</button>
                        </div>
                        <AvvisoErrore :messaggio="anteprima.errore" @riprova="() => { const id = anteprima.id; anteprima.id = null; apriAnteprima(id); }" />
                        <p v-if="anteprima.caricamento" class="text-sm text-gray-500">Carico l'anteprima…</p>
                        <template v-if="anteprima.dettaglio">
                            <div class="overflow-hidden rounded-lg bg-gray-100" style="aspect-ratio: 4 / 3">
                                <img v-if="fotoAnteprima" :src="fotoAnteprima.url" :alt="`Fotografia di ${anteprima.dettaglio.census_code ?? 'elemento'}`" class="h-full w-full object-cover">
                                <div v-else class="flex h-full items-center justify-center text-sm text-gray-500">Nessuna fotografia</div>
                            </div>
                            <p v-if="fotoAnteprima" class="mt-1 text-xs text-gray-500">
                                Foto del {{ formatData(fotoAnteprima.taken_at ?? fotoAnteprima.created_at) }}
                                <template v-if="anteprima.dettaglio.photos_count > 1"> · {{ anteprima.dettaglio.photos_count }} foto</template>
                            </p>
                            <div class="mt-3">
                                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Cartellino</div>
                                <div class="text-xl font-bold text-gray-900">{{ anteprima.dettaglio.census_code ?? 'senza cartellino' }}</div>
                                <div class="text-sm text-gray-900">
                                    <template v-if="anteprima.dettaglio.tree">
                                        <i v-if="anteprima.dettaglio.tree.species">{{ anteprima.dettaglio.tree.species }}</i><span v-else class="text-amber-800">specie da indicare</span>
                                        <template v-if="anteprima.dettaglio.tree.common_name"> · {{ anteprima.dettaglio.tree.common_name }}</template>
                                    </template>
                                    <template v-else>{{ anteprima.dettaglio.object_type?.name }}</template>
                                </div>
                                <div class="text-[13px] text-gray-500">{{ anteprima.dettaglio.area?.name }} · {{ statusLabel(anteprima.dettaglio.status) }}</div>
                            </div>
                            <dl v-if="misure.length" class="mt-3 grid grid-cols-2 gap-x-3 gap-y-2">
                                <div v-for="[nome, valore] in misure" :key="nome">
                                    <dt class="text-xs text-gray-500">{{ nome }}</dt>
                                    <dd class="text-sm font-semibold text-gray-900">{{ valore }}</dd>
                                </div>
                            </dl>
                            <div class="mt-4 flex flex-wrap gap-2">
                                <Link :href="`/censimento/${anteprima.id}`" :class="BOTTONE" data-test="anteprima-apri">Apri la scheda</Link>
                                <Link v-if="can('works.manage')" :href="`/lavori?nuovo=1&elementi=${anteprima.id}`" :class="BOTTONE_SECONDARIO">Nuovo lavoro</Link>
                                <Link v-if="can('assets.update') && anteprima.dettaglio.tree" :href="`/censimento/${anteprima.id}?vta=1`" :class="BOTTONE_SECONDARIO">Valuta VTA</Link>
                            </div>
                            <div v-if="anteprima.cronologia" class="mt-4">
                                <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Cronologia</h2>
                                <ul v-if="anteprima.cronologia.eventi.length" class="mt-1 divide-y divide-gray-100" data-test="anteprima-cronologia">
                                    <li v-for="(e, i) in anteprima.cronologia.eventi.slice(0, 8)" :key="i" class="flex gap-3 py-2">
                                        <span class="w-20 shrink-0 pt-0.5 text-xs text-gray-500">{{ formatData(e.data) }}</span>
                                        <div class="min-w-0">
                                            <div class="text-sm font-semibold text-gray-900">
                                                <Link v-if="e.href" :href="e.href" class="underline-offset-2 hover:underline">{{ e.titolo }}</Link>
                                                <template v-else>{{ e.titolo }}</template>
                                            </div>
                                            <div class="text-[13px] text-gray-500">{{ TIPO_EVENTO[e.tipo] ?? e.tipo }}{{ e.dettaglio ? ` · ${e.dettaglio}` : '' }}</div>
                                        </div>
                                    </li>
                                </ul>
                                <p v-else class="mt-1 text-[13px] text-gray-500">Nessun evento registrato.</p>
                                <p v-if="anteprima.cronologia.eventi.length > 8" class="mt-1 text-[13px] text-gray-500">Tutta la cronologia nella scheda.</p>
                            </div>
                        </template>
                    </template>
                </aside>
            </div>
        </div>
    </AppLayout>
</template>
