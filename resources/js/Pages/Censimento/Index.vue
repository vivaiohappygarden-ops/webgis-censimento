<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import AvvisoErrore from '@/Components/AvvisoErrore.vue';
import ScegliCommittente from '@/Components/ScegliCommittente.vue';
import ScegliVoce from '@/Components/ScegliVoce.vue';
import VisteSalvate from '@/Components/VisteSalvate.vue';
import { usaCaricamento } from '@/caricamento';
import { STATUS_LABELS, inArchivio, statusLabel } from '@/assetStatus';

const page = usePage();
const permissions = computed(() => page.props.auth?.user?.permissions ?? []);
const canCreate = computed(() => permissions.value.includes('assets.create'));
// L'operatore non vede l'anagrafica committenti: per lui resta il filtro area
const canViewClients = computed(() => permissions.value.includes('clients.view'));

const rows = ref([]);

// Se l'elenco non arriva, la tabella non deve restare vuota senza spiegazione
const { avviso, riprovaInCorso, carica, riprova } = usaCaricamento();
const canUpdateAssets = computed(() => permissions.value.includes('assets.update'));

// --- Azioni su piu' elementi insieme ---------------------------------------
const selezionati = ref([]);
const azioneInCorso = ref(false);
const esitoMultiplo = ref('');
const saltatiMultiplo = ref([]);
const dataRilievoMultipla = ref('');
const ordini = ref([]);
const ordineScelto = ref('');

const tuttiSelezionati = computed(() =>
    rows.value.length > 0 && selezionati.value.length === rows.value.length);

function commutaTutti() {
    selezionati.value = tuttiSelezionati.value ? [] : rows.value.map((r) => r.id);
}

async function caricaOrdiniAperti() {
    try {
        const { data } = await axios.get('/api/v1/work-orders', { params: { per_page: 100 } });
        // Su un ordine chiuso non si aggiungono elementi: non va nemmeno offerto
        ordini.value = (data.data ?? []).filter((o) => ! ['completed', 'cancelled'].includes(o.status));
    } catch {
        ordini.value = [];
    }
}

/*
 * Ogni azione in blocco passa da una prova a vuoto: prima di toccare i dati
 * si legge "N verranno elaborati, M esclusi perche'...". La conferma esegue
 * la stessa chiamata senza la prova.
 */
const anteprimaMultipla = ref(null);

async function azioneMultipla(chiamata, riuscita, contaFatti) {
    azioneInCorso.value = true;
    esitoMultiplo.value = '';
    saltatiMultiplo.value = [];
    try {
        const { data } = await chiamata(true);
        anteprimaMultipla.value = {
            fatti: contaFatti(data.data),
            saltati: data.data.saltati ?? [],
            conferma: () => confermaAzione(chiamata, riuscita),
        };
    } catch (err) {
        esitoMultiplo.value = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
            ?? err.response?.data?.message ?? 'Errore nell\'operazione.';
    } finally {
        azioneInCorso.value = false;
    }
}

async function confermaAzione(chiamata, riuscita) {
    azioneInCorso.value = true;
    esitoMultiplo.value = '';
    saltatiMultiplo.value = [];
    try {
        const { data } = await chiamata(false);
        saltatiMultiplo.value = data.data.saltati ?? [];
        esitoMultiplo.value = riuscita(data.data);
        selezionati.value = [];
        anteprimaMultipla.value = null;
        await load();
    } catch (err) {
        esitoMultiplo.value = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
            ?? err.response?.data?.message ?? 'Errore nell\'operazione.';
    } finally {
        azioneInCorso.value = false;
    }
}

// La selezione cambia: l'anteprima calcolata prima non vale piu'
watch(selezionati, () => { anteprimaMultipla.value = null; });

const nascondiSelezionati = (nascosto) => azioneMultipla(
    (prova) => axios.post('/api/v1/azioni/modifica-elementi', {
        ids: selezionati.value, public_hidden: nascosto, prova: prova ? 1 : 0,
    }),
    (d) => `${d.modificati.length} element${d.modificati.length === 1 ? 'o' : 'i'} ${nascosto ? 'nascost' : 'rimess'}${d.modificati.length === 1 ? 'o' : 'i'} ${nascosto ? '' : 'in vista '}sul portale.`,
    (d) => d.modificati.length,
);

const applicaDataRilievo = () => azioneMultipla(
    (prova) => axios.post('/api/v1/azioni/modifica-elementi', {
        ids: selezionati.value, surveyed_at: dataRilievoMultipla.value || null, prova: prova ? 1 : 0,
    }),
    (d) => `Data di rilievo aggiornata su ${d.modificati.length}.`,
    (d) => d.modificati.length,
);

const collegaAOrdine = () => azioneMultipla(
    (prova) => axios.post(`/api/v1/azioni/lavori/${ordineScelto.value}/collega-elementi`, {
        ids: selezionati.value, prova: prova ? 1 : 0,
    }),
    (d) => `${d.collegati.length} collegat${d.collegati.length === 1 ? 'o' : 'i'} all'ordine di lavoro.`,
    (d) => d.collegati.length,
);
const meta = reactive({ total: 0, current_page: 1, last_page: 1 });
const loading = ref(false);
const filters = reactive({ q: '', status: '', clientId: '', areaId: '', page: 1 });

// Committente e area: l'elenco delle aree segue il committente scelto
const clients = ref([]);
const areas = ref([]);
const areeVoci = computed(() => areas.value.map((a) => ({
    id: a.id,
    nome: a.locality?.name ? `${a.name} · ${a.locality.name}` : a.name,
    name: a.name,
    code: a.code,
    localita: a.locality?.name,
})));
const ordiniVoci = computed(() => ordini.value.map((o) => ({
    id: o.id, nome: `${o.code} - ${o.title}`, code: o.code, title: o.title,
})));

/**
 * Un elenco intero, non solo la prima pagina.
 *
 * Le tendine chiedevano 200 voci e il server ne restituisce al massimo 100:
 * oltre il centesimo committente, o la centesima area, le voci in coda
 * sparivano dal filtro e sembrava che non esistessero.
 */
async function tutteLePagine(indirizzo, params = {}) {
    const tutte = [];
    for (let p = 1; p <= 20; p++) {
        const { data } = await axios.get(indirizzo, { params: { ...params, per_page: 100, page: p } });
        tutte.push(...data.data);
        if (! data.next_page_url) break;
    }

    return tutte;
}

async function loadClients() {
    if (! canViewClients.value) return;
    clients.value = await tutteLePagine('/api/v1/clients');
}

async function loadAreas() {
    areas.value = await tutteLePagine('/api/v1/areas', {
        client_id: filters.clientId || undefined,
    });
    // L'area scelta prima può non appartenere al nuovo committente
    if (filters.areaId && ! areas.value.some((a) => a.id === filters.areaId)) {
        filters.areaId = '';
    }
}

// L'archivio del censimento: le schede abbattute o dismesse escono dal
// lavoro quotidiano ma restano ritrovabili a colpo sicuro in una vista
// dedicata, da cui si possono sempre ripristinare
const vista = ref('censimento');

// In ogni vista il filtro di stato offre solo gli stati che le appartengono:
// così non si può chiedere un incrocio vuoto (es. "Attivo" dentro l'archivio)
const statiFiltro = computed(() => Object.fromEntries(
    Object.entries(STATUS_LABELS).filter(([valore]) =>
        (vista.value === 'archivio') === inArchivio(valore))));

function cambiaVista(nuova) {
    if (vista.value === nuova) return;
    vista.value = nuova;
    // Lo stato scelto nella vista di prima può non esistere in quella nuova
    if (filters.status && ! (filters.status in statiFiltro.value)) filters.status = '';
}

// --- Viste salvate ----------------------------------------------------------
// I filtri correnti in una forma salvabile, e il percorso inverso
const filtriCorrenti = computed(() => ({
    q: filters.q,
    status: filters.status,
    clientId: filters.clientId,
    areaId: filters.areaId,
    vista: vista.value,
}));

function applicaVista(filtri) {
    filters.q = filtri.q ?? '';
    filters.status = filtri.status ?? '';
    filters.clientId = filtri.clientId ?? '';
    filters.areaId = filtri.areaId ?? '';
    // Le viste salvate prima dell'archivio portano ancora showRemoved (la
    // vecchia spunta "mostra anche gli abbattuti") o un filtro su uno stato
    // d'archivio: tutte e due le intenzioni oggi vivono nella vista Archivio
    vista.value = filtri.vista === 'archivio'
        || (! filtri.vista && (filtri.showRemoved || inArchivio(filtri.status)))
        ? 'archivio' : 'censimento';
    if (filters.status && ! (filters.status in statiFiltro.value)) filters.status = '';
    // Il watcher dei filtri riparte da solo e ricarica l'elenco
}

// Il parametro dell'archivio, identico per elenco e CSV: 1 = solo archivio,
// 0 = nascondilo dall'elenco di tutti i giorni. Sul server un filtro di
// stato esplicito vince comunque su archivio=0, così una vista salvata
// vecchia con status "removed" non produce mai un elenco vuoto
const paramArchivio = computed(() => (vista.value === 'archivio' ? 1 : 0));

// Import GeoJSON / CAM
const importer = reactive({
    open: false, format: 'generico', areaId: '', file: null, report: null, busy: false, error: '',
    // Import generico: analisi del file, mappatura colonna -> campo nostro,
    // tipo predefinito, gestione dei codici già presenti, mappature salvate
    analisi: null, mappatura: {}, tipoDefaultId: '', esistenti: 'salta',
    mappature: [], nomeMappatura: '', mappaturaSceltaId: '', avvisoMappatura: '',
    // Solo per i file tabellari (Excel/CSV): colonne delle coordinate e
    // sistema di riferimento, proposti dall'analisi ma decisi dall'utente
    coordinate: { x: '', y: '', epsg: '4326' },
});

// Per un file tabellare la verifica parte solo con le coordinate scelte:
// senza colonne X/Y ogni riga sarebbe scartata per geometria mancante
const coordinateMancanti = computed(() => Boolean(importer.analisi?.tabellare)
    && (! importer.coordinate.x || ! importer.coordinate.y));

// Tipi del catalogo per il "tipo predefinito" (caricati alla prima analisi)
const tipiImport = ref([]);

function azzeraGenerico() {
    importer.analisi = null;
    importer.mappatura = {};
    importer.report = null;
    importer.error = '';
    importer.mappaturaSceltaId = '';
    importer.avvisoMappatura = '';
    importer.coordinate = { x: '', y: '', epsg: '4326' };
}

async function analizzaGenerico() {
    if (! importer.file) {
        importer.error = 'Scegli prima il file da caricare.';
        return;
    }
    importer.busy = true;
    importer.error = '';
    importer.report = null;
    try {
        const form = new FormData();
        form.append('file', importer.file);
        const { data } = await axios.post('/api/v1/imports/analizza', form);
        importer.analisi = data.data;
        // La proposta automatica è un punto di partenza: si può cambiare tutto
        importer.mappatura = { ...data.data.proposta };
        importer.mappaturaSceltaId = '';
        // File tabellare: proposta delle colonne di coordinate (se i nomi
        // parlano da soli); il sistema parte da WGS84, il più comune
        importer.coordinate = data.data.tabellare
            ? { x: data.data.tabellare.proposta_x ?? '', y: data.data.tabellare.proposta_y ?? '', epsg: '4326' }
            : { x: '', y: '', epsg: '4326' };
        if (! tipiImport.value.length) {
            const cat = await axios.get('/api/v1/catalog');
            tipiImport.value = cat.data.data.flatMap((m) => m.sub_types.flatMap((s) =>
                s.object_types.map((t) => ({ id: t.id, name: `${t.code} — ${t.name}` }))));
        }
        await caricaMappature();
    } catch (err) {
        importer.error = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
            ?? err.response?.data?.message ?? 'Analisi del file non riuscita';
    } finally {
        importer.busy = false;
    }
}

function mappaturaPulita() {
    return Object.fromEntries(Object.entries(importer.mappatura).filter(([, v]) => v));
}

async function runImportGenerico(dryRun) {
    if (! importer.analisi || ! importer.areaId) {
        importer.error = 'Analizza il file e scegli un\'area di destinazione.';
        return;
    }
    if (coordinateMancanti.value) {
        importer.error = 'Scegli le colonne di longitudine (X) e latitudine (Y) del foglio.';
        return;
    }
    importer.busy = true;
    importer.error = '';
    try {
        const { data } = await axios.post('/api/v1/imports/generico', {
            file_token: importer.analisi.token,
            area_id: importer.areaId,
            mappatura: mappaturaPulita(),
            default_object_type_id: importer.tipoDefaultId || null,
            esistenti: importer.esistenti,
            dry_run: dryRun,
            // Solo per i fogli: da queste colonne nascono i punti
            ...(importer.analisi.tabellare ? { coordinate: {
                colonna_x: importer.coordinate.x,
                colonna_y: importer.coordinate.y,
                epsg: importer.coordinate.epsg,
            } } : {}),
        });
        importer.report = data.data;
        if (! dryRun) {
            // Il gettone è consumato: si chiude il giro (resta il rapporto),
            // per un altro import si riparte dal file
            importer.analisi = null;
            importer.mappatura = {};
            importer.file = null;
            await load();
        }
    } catch (err) {
        importer.error = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
            ?? err.response?.data?.message ?? 'Errore durante l\'import';
    } finally {
        importer.busy = false;
    }
}

async function caricaMappature() {
    const { data } = await axios.get('/api/v1/imports/mappature');
    importer.mappature = data.data;
}

function applicaMappaturaSalvata() {
    const salvata = importer.mappature.find((m) => m.id === importer.mappaturaSceltaId);
    if (! salvata || ! importer.analisi) return;
    // Solo le colonne davvero presenti nel file: una mappatura pensata per
    // un altro tracciato non deve inventare colonne. Le assenti si dicono,
    // non si tacciono
    const presenti = new Set(importer.analisi.colonne.map((c) => c.nome));
    const mancanti = Object.keys(salvata.mapping).filter((colonna) => ! presenti.has(colonna));
    importer.mappatura = Object.fromEntries(
        Object.entries(salvata.mapping).filter(([colonna]) => presenti.has(colonna)));
    importer.avvisoMappatura = mancanti.length
        ? `Nel file mancano le colonne ${mancanti.join(', ')} della mappatura salvata: quelle corrispondenze non sono state applicate.`
        : '';
    if (salvata.default_object_type_id) importer.tipoDefaultId = salvata.default_object_type_id;
    importer.report = null;
}

async function salvaMappatura() {
    if (! importer.nomeMappatura.trim()) return;
    importer.error = '';
    try {
        await axios.post('/api/v1/imports/mappature', {
            name: importer.nomeMappatura.trim(),
            mapping: mappaturaPulita(),
            default_object_type_id: importer.tipoDefaultId || null,
        });
        importer.nomeMappatura = '';
        await caricaMappature();
    } catch (err) {
        importer.error = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
            ?? err.response?.data?.message ?? 'Salvataggio della mappatura non riuscito';
    }
}
const importAreas = ref([]);

// Export CAM: un layer del Modello Dati per volta (P/L/S + macro-categoria)
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
const camExport = reactive({ busy: false, error: '', deliveryFallback: false });

// Non un semplice link: l'errore del server (es. GDAL non installato per lo
// shapefile) deve arrivare a video in italiano, non come pagina bianca
async function exportCam(format) {
    await downloadCam(
        `/api/v1/exports/cam?layer=${exportLayer.value}&format=${format}`,
        `cam_${exportLayer.value}`,
        format === 'shapefile' ? 'zip' : 'geojson',
    );
}

// La consegna completa: tutti i layer, foto e manifest in un unico zip.
// Se lo shapefile non è disponibile sul server, il consiglio a video
// diventa azionabile: si offre la stessa consegna in GeoJSON
async function exportDelivery(format = 'shapefile') {
    camExport.deliveryFallback = false;
    const ok = await downloadCam(`/api/v1/exports/cam/delivery?format=${format}`, 'consegna_cam', 'zip');
    if (! ok && format === 'shapefile') {
        camExport.deliveryFallback = true;
    }
}

// CSV per Excel con gli stessi filtri attivi nell'elenco
async function exportCsv() {
    const params = new URLSearchParams();
    if (filters.q) params.set('q', filters.q);
    if (filters.status) params.set('status', filters.status);
    if (filters.clientId) params.set('client_id', filters.clientId);
    if (filters.areaId) params.set('area_id', filters.areaId);
    // Il CSV esporta quello che si vede: stessi parametri dell'elenco
    params.set('archivio', String(paramArchivio.value));
    const suffix = params.toString() ? `?${params.toString()}` : '';
    await downloadCam(`/api/v1/exports/assets.csv${suffix}`, 'censimento', 'csv');
}

async function downloadCam(url, baseName, extension) {
    camExport.busy = true;
    camExport.error = '';
    try {
        const r = await fetch(url, {
            headers: { Accept: 'application/json' },
        });
        if (! r.ok) {
            let message = 'Export non riuscito.';
            try {
                const body = await r.json();
                message = Object.values(body.errors ?? {})[0]?.[0] ?? body.message ?? message;
            } catch { /* risposta non JSON: resta il messaggio generico */ }
            camExport.error = message;
            return false;
        }
        const blob = await r.blob();
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        const stamp = new Date().toISOString().slice(0, 10).replaceAll('-', '');
        // Il server nomina il file con il riferimento della consegna: se
        // il nome arriva nell'intestazione, vale quello
        const served = r.headers.get('Content-Disposition')?.match(/filename="?([^";]+)"?/)?.[1];
        link.download = served ?? `${baseName}_${stamp}.${extension}`;
        link.click();
        URL.revokeObjectURL(link.href);
        return true;
    } catch {
        camExport.error = 'Export non riuscito: problema di rete.';
        return false;
    } finally {
        camExport.busy = false;
    }
}

async function openImporter() {
    importer.open = true;
    importer.file = null;
    // L'analisi del giro precedente non deve restare a schermo con il
    // campo del file ormai vuoto
    azzeraGenerico();
    if (! importAreas.value.length) {
        const { data } = await axios.get('/api/v1/areas', { params: { per_page: 100 } });
        importAreas.value = data.data;
    }
}

async function runImport(dryRun) {
    if (! importer.file || ! importer.areaId) {
        importer.error = 'Scegli un file e un\'area di destinazione.';
        return;
    }
    importer.busy = true;
    importer.error = '';
    try {
        const form = new FormData();
        form.append('file', importer.file);
        form.append('area_id', importer.areaId);
        form.append('dry_run', dryRun ? '1' : '0');
        const endpoint = importer.format === 'cam' ? '/api/v1/imports/cam' : '/api/v1/imports/geojson';
        const { data } = await axios.post(endpoint, form);
        importer.report = data.data;
        if (! dryRun) {
            await load();
        }
    } catch (err) {
        importer.error = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
            ?? err.response?.data?.message ?? 'Errore durante l\'import';
    } finally {
        importer.busy = false;
    }
}

let debounce = null;

async function load() {
    loading.value = true;
    selezionati.value = [];
    try {
        const { data } = await axios.get('/api/v1/assets', {
            params: {
                q: filters.q || undefined,
                status: filters.status || undefined,
                client_id: filters.clientId || undefined,
                area_id: filters.areaId || undefined,
                // Vista Archivio: solo abbattuti e dismessi; altrimenti
                // l'elenco di tutti i giorni li nasconde entrambi
                archivio: paramArchivio.value,
                page: filters.page,
                per_page: 25,
            },
        });
        rows.value = data.data;
        meta.total = data.total;
        meta.current_page = data.current_page;
        meta.last_page = data.last_page;
    } finally {
        loading.value = false;
    }
}

watch(() => [filters.q, filters.status, filters.clientId, filters.areaId, vista.value], () => {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
        filters.page = 1;
        carica(load);
    }, 300);
});

// Cambiando committente cambia l'elenco delle aree selezionabili
watch(() => filters.clientId, loadAreas);

watch(() => filters.page, () => carica(load));

onMounted(() => carica(() => Promise.all([load(), loadClients(), loadAreas()])));

const measure = (row) => {
    if (row.computed_area_sqm) return `${Number(row.computed_area_sqm).toLocaleString('it-IT')} m²`;
    if (row.computed_length_m) return `${Number(row.computed_length_m).toLocaleString('it-IT')} m`;
    return '—';
};

// Nell'archivio le azioni in blocco non si offrono: le schede archiviate
// verrebbero comunque saltate tutte dal pre-conteggio, meglio non proporle
const selezionabile = computed(() => canUpdateAssets.value && vista.value !== 'archivio');

// La data di abbattimento da mostrare nell'archivio: la scrive "Registra
// abbattimento" (fine validità). Un dismesso non ce l'ha: non è un abbattuto
const dataAbbattimento = (row) => {
    const iso = String(row.valid_to ?? '').slice(0, 10);
    if (! iso) return null;
    const [y, m, d] = iso.split('-');

    return `${d}/${m}/${y}`;
};
</script>

<template>
    <Head title="Censimento" />

    <AppLayout>
        <div class="p-6">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold">Censimento</h1>
                    <!-- Il totale è quello della risposta paginata: nell'archivio
                         diventa il contatore delle schede archiviate -->
                    <p class="text-sm text-gray-500" data-test="censimento-contatore">
                        {{ meta.total.toLocaleString('it-IT') }}
                        {{ vista === 'archivio'
                            ? (meta.total === 1 ? 'scheda in archivio' : 'schede in archivio')
                            : (meta.total === 1 ? 'elemento censito' : 'elementi censiti') }}
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <select v-model="exportLayer" data-test="cam-export-layer" class="w-full max-w-full rounded-lg border border-gray-300 px-2 py-2 text-sm sm:w-auto">
                        <option v-for="(label, value) in CAM_LAYERS" :key="value" :value="value">{{ label }}</option>
                    </select>
                    <button
                        class="rounded-lg border border-green-700 px-3 py-2 text-sm font-medium text-green-700 hover:bg-green-50 disabled:opacity-50"
                        :disabled="camExport.busy"
                        data-test="cam-export-geojson"
                        @click="exportCam('geojson')"
                    >Esporta GeoJSON</button>
                    <button
                        class="rounded-lg border border-green-700 px-3 py-2 text-sm font-medium text-green-700 hover:bg-green-50 disabled:opacity-50"
                        :disabled="camExport.busy"
                        data-test="cam-export-shapefile"
                        @click="exportCam('shapefile')"
                    >Esporta Shapefile</button>
                    <button
                        class="rounded-lg border border-green-700 px-3 py-2 text-sm font-medium text-green-700 hover:bg-green-50 disabled:opacity-50"
                        :disabled="camExport.busy"
                        title="Tutti i layer, le foto e il manifest in un unico pacchetto"
                        data-test="cam-export-delivery"
                        @click="exportDelivery()"
                    >Consegna completa</button>
                    <button
                        class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                        :disabled="camExport.busy"
                        title="Elenco degli elementi con i filtri attivi, da aprire in Excel"
                        data-test="csv-export"
                        @click="exportCsv()"
                    >Esporta CSV</button>
                    <button
                        v-if="canCreate"
                        class="rounded-lg bg-green-700 px-3 py-2 text-sm font-medium text-white hover:bg-green-800"
                        data-test="apri-import"
                        @click="openImporter"
                    >Importa</button>
                </div>
            </div>

            <AvvisoErrore :messaggio="avviso" :in-corso="riprovaInCorso" @riprova="riprova" />

            <p v-if="camExport.error" class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" data-test="cam-export-error">
                {{ camExport.error }}
                <button
                    v-if="camExport.deliveryFallback"
                    class="ml-2 font-medium underline"
                    data-test="cam-delivery-geojson"
                    @click="exportDelivery('geojson')"
                >Scarica la consegna in GeoJSON</button>
            </p>

            <!-- Censimento o Archivio: il lavoro di tutti i giorni da una parte,
                 le schede abbattute o dismesse dall'altra, sempre ripristinabili -->
            <div class="mb-3 inline-flex max-w-full overflow-x-auto rounded-lg border border-gray-300 text-sm">
                <button
                    class="whitespace-nowrap px-4 py-1.5 font-medium"
                    :class="vista === 'censimento' ? 'bg-green-700 text-white' : 'bg-white text-gray-600 hover:bg-gray-50'"
                    data-test="vista-censimento"
                    @click="cambiaVista('censimento')"
                >Censimento</button>
                <button
                    class="whitespace-nowrap border-l border-gray-300 px-4 py-1.5 font-medium"
                    :class="vista === 'archivio' ? 'bg-green-700 text-white' : 'bg-white text-gray-600 hover:bg-gray-50'"
                    data-test="vista-archivio"
                    @click="cambiaVista('archivio')"
                >Archivio</button>
            </div>
            <p v-if="vista === 'archivio'" class="mb-3 text-xs text-gray-500" data-test="archivio-descrizione">
                L'archivio raccoglie le schede abbattute e quelle dismesse: fuori dall'elenco
                di tutti i giorni, ma sempre consultabili e ripristinabili dalla loro scheda.
            </p>

            <div class="mb-4 flex flex-wrap items-center gap-3">
                <input
                    v-model="filters.q"
                    type="search"
                    placeholder="Cerca: codice, specie, area, committente, note…"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-green-600 focus:outline-none sm:w-72"
                >
                <ScegliCommittente
                    v-if="canViewClients"
                    v-model="filters.clientId"
                    data-test="filtro-committente"
                    class="w-full max-w-full sm:w-56"
                    campo-classe="px-3 py-2 text-sm"
                    :committenti="clients"
                    tutti="Tutti i committenti"
                />
                <ScegliVoce
                    v-model="filters.areaId"
                    data-test="filtro-area"
                    class="w-full max-w-full sm:w-56"
                    campo-classe="px-3 py-2 text-sm"
                    campo-nome="nome"
                    :voci="areeVoci"
                    :campi-ricerca="['name', 'code', 'localita']"
                    tutti="Tutte le aree"
                    vuoto="Nessuna area trovata."
                />
                <select v-model="filters.status" data-test="filtro-stato" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm sm:w-auto">
                    <option value="">{{ vista === 'archivio' ? 'Tutto l\'archivio' : 'Tutti gli stati' }}</option>
                    <option v-for="(label, value) in statiFiltro" :key="value" :value="value">{{ label }}</option>
                </select>

                <VisteSalvate pagina="censimento" :filtri="filtriCorrenti" @applica="applicaVista" />
            </div>

            <div
                v-if="selezionabile && selezionati.length"
                data-test="censimento-barra-selezione"
                class="mb-3 rounded-xl border border-green-200 bg-green-50 px-4 py-2.5 text-sm"
            >
                <div class="flex flex-wrap items-center gap-3">
                    <span class="font-medium">{{ selezionati.length }} selezionati</span>

                    <template v-if="anteprimaMultipla">
                        <span data-test="multipla-anteprima" class="text-gray-800">
                            {{ anteprimaMultipla.fatti }} {{ anteprimaMultipla.fatti === 1 ? 'verrà elaborato' : 'verranno elaborati' }}<template v-if="anteprimaMultipla.saltati.length">, {{ anteprimaMultipla.saltati.length }} esclus{{ anteprimaMultipla.saltati.length === 1 ? 'o' : 'i' }}</template>.
                        </span>
                        <button
                            class="rounded-lg bg-green-700 px-3 py-1.5 font-medium text-white hover:bg-green-800 disabled:opacity-50"
                            :disabled="azioneInCorso || ! anteprimaMultipla.fatti"
                            data-test="multipla-conferma"
                            @click="anteprimaMultipla.conferma()"
                        >Conferma</button>
                        <button class="text-gray-600 hover:underline" @click="anteprimaMultipla = null">Annulla</button>
                    </template>

                    <template v-if="! anteprimaMultipla">
                    <button
                        class="rounded-lg bg-green-700 px-3 py-1.5 font-medium text-white hover:bg-green-800 disabled:opacity-50"
                        :disabled="azioneInCorso"
                        data-test="multipla-nascondi"
                        @click="nascondiSelezionati(true)"
                    >Nascondi dal portale</button>
                    <button
                        class="rounded-lg border border-green-700 px-3 py-1.5 font-medium text-green-700 hover:bg-green-100 disabled:opacity-50"
                        :disabled="azioneInCorso"
                        @click="nascondiSelezionati(false)"
                    >Rimetti sul portale</button>

                    <label class="flex items-center gap-2">
                        <span class="text-gray-600">Data di rilievo</span>
                        <input v-model="dataRilievoMultipla" type="date" class="rounded-lg border border-gray-300 px-2 py-1.5 text-sm">
                        <button
                            class="rounded-lg border border-green-700 px-2.5 py-1.5 font-medium text-green-700 hover:bg-green-100 disabled:opacity-50"
                            :disabled="azioneInCorso || ! dataRilievoMultipla"
                            data-test="multipla-data"
                            @click="applicaDataRilievo"
                        >Applica</button>
                    </label>

                    <label class="flex items-center gap-2">
                        <span class="text-gray-600">Aggiungi a un lavoro</span>
                        <ScegliVoce
                            v-model="ordineScelto"
                            class="w-64"
                            campo-classe="px-2 py-1.5 text-sm"
                            campo-nome="nome"
                            :voci="ordiniVoci"
                            :campi-ricerca="['code', 'title']"
                            segnaposto="Scegli…"
                            vuoto="Nessun ordine trovato."
                            @apre="ordini.length || caricaOrdiniAperti()"
                        />
                        <button
                            class="rounded-lg border border-green-700 px-2.5 py-1.5 font-medium text-green-700 hover:bg-green-100 disabled:opacity-50"
                            :disabled="azioneInCorso || ! ordineScelto"
                            data-test="multipla-collega"
                            @click="collegaAOrdine"
                        >Collega</button>
                    </label>
                    </template>

                    <button class="text-gray-600 hover:underline" @click="selezionati = []">Annulla selezione</button>
                </div>

                <p v-if="esitoMultiplo" class="mt-2 text-gray-700">{{ esitoMultiplo }}</p>
            </div>

            <ul
                v-if="anteprimaMultipla?.saltati.length"
                data-test="multipla-anteprima-esclusi"
                class="mb-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-2.5 text-sm text-amber-900"
            >
                <li class="mb-1 font-medium">Questi verranno esclusi:</li>
                <li v-for="s in anteprimaMultipla.saltati" :key="s.id" class="text-xs">{{ s.codice || s.id }} - {{ s.motivo }}</li>
            </ul>

            <ul v-if="saltatiMultiplo.length" data-test="censimento-saltati" class="mb-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-2.5 text-sm text-amber-900">
                <li class="mb-1 font-medium">Questi sono stati saltati:</li>
                <li v-for="s in saltatiMultiplo" :key="s.id" class="text-xs">{{ s.codice || s.id }} - {{ s.motivo }}</li>
            </ul>

            <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th v-if="selezionabile" class="px-2 py-3">
                                <input
                                    type="checkbox"
                                    class="rounded border-gray-300"
                                    aria-label="Seleziona tutti"
                                    :checked="tuttiSelezionati"
                                    @change="commutaTutti"
                                >
                            </th>
                            <th class="px-4 py-3">Codice</th>
                            <th class="px-4 py-3">Tipo</th>
                            <th class="px-4 py-3">Committente</th>
                            <th class="px-4 py-3">Area</th>
                            <th class="px-4 py-3">Stato</th>
                            <th class="px-4 py-3">Misura</th>
                            <th class="px-4 py-3">Aggiornato</th>
                            <th class="px-4 py-3" />
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-if="loading">
                            <td :colspan="selezionabile ? 9 : 8" class="px-4 py-8 text-center text-gray-400">Caricamento…</td>
                        </tr>
                        <tr v-else-if="rows.length === 0">
                            <td :colspan="selezionabile ? 9 : 8" class="px-4 py-8 text-center text-gray-400">
                                {{ vista === 'archivio' ? 'Nessuna scheda in archivio.' : 'Nessun elemento trovato.' }}
                            </td>
                        </tr>
                        <tr v-for="row in rows" v-else :key="row.id" class="hover:bg-green-50/40">
                            <td v-if="selezionabile" class="px-2 py-3">
                                <input
                                    v-model="selezionati"
                                    type="checkbox"
                                    :value="row.id"
                                    class="rounded border-gray-300"
                                    :aria-label="`Seleziona ${row.census_code || 'elemento'}`"
                                    data-test="elemento-casella"
                                >
                            </td>
                            <td class="px-4 py-3 font-medium">{{ row.census_code || '—' }}</td>
                            <td class="px-4 py-3">
                                <span class="text-xs text-gray-400">{{ row.object_type?.code }}</span>
                                {{ row.object_type?.name }}
                            </td>
                            <td class="px-4 py-3">{{ row.area?.locality?.site?.client?.name || '—' }}</td>
                            <td class="px-4 py-3">
                                {{ row.area?.name || '—' }}
                                <span v-if="row.area?.locality?.name" class="text-xs text-gray-400">· {{ row.area.locality.name }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="{
                                        'bg-green-100 text-green-800': row.status === 'active',
                                        'bg-amber-100 text-amber-900': row.status === 'removed',
                                        'bg-gray-200 text-gray-700': row.status === 'dismissed',
                                        'bg-gray-100 text-gray-600': ! ['active', 'removed', 'dismissed'].includes(row.status),
                                    }"
                                >
                                    {{ statusLabel(row.status) }}
                                </span>
                                <!-- Nell'archivio la data dell'abbattimento sta accanto allo
                                     stato; un dismesso non ne ha una (non è un abbattuto) -->
                                <span
                                    v-if="vista === 'archivio' && row.status === 'removed' && dataAbbattimento(row)"
                                    class="mt-0.5 block text-xs text-gray-500"
                                    data-test="archivio-data"
                                >il {{ dataAbbattimento(row) }}</span>
                            </td>
                            <td class="px-4 py-3">{{ measure(row) }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ new Date(row.updated_at).toLocaleDateString('it-IT') }}</td>
                            <td class="px-4 py-3 text-right">
                                <Link :href="`/censimento/${row.id}`" class="font-medium text-green-700 hover:underline">
                                    Apri
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="meta.last_page > 1" class="mt-4 flex items-center justify-between text-sm">
                <button
                    class="rounded-lg border border-gray-300 px-3 py-1.5 disabled:opacity-40"
                    :disabled="filters.page <= 1"
                    @click="filters.page--"
                >
                    ← Precedente
                </button>
                <span class="text-gray-500">Pagina {{ meta.current_page }} di {{ meta.last_page }}</span>
                <button
                    class="rounded-lg border border-gray-300 px-3 py-1.5 disabled:opacity-40"
                    :disabled="filters.page >= meta.last_page"
                    @click="filters.page++"
                >
                    Successiva →
                </button>
            </div>

            <!-- Modal import GeoJSON -->
            <Teleport to="body">
                <div v-if="importer.open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4" @click.self="importer.open = false">
                    <div class="max-h-[92vh] w-full overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl" :class="importer.format === 'generico' && importer.analisi ? 'max-w-3xl' : 'max-w-lg'">
                        <div class="flex items-start justify-between">
                            <h2 class="font-semibold">Importa censimento</h2>
                            <button class="text-gray-400 hover:text-gray-600" @click="importer.open = false">✕</button>
                        </div>
                        <p class="mt-1 text-xs text-gray-500">
                            <template v-if="importer.format === 'generico'">
                                File di qualunque provenienza (shapefile zippato, GeoJSON, GeoPackage, KML,
                                Excel .xlsx o CSV): il programma legge le colonne e tu dici a quale nostro campo
                                corrisponde ciascuna. Per Excel e CSV indichi anche le due colonne delle coordinate.
                            </template>
                            <template v-else-if="importer.format === 'cam'">
                                Shapefile zippato o GeoJSON nel formato ministeriale (Modello Dati v2.1), tutte le macro-categorie: campi CODICE, OBJ_ID, GENERE/SPECIE, H_m, LARG_m, DATA_RIL…
                            </template>
                            <template v-else>
                                FeatureCollection con proprietà codice (codice catalogo, es. P103108) per ogni feature.
                            </template>
                            Prima l'analisi, poi l'import: nulla viene importato silenziosamente.
                        </p>

                        <div class="mt-4 space-y-3">
                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                                <label
                                    v-for="opt in [
                                        { value: 'generico', label: 'Qualsiasi file (scegli tu le colonne)' },
                                        { value: 'cam', label: 'Formato CAM (shapefile/GeoJSON)' },
                                        { value: 'geojson', label: 'GeoJSON campi nostri' },
                                    ]"
                                    :key="opt.value"
                                    class="flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm"
                                    :class="[importer.format === opt.value ? 'border-green-700 bg-green-50 font-medium' : 'border-gray-300', importer.busy ? 'pointer-events-none opacity-50' : '']"
                                    :data-test="`imp-formato-${opt.value}`"
                                >
                                    <input v-model="importer.format" type="radio" :value="opt.value" class="hidden" :disabled="importer.busy" @change="azzeraGenerico()">
                                    {{ opt.label }}
                                </label>
                            </div>
                            <input
                                type="file"
                                data-test="imp-file"
                                :accept="importer.format === 'generico' ? '.zip,.json,.geojson,.gpkg,.kml,.xlsx,.csv'
                                    : importer.format === 'cam' ? '.zip,.json,.geojson' : '.json,.geojson,application/json,application/geo+json'"
                                class="w-full text-sm disabled:opacity-50"
                                :disabled="importer.busy"
                                @change="(e) => { importer.file = e.target.files?.[0] ?? null; azzeraGenerico(); }"
                            >
                            <ScegliVoce
                                v-model="importer.areaId"
                                class="w-full"
                                campo-classe="px-2.5 py-2 text-sm"
                                :voci="importAreas"
                                :campi-ricerca="['name', 'code']"
                                segnaposto="Area di destinazione…"
                                vuoto="Nessuna area trovata."
                                @cambia="importer.report = null"
                            />

                            <!-- Passo 1 del generico: analisi del file -->
                            <template v-if="importer.format === 'generico'">
                                <button
                                    v-if="! importer.analisi"
                                    class="w-full rounded-lg border border-green-700 px-3 py-2 text-sm font-medium text-green-700 hover:bg-green-50 disabled:opacity-50"
                                    :disabled="importer.busy || ! importer.file"
                                    data-test="imp-analizza"
                                    @click="analizzaGenerico"
                                >{{ importer.busy ? 'Lettura del file…' : '1 · Leggi il file (colonne e anteprima)' }}</button>

                                <div v-if="importer.analisi" class="space-y-3">
                                    <p class="text-xs text-gray-600" data-test="imp-analisi-riassunto">
                                        <template v-if="importer.analisi.tabellare">
                                            {{ importer.analisi.totale }} righe nel foglio: i punti nasceranno
                                            dalle colonne di coordinate scelte qui sotto.
                                        </template>
                                        <template v-else>
                                            {{ importer.analisi.totale }} elementi nel file
                                            ({{ Object.entries(importer.analisi.geometrie).map(([t, n]) => `${n} ${t}`).join(', ') }}).
                                        </template>
                                    </p>
                                    <p v-for="(avviso, i) in importer.analisi.avvisi ?? []" :key="i" class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900" data-test="imp-avviso-analisi">{{ avviso }}</p>

                                    <!-- Coordinate del foglio: due colonne e sistema di riferimento.
                                         Le righe senza coordinate valide saranno scartate e dichiarate,
                                         mai posizionate a caso -->
                                    <div v-if="importer.analisi.tabellare" class="rounded-xl border border-gray-200 p-3" data-test="imp-coordinate">
                                        <p class="mb-2 text-xs font-medium text-gray-500">Coordinate (obbligatorie per Excel/CSV)</p>
                                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                                            <label class="block text-xs">
                                                <span class="text-gray-500">Colonna X / longitudine / Est</span>
                                                <select v-model="importer.coordinate.x" data-test="imp-coordinata-x" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm" @change="importer.report = null">
                                                    <option value="">Scegli…</option>
                                                    <option v-for="colonna in importer.analisi.colonne" :key="colonna.nome" :value="colonna.nome">{{ colonna.nome }}</option>
                                                </select>
                                            </label>
                                            <label class="block text-xs">
                                                <span class="text-gray-500">Colonna Y / latitudine / Nord</span>
                                                <select v-model="importer.coordinate.y" data-test="imp-coordinata-y" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm" @change="importer.report = null">
                                                    <option value="">Scegli…</option>
                                                    <option v-for="colonna in importer.analisi.colonne" :key="colonna.nome" :value="colonna.nome">{{ colonna.nome }}</option>
                                                </select>
                                            </label>
                                            <label class="block text-xs">
                                                <span class="text-gray-500">Sistema di riferimento</span>
                                                <select v-model="importer.coordinate.epsg" data-test="imp-coordinate-epsg" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm" @change="importer.report = null">
                                                    <option v-for="s in importer.analisi.tabellare.sistemi" :key="s.valore" :value="s.valore">{{ s.etichetta }}</option>
                                                </select>
                                            </label>
                                        </div>
                                        <p class="mt-2 text-xs text-gray-500">
                                            Le virgole decimali ("9,1912") vanno bene. Le righe senza coordinate
                                            valide verranno scartate e conteggiate con il numero di riga del
                                            foglio (la riga 1 è l'intestazione).
                                        </p>
                                    </div>

                                    <div v-if="importer.mappature.length" class="flex flex-wrap items-center gap-2 text-xs">
                                        <span class="text-gray-500">Mappatura salvata</span>
                                        <select v-model="importer.mappaturaSceltaId" data-test="imp-mappatura-salvata" class="rounded-lg border border-gray-300 px-2 py-1.5 text-sm" @change="applicaMappaturaSalvata">
                                            <option value="">—</option>
                                            <option v-for="m in importer.mappature" :key="m.id" :value="m.id">{{ m.name }}</option>
                                        </select>
                                    </div>
                                    <p v-if="importer.avvisoMappatura" class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900" data-test="imp-avviso-mappatura">{{ importer.avvisoMappatura }}</p>

                                    <div class="overflow-x-auto rounded-xl border border-gray-200">
                                        <table class="w-full text-sm">
                                            <thead>
                                                <tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wide text-gray-400">
                                                    <th class="px-3 py-2 font-medium">Colonna del file</th>
                                                    <th class="px-3 py-2 font-medium">Esempi</th>
                                                    <th class="px-3 py-2 font-medium">Diventa il nostro campo</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-50">
                                                <tr v-for="colonna in importer.analisi.colonne" :key="colonna.nome" data-test="imp-colonna">
                                                    <td class="px-3 py-1.5 font-medium">{{ colonna.nome }}</td>
                                                    <td class="max-w-40 truncate px-3 py-1.5 text-xs text-gray-500" :title="colonna.esempi.join(' · ')">{{ colonna.esempi.join(' · ') || '—' }}</td>
                                                    <td class="px-3 py-1.5">
                                                        <select
                                                            :value="importer.mappatura[colonna.nome] ?? ''"
                                                            class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm"
                                                            data-test="imp-destinazione"
                                                            @change="(e) => { importer.mappatura[colonna.nome] = e.target.value; importer.report = null; }"
                                                        >
                                                            <option value="">Non importare</option>
                                                            <option v-for="d in importer.analisi.destinazioni" :key="d.valore" :value="d.valore">{{ d.etichetta }}</option>
                                                        </select>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>

                                    <div class="overflow-x-auto rounded-xl border border-gray-200 p-2">
                                        <p class="mb-1 px-1 text-xs font-medium text-gray-500">Anteprima delle prime righe</p>
                                        <table class="w-full text-xs">
                                            <thead>
                                                <tr class="text-left text-gray-400">
                                                    <th v-for="colonna in importer.analisi.colonne" :key="colonna.nome" class="px-2 py-1 font-medium">{{ colonna.nome }}</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-50">
                                                <tr v-for="(riga, i) in importer.analisi.anteprima" :key="i" data-test="imp-anteprima-riga">
                                                    <td v-for="colonna in importer.analisi.colonne" :key="colonna.nome" class="max-w-32 truncate px-2 py-1">{{ riga[colonna.nome] ?? '—' }}</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>

                                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                        <label class="block text-xs">
                                            <span class="text-gray-500">Tipo predefinito (per le righe senza codice catalogo)</span>
                                            <ScegliVoce
                                                v-model="importer.tipoDefaultId"
                                                data-test="imp-tipo-default"
                                                class="mt-1 w-full"
                                                campo-classe="px-2.5 py-2 text-sm"
                                                :voci="tipiImport"
                                                :campi-ricerca="['name']"
                                                tutti="Nessuno"
                                                vuoto="Nessun tipo trovato."
                                                @cambia="importer.report = null"
                                            />
                                        </label>
                                        <div class="text-xs">
                                            <span class="text-gray-500">Codici censimento già presenti</span>
                                            <div class="mt-1.5 flex gap-3">
                                                <label class="flex items-center gap-1.5 text-sm">
                                                    <input v-model="importer.esistenti" type="radio" value="salta" class="border-gray-300" @change="importer.report = null">
                                                    Salta
                                                </label>
                                                <label class="flex items-center gap-1.5 text-sm">
                                                    <input v-model="importer.esistenti" type="radio" value="aggiorna" data-test="imp-aggiorna" class="border-gray-300" @change="importer.report = null">
                                                    Aggiorna le schede
                                                </label>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="flex flex-wrap items-center gap-2 text-xs">
                                        <input v-model="importer.nomeMappatura" data-test="imp-nome-mappatura" placeholder="Salva questa mappatura col nome…" maxlength="80" class="w-56 rounded-lg border border-gray-300 px-2 py-1.5 text-sm">
                                        <button class="font-medium text-green-800 hover:underline disabled:opacity-50" :disabled="! importer.nomeMappatura.trim()" data-test="imp-salva-mappatura" @click="salvaMappatura">Salva mappatura</button>
                                    </div>
                                </div>
                            </template>

                            <p v-if="importer.error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ importer.error }}</p>

                            <div v-if="importer.report" class="rounded-xl border border-gray-200 p-3 text-sm" data-test="imp-report">
                                <div class="grid gap-2 text-center" :class="importer.report.updatable !== undefined ? 'grid-cols-4' : 'grid-cols-3'">
                                    <div><div class="text-lg font-semibold">{{ importer.report.total }}</div><div class="text-xs text-gray-500">nel file</div></div>
                                    <div><div class="text-lg font-semibold text-green-700">{{ importer.report.dry_run ? importer.report.importable : importer.report.imported }}</div><div class="text-xs text-gray-500">{{ importer.report.dry_run ? 'importabili' : 'importati' }}</div></div>
                                    <div v-if="importer.report.updatable !== undefined"><div class="text-lg font-semibold text-green-700">{{ importer.report.dry_run ? importer.report.updatable : importer.report.updated }}</div><div class="text-xs text-gray-500">{{ importer.report.dry_run ? 'da aggiornare' : 'aggiornati' }}</div></div>
                                    <div><div class="text-lg font-semibold text-red-600">{{ importer.report.errors_total }}</div><div class="text-xs text-gray-500">scartati</div></div>
                                </div>
                                <ul v-if="importer.report.errors.length" class="mt-2 max-h-32 space-y-0.5 overflow-y-auto text-xs text-red-700">
                                    <li v-for="(e, i) in importer.report.errors" :key="i">• {{ e.error }}</li>
                                    <li v-if="importer.report.errors_total > importer.report.errors.length" class="font-medium">
                                        … e altri {{ importer.report.errors_total - importer.report.errors.length }} scarti non mostrati.
                                    </li>
                                </ul>
                                <ul v-if="importer.report.warnings?.length" class="mt-2 max-h-24 space-y-0.5 overflow-y-auto text-xs text-amber-700">
                                    <li v-for="(w, i) in importer.report.warnings" :key="i">• {{ w }}</li>
                                    <li v-if="importer.report.warnings_total > importer.report.warnings.length" class="font-medium">
                                        … e altri {{ importer.report.warnings_total - importer.report.warnings.length }} avvisi non mostrati.
                                    </li>
                                </ul>
                                <p v-if="importer.report.dropped_properties?.length" class="mt-2 text-xs text-gray-500">
                                    Proprietà ignorate (non definite come campi del tipo): {{ importer.report.dropped_properties.join(', ') }}
                                </p>
                                <p v-if="! importer.report.dry_run" class="mt-2 font-medium text-green-700">Import completato</p>
                            </div>

                            <div v-if="importer.format !== 'generico'" class="flex gap-2">
                                <button
                                    class="flex-1 rounded-lg border border-green-700 px-3 py-2 text-sm font-medium text-green-700 hover:bg-green-50 disabled:opacity-50"
                                    :disabled="importer.busy"
                                    @click="runImport(true)"
                                >{{ importer.busy ? 'Analisi…' : '1 · Analizza (dry-run)' }}</button>
                                <button
                                    class="flex-1 rounded-lg bg-green-700 px-3 py-2 text-sm font-medium text-white hover:bg-green-800 disabled:opacity-50"
                                    :disabled="importer.busy || ! importer.report || ! importer.report.dry_run || importer.report.importable === 0"
                                    @click="runImport(false)"
                                >2 · Importa {{ importer.report?.dry_run ? importer.report.importable : '' }}</button>
                            </div>
                            <div v-else-if="importer.analisi" class="flex gap-2">
                                <button
                                    class="flex-1 rounded-lg border border-green-700 px-3 py-2 text-sm font-medium text-green-700 hover:bg-green-50 disabled:opacity-50"
                                    :disabled="importer.busy || coordinateMancanti"
                                    data-test="imp-verifica"
                                    @click="runImportGenerico(true)"
                                >{{ importer.busy ? 'Verifica…' : '2 · Verifica (senza importare)' }}</button>
                                <button
                                    class="flex-1 rounded-lg bg-green-700 px-3 py-2 text-sm font-medium text-white hover:bg-green-800 disabled:opacity-50"
                                    :disabled="importer.busy || coordinateMancanti || ! importer.report || ! importer.report.dry_run || (importer.report.importable === 0 && importer.report.updatable === 0)"
                                    data-test="imp-importa"
                                    @click="runImportGenerico(false)"
                                >3 · Importa{{ importer.report?.dry_run ? ` ${importer.report.importable + importer.report.updatable}` : '' }}</button>
                            </div>
                        </div>
                    </div>
                </div>
            </Teleport>
        </div>
    </AppLayout>
</template>
