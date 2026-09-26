<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import * as maplibregl from 'maplibre-gl';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import AvvisoErrore from '@/Components/AvvisoErrore.vue';
import AssetEditPanel from '@/Components/AssetEditPanel.vue';
import GestionalePanel from '@/Components/GestionalePanel.vue';
import PlantingSitePanel from '@/Components/PlantingSitePanel.vue';
import TreeVtaPanel from '@/Components/TreeVtaPanel.vue';
import ModuloAlbero from '@/Components/Nuovo/ModuloAlbero.vue';
import { usaCaricamento } from '@/caricamento';
import { avvisoCaricamento } from '@/avvisi';
import { fetchPdf } from '@/pdf';
import { inArchivio, statusLabel } from '@/assetStatus';
import { BOTTONE, BOTTONE_PICCOLO, BOTTONE_SECONDARIO, CARTA, CHIP, ETICHETTA, plurale } from '@/nuovo/stile';

/*
 * La scheda dell'elemento nella veste nuova (bozza A, schermata 05): prima si
 * legge, poi si modifica una sezione per volta. Misure, identita' e
 * posizione, stabilita' (VTA), lavori e segnalazioni; a fianco fotografie,
 * mappa e cronologia. Le scritture passano dagli stessi moduli e dalle
 * stesse chiamate della scheda precedente: qui cambia l'ordine con cui si
 * guarda, non quello che si puo' fare.
 */
const props = defineProps({
    assetId: { type: String, required: true },
    navigazioneUrl: { type: String, default: null },
});

const page = usePage();
const permessi = computed(() => page.props.auth?.user?.permissions ?? []);
const can = (p) => permessi.value.includes(p);
const canUpdate = computed(() => can('assets.update'));
const canDelete = computed(() => can('assets.delete'));
const apriValutazione = new URLSearchParams(window.location.search).get('vta') === '1';

const { avviso, riprovaInCorso, carica, riprova } = usaCaricamento();
const asset = ref(null);
const cronologia = ref(null);
const valutazioni = ref([]);
const campiTipo = ref([]);
const vincoli = ref([]);
const vincoliDisponibili = ref([]);
const vincoloDaCollegare = ref('');

const modifica = reactive({ misure: false, identita: false, scheda: false });
const pannelli = reactive({ vta: apriValutazione, storia: false, gestionale: false, fineVita: false });
const storia = reactive({ righe: null, caricamento: false, errore: '' });
const azione = reactive({ errore: '', inCorso: false });
const SEZIONI_STAMPA = {
    dendro: 'Dati dendrometrici e agronomici',
    vta: 'Ultima valutazione di stabilità',
    attributi: 'Attributi del tipo',
    foto: 'Documentazione fotografica',
};
const stampa = reactive({ busy: false, errore: '', sezioni: Object.fromEntries(Object.keys(SEZIONI_STAMPA).map((k) => [k, true])) });
const foto = reactive({ caricamento: false, errore: '', aperta: null });

// --- Dati -------------------------------------------------------------------
async function load() {
    const [a, c] = await Promise.all([
        axios.get(`/api/v1/assets/${props.assetId}`),
        axios.get(`/api/v1/assets/${props.assetId}/cronologia`),
    ]);
    asset.value = a.data.data;
    cronologia.value = c.data.data;
    storia.righe = null;
    Object.assign(modifica, { misure: false, identita: false, scheda: false });
    await Promise.all([caricaValutazioni(), caricaCampiTipo(), caricaVincoli()]);
}

async function caricaValutazioni() {
    if (! asset.value?.tree) {
        valutazioni.value = [];

        return;
    }
    try {
        const { data } = await axios.get(`/api/v1/assets/${props.assetId}/assessments`);
        valutazioni.value = data.data;
    } catch {
        valutazioni.value = [];
    }
}

// Le etichette degli attributi del tipo: senza, si vedrebbero le chiavi
async function caricaCampiTipo() {
    if (! asset.value?.object_type?.id) return;
    try {
        const { data } = await axios.get('/api/v1/custom-fields', { params: { object_type_id: asset.value.object_type.id } });
        campiTipo.value = data.data ?? [];
    } catch {
        campiTipo.value = [];
    }
}

async function caricaVincoli() {
    try {
        const [collegati, elenco] = await Promise.all([
            axios.get(`/api/v1/assets/${props.assetId}/constraints`),
            canUpdate.value ? axios.get('/api/v1/constraints', { params: { per_page: 100 } }) : Promise.resolve({ data: { data: [] } }),
        ]);
        vincoli.value = collegati.data.data;
        vincoliDisponibili.value = elenco.data.data;
    } catch {
        vincoli.value = [];
        vincoliDisponibili.value = [];
    }
}

async function onSaved() {
    await carica(load);
}

// --- Testi e formati --------------------------------------------------------
function formatData(valore) {
    if (! valore) return '—';
    const [a, m, g] = String(valore).slice(0, 10).split('-');

    return `${g}/${m}/${a}`;
}
const num = (v, dec = 1) => (v === null || v === undefined || v === '' ? null : Number(v).toLocaleString('it-IT', { maximumFractionDigits: dec }));
const misura = (v, unita, dec = 1) => (num(v, dec) === null ? '—' : `${num(v, dec)} ${unita}`);
const voce = (dizionario, valore) => (valore ? String(valore) : '—');
const oggiIso = new Date().toISOString().slice(0, 10);

const albero = computed(() => asset.value?.tree ?? null);
const titolo = computed(() => asset.value?.census_code || asset.value?.object_type?.name || 'Elemento');
const committente = computed(() => asset.value?.area?.locality?.site?.client?.name ?? null);

const ultimaVta = computed(() => valutazioni.value[0] ?? null);
const statoVta = computed(() => {
    if (! albero.value) return null;
    const v = ultimaVta.value;
    if (! v) return { testo: 'mai valutato', tono: 'neutra' };
    const classe = v.failure_class ? `classe ${v.failure_class}` : 'classe n.d.';
    const scadenza = v.next_check_due ? String(v.next_check_due).slice(0, 10) : null;
    if (! scadenza) return { testo: `VTA ${classe} · senza ricontrollo`, tono: 'neutra' };
    if (scadenza < oggiIso) return { testo: `VTA ${classe} · ricontrollo scaduto il ${formatData(scadenza)}`, tono: 'errore' };

    return { testo: `VTA ${classe} · ricontrollo entro il ${formatData(scadenza)}`, tono: 'ok' };
});
const TIPI_VALUTAZIONE = {
    vta_visual: 'metodo visivo', vta_instrumental: 'metodo strumentale', vsa: 'valutazione speditiva',
    pull_test: 'prova di trazione', aerial_inspection: 'ispezione in quota', other: 'altro metodo',
};
const ESITI = { ok: 'nessuna criticità rilevata', monitor: 'da monitorare', prescriptions: 'interventi prescritti', fell: 'da abbattere' };

// Il ricontrollo in agenda: l'ordine nato dallo scadenzario, non ancora chiuso
const ricontrolloInAgenda = computed(() => (cronologia.value?.eventi ?? [])
    .find((e) => e.tipo === 'lavoro' && e.origine === 'vta_recheck' && ! ['completed', 'cancelled'].includes(e.stato)) ?? null);

const lavoriESegnalazioni = computed(() => (cronologia.value?.eventi ?? []).filter((e) => e.tipo === 'lavoro' || e.tipo === 'segnalazione'));

// La specie e' spesso gia' il binomio ("Tilia cordata"): il genere non si ripete
const nomeBotanico = computed(() => {
    const t = albero.value;
    if (! t) return '';
    if (t.species && t.genus && t.species.toLowerCase().startsWith(t.genus.toLowerCase())) return t.species;

    return [t.genus, t.species].filter(Boolean).join(' ');
});

const METODI_RILIEVO = {
    gps: 'GPS', manual_map: 'posizionato sulla mappa', import: 'importato da file', total_station: 'stazione totale',
    rtk: 'GPS RTK', dgps: 'GPS differenziale', photogrammetry: 'fotogrammetria', manual: 'a mano',
};
const metodoRilievo = computed(() => (asset.value?.survey_method ? (METODI_RILIEVO[asset.value.survey_method] ?? asset.value.survey_method) : null));

const tutele = computed(() => {
    const t = albero.value;
    if (! t) return null;
    const parti = [];
    if (t.is_monumental) parti.push(`monumentale${t.monumental_ref ? ` (${t.monumental_ref})` : ''}`);
    if (t.is_protected) parti.push(`soggetto a tutela${t.protection_ref ? ` (${t.protection_ref})` : ''}`);
    if (t.is_dedicated) parti.push(`dedicato${t.dedicated_to?.name ? ` a ${t.dedicated_to.name}` : ''}${t.dedicated_to?.occasion ? `, ${t.dedicated_to.occasion}` : ''}`);

    return parti.length ? parti.join(' · ') : 'nessuna';
});
const sostegni = computed(() => {
    const t = albero.value;
    if (! t) return null;
    const parti = [];
    if (t.has_stake) parti.push('palo tutore');
    if (t.has_bracing) parti.push(`consolidamento${t.bracing_notes ? ` (${t.bracing_notes})` : ''}`);

    return parti.length ? parti.join(' · ') : 'nessuno';
});

// Il punto di riferimento: il punto stesso, o il primo vertice disegnato
const punto = computed(() => {
    const g = asset.value?.geom_geojson;
    const p = ! g ? null
        : g.type === 'Point' ? g.coordinates
        : g.type === 'MultiPoint' ? g.coordinates[0]
        : g.type === 'LineString' ? g.coordinates[0]
        : g.type === 'Polygon' ? g.coordinates[0]?.[0]
        : g.type === 'MultiLineString' ? g.coordinates[0]?.[0]
        : g.type === 'MultiPolygon' ? g.coordinates[0]?.[0]?.[0]
        : null;

    return Array.isArray(p) && p.length >= 2 ? { lon: p[0], lat: p[1] } : null;
});
const coordinate = computed(() => {
    if (! punto.value) return '—';
    const gps = asset.value?.gps_accuracy_m ? ` (GPS ${num(asset.value.gps_accuracy_m)} m)` : '';

    return `${punto.value.lat.toFixed(6).replace('.', ',')} N · ${punto.value.lon.toFixed(6).replace('.', ',')} E${gps}`;
});
const streetView = computed(() => (punto.value ? `https://www.google.com/maps/@?api=1&map_action=pano&viewpoint=${punto.value.lat},${punto.value.lon}` : null));
const naviga = computed(() => (punto.value && props.navigazioneUrl
    ? props.navigazioneUrl.replace('{lat}', punto.value.lat.toFixed(6)).replace('{lon}', punto.value.lon.toFixed(6))
    : null));

const misuraGeometrica = computed(() => {
    const a = asset.value;
    if (! a) return null;
    if (a.computed_area_sqm) return `${num(a.computed_area_sqm, 0)} m² (perimetro ${num(a.computed_perimeter_m, 0)} m)`;
    if (a.computed_length_m) return `${num(a.computed_length_m, 1)} m`;

    return null;
});

const attributi = computed(() => {
    const righe = Object.entries(asset.value?.attributes ?? {}).filter(([, v]) => v !== null && v !== '' && ! (Array.isArray(v) && ! v.length));
    const etichette = Object.fromEntries(campiTipo.value.map((c) => [c.key, c]));

    return righe.map(([chiave, valore]) => ({
        chiave,
        etichetta: etichette[chiave]?.label ?? chiave,
        valore: `${Array.isArray(valore) ? valore.join(', ') : valore}${etichette[chiave]?.unit ? ` ${etichette[chiave].unit}` : ''}`,
    }));
});

const removedOn = computed(() => {
    const iso = String(asset.value?.valid_to ?? '').slice(0, 10);

    return iso ? formatData(iso) : null;
});

const TIPO_EVENTO = {
    rilievo: 'Rilievo', modifica: 'Modifica', valutazione: 'VTA', lavoro: 'Lavoro', segnalazione: 'Segnalazione',
    foto: 'Foto', abbattimento: 'Abbattimento',
};

// --- Azioni -----------------------------------------------------------------
function messaggio(err, predefinito) {
    return Object.values(err.response?.data?.errors ?? {})[0]?.[0] ?? err.response?.data?.message ?? predefinito;
}

async function conAzione(fn, predefinito = 'Operazione non riuscita') {
    azione.inCorso = true;
    azione.errore = '';
    try {
        await fn();
        await carica(load);
    } catch (err) {
        azione.errore = messaggio(err, predefinito);
    } finally {
        azione.inCorso = false;
    }
}

const togglePaginaPubblica = () => conAzione(async () => {
    if (asset.value.public_token) {
        if (! window.confirm('Disattivare la pagina pubblica? Il QR già stampato smetterà di funzionare.')) return;
        await axios.delete(`/api/v1/assets/${props.assetId}/public-page`);
    } else {
        await axios.post(`/api/v1/assets/${props.assetId}/public-page`);
    }
});

const toggleNascondi = () => conAzione(() => axios.patch(`/api/v1/assets/${props.assetId}`, {
    public_hidden: ! asset.value.public_hidden, version: asset.value.version,
}));

async function scaricaPdf() {
    stampa.busy = true;
    stampa.errore = '';
    const scelte = Object.keys(stampa.sezioni).filter((k) => stampa.sezioni[k]);
    const query = scelte.length === Object.keys(SEZIONI_STAMPA).length ? '' : `?sezioni=${scelte.join(',')}`;
    const { error } = await fetchPdf(`/api/v1/assets/${props.assetId}/pdf${query}`);
    if (error) stampa.errore = error;
    stampa.busy = false;
}

async function scaricaCartellino() {
    stampa.busy = true;
    stampa.errore = '';
    const { error } = await fetchPdf(`/api/v1/assets/${props.assetId}/public-tag`);
    if (error) stampa.errore = error;
    stampa.busy = false;
}

async function apriStoria() {
    pannelli.storia = ! pannelli.storia;
    if (! pannelli.storia || storia.righe !== null) return;
    storia.caricamento = true;
    storia.errore = '';
    try {
        const { data } = await axios.get(`/api/v1/assets/${props.assetId}/versioni`);
        storia.righe = data.data;
    } catch (err) {
        storia.errore = avvisoCaricamento(err);
    } finally {
        storia.caricamento = false;
    }
}

async function caricaFoto(event) {
    const file = event.target.files?.[0];
    if (! file) return;
    foto.caricamento = true;
    foto.errore = '';
    const form = new FormData();
    form.append('photo', file);
    form.append('category', 'census');
    try {
        await axios.post(`/api/v1/assets/${props.assetId}/photos`, form);
        await carica(load);
    } catch (err) {
        foto.errore = messaggio(err, 'Errore nel caricamento');
    } finally {
        foto.caricamento = false;
        event.target.value = '';
    }
}

async function eliminaFoto(f) {
    if (! window.confirm('Eliminare questa fotografia? Sparisce anche dalle perizie non ancora validate e dal portale.')) return;
    foto.errore = '';
    try {
        await axios.delete(`/api/v1/photos/${f.id}`);
        foto.aperta = null;
        await carica(load);
    } catch (err) {
        foto.errore = messaggio(err, 'Eliminazione non riuscita');
    }
}

async function collegaVincolo() {
    if (! vincoloDaCollegare.value) return;
    try {
        const { data } = await axios.post(`/api/v1/assets/${props.assetId}/constraints`, { constraint_id: vincoloDaCollegare.value });
        vincoli.value = data.data;
        vincoloDaCollegare.value = '';
    } catch (err) {
        azione.errore = messaggio(err, 'Collegamento non riuscito');
    }
}

async function scollegaVincolo(id) {
    try {
        const { data } = await axios.delete(`/api/v1/assets/${props.assetId}/constraints/${id}`);
        vincoli.value = data.data;
    } catch (err) {
        azione.errore = messaggio(err, 'Operazione non riuscita');
    }
}

// Abbattimento/rimozione: una sola registrazione tiene allineati stato della
// scheda, data di fine validita', scheda albero e pagina pubblica col QR
const oggi = () => new Date().toISOString().slice(0, 10);
const removal = reactive({ open: false, date: oggi(), reason: '', busy: false, error: '' });
const deletion = reactive({ busy: false, error: '' });

async function registraAbbattimento() {
    removal.busy = true;
    removal.error = '';
    try {
        await axios.post(`/api/v1/assets/${props.assetId}/removal`, {
            removed_on: removal.date, removal_reason: removal.reason || null, version: asset.value.version,
        });
        removal.open = false;
        removal.reason = '';
        await carica(load);
    } catch (err) {
        removal.error = messaggio(err, 'Registrazione non riuscita');
    } finally {
        removal.busy = false;
    }
}

async function annullaAbbattimento() {
    if (! window.confirm('Annullare la registrazione dell\'abbattimento? La scheda torna attiva.')) return;
    removal.busy = true;
    removal.error = '';
    try {
        await axios.delete(`/api/v1/assets/${props.assetId}/removal`);
        await carica(load);
    } catch (err) {
        removal.error = messaggio(err, 'Operazione non riuscita');
    } finally {
        removal.busy = false;
    }
}

async function eliminaScheda() {
    if (! window.confirm(`Eliminare definitivamente ${titolo.value} dal censimento? Da usare solo per un rilievo sbagliato: se la pianta è stata abbattuta registra invece l'abbattimento.`)) return;
    deletion.busy = true;
    deletion.error = '';
    try {
        await axios.delete(`/api/v1/assets/${props.assetId}`);
        router.visit('/patrimonio');
    } catch (err) {
        deletion.error = messaggio(err, 'Eliminazione non riuscita');
        deletion.busy = false;
    }
}

// --- Mappa ------------------------------------------------------------------
const mapEl = ref(null);
let map = null;

function initMap() {
    if (! asset.value?.geom_geojson || map || ! mapEl.value) return;
    map = new maplibregl.Map({
        container: mapEl.value,
        zoom: 17,
        interactive: false,
        attributionControl: false,
        style: {
            version: 8,
            sources: { osm: { type: 'raster', tiles: ['https://tile.openstreetmap.org/{z}/{x}/{y}.png'], tileSize: 256, maxzoom: 19 } },
            layers: [
                { id: 'sfondo', type: 'background', paint: { 'background-color': '#e8ede9' } },
                { id: 'osm', type: 'raster', source: 'osm' },
            ],
        },
    });
    map.on('load', () => {
        map.addSource('geom', { type: 'geojson', data: asset.value.geom_geojson });
        map.addLayer({ id: 'geom-fill', type: 'fill', source: 'geom', filter: ['==', ['geometry-type'], 'Polygon'], paint: { 'fill-color': '#16a34a', 'fill-opacity': 0.35 } });
        map.addLayer({ id: 'geom-line', type: 'line', source: 'geom', paint: { 'line-color': '#15803d', 'line-width': 2.5 } });
        // La chioma a dimensione reale, se il diametro e' censito: stesso
        // calcolo della Mappa (un metro = 2^zoom / (78271,517 x cos(lat)) pixel)
        const diametro = Number(asset.value.tree?.crown_diameter_m) || 0;
        if (diametro > 0 && asset.value.geom_geojson.type === 'Point') {
            const cosLat = Math.cos((asset.value.geom_geojson.coordinates[1] * Math.PI) / 180);
            const pixelPerMetro = (zoom) => (2 ** zoom) / (78271.517 * cosLat);
            map.addLayer({
                id: 'chioma', type: 'circle', source: 'geom',
                paint: {
                    'circle-color': '#16a34a', 'circle-opacity': 0.16, 'circle-stroke-color': '#15803d', 'circle-stroke-opacity': 0.4, 'circle-stroke-width': 1,
                    'circle-radius': ['interpolate', ['exponential', 2], ['zoom'], 14, (diametro / 2) * pixelPerMetro(14), 20, (diametro / 2) * pixelPerMetro(20)],
                },
            });
        }
        map.addLayer({ id: 'geom-point', type: 'circle', source: 'geom', filter: ['==', ['geometry-type'], 'Point'], paint: { 'circle-color': '#16a34a', 'circle-radius': 8, 'circle-stroke-width': 2, 'circle-stroke-color': '#fff' } });
        const bounds = new maplibregl.LngLatBounds();
        const extend = (coords) => { if (typeof coords[0] === 'number') bounds.extend(coords); else coords.forEach(extend); };
        extend(asset.value.geom_geojson.coordinates);
        map.fitBounds(bounds, { padding: 40, maxZoom: 18 });
    });
}

onMounted(async () => {
    if (await carica(load)) initMap();
});
onBeforeUnmount(() => map?.remove());
</script>

<template>
    <Head :title="asset?.census_code || 'Scheda elemento'" />

    <AppLayout>
        <div class="mx-auto flex max-w-[1640px] flex-col gap-4 p-4 md:p-6 lg:px-7">
            <nav class="text-[13px] text-gray-500" aria-label="Percorso">
                <Link href="/patrimonio" class="hover:underline">Patrimonio</Link>
                <template v-if="committente"> › <span>{{ committente }}</span></template>
                <template v-if="asset?.area"> › <span>{{ asset.area.name }}</span></template>
                <template v-if="asset"> › <span class="text-gray-900">{{ titolo }}</span></template>
            </nav>

            <AvvisoErrore :messaggio="avviso" :in-corso="riprovaInCorso" @riprova="riprova" />
            <p v-if="! asset && ! avviso" class="text-sm text-gray-500">Carico la scheda…</p>

            <template v-if="asset">
                <!-- Testata -->
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div :class="ETICHETTA">{{ asset.census_code ? 'Cartellino' : asset.object_type?.code }}</div>
                        <h1 class="text-2xl font-bold text-gray-900" data-test="scheda-titolo">{{ titolo }}</h1>
                        <div class="mt-0.5 text-base text-gray-900">
                            <template v-if="albero">
                                <i v-if="albero.species">{{ albero.species }}</i><span v-else class="text-amber-800">specie da indicare</span>
                                <template v-if="albero.common_name"> · {{ albero.common_name }}</template>
                            </template>
                            <template v-else>{{ asset.object_type?.name }}</template>
                        </div>
                        <div class="mt-2 flex flex-wrap items-center gap-2 text-[13px]">
                            <span :class="inArchivio(asset.status) ? CHIP.neutra : (asset.status === 'active' ? CHIP.ok : CHIP.attenzione)" data-test="scheda-stato">{{ statusLabel(asset.status) }}</span>
                            <span v-if="statoVta" :class="CHIP[statoVta.tono]" data-test="scheda-vta">{{ statoVta.testo }}</span>
                            <span v-if="asset.public_hidden" :class="CHIP.neutra">nascosto dal portale</span>
                            <span v-if="asset.public_token" :class="CHIP.info">pagina pubblica con QR</span>
                            <span class="text-gray-500">v{{ asset.version }} · aggiornata il {{ formatData(asset.updated_at) }}</span>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Link v-if="can('works.manage') && ! inArchivio(asset.status)" :href="`/lavori?nuovo=1&elementi=${asset.id}`" :class="BOTTONE">Nuovo lavoro</Link>
                        <button v-if="canUpdate && albero" type="button" :class="BOTTONE_SECONDARIO" data-test="scheda-valuta" @click="pannelli.vta = true; $nextTick(() => document.getElementById('sezione-vta')?.scrollIntoView({ behavior: 'smooth', block: 'start' }))">Valuta VTA</button>
                        <details class="relative">
                            <summary :class="BOTTONE_SECONDARIO" class="cursor-pointer list-none">Stampa</summary>
                            <div :class="CARTA" class="absolute right-0 z-20 mt-1 w-72 p-3 text-sm shadow-lg" data-test="menu-stampa">
                                <div :class="ETICHETTA">Scheda in PDF</div>
                                <label v-for="(nome, chiave) in SEZIONI_STAMPA" :key="chiave" class="mt-1 flex min-h-9 items-center gap-2 text-[13px]">
                                    <input v-model="stampa.sezioni[chiave]" type="checkbox" class="rounded border-gray-300"> {{ nome }}
                                </label>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <button type="button" :class="BOTTONE_PICCOLO" :disabled="stampa.busy" data-test="scheda-pdf" @click="scaricaPdf">Scarica il PDF</button>
                                    <button v-if="asset.public_token" type="button" :class="BOTTONE_PICCOLO" :disabled="stampa.busy" @click="scaricaCartellino">Cartellino QR</button>
                                </div>
                                <p v-if="stampa.errore" class="mt-2 text-xs text-red-700">{{ stampa.errore }}</p>
                            </div>
                        </details>
                        <details class="relative">
                            <summary :class="BOTTONE_SECONDARIO" class="cursor-pointer list-none">Altro</summary>
                            <div :class="CARTA" class="absolute right-0 z-20 mt-1 w-80 p-2 text-sm shadow-lg" data-test="menu-altro">
                                <a v-if="streetView" :href="streetView" target="_blank" rel="noopener" class="flex min-h-11 items-center rounded-lg px-3 hover:bg-gray-50 md:min-h-9">Street View sul punto</a>
                                <a v-if="naviga" :href="naviga" target="_blank" rel="noopener" class="flex min-h-11 items-center rounded-lg px-3 hover:bg-gray-50 md:min-h-9">Naviga fino all'elemento</a>
                                <button v-if="canUpdate" type="button" class="flex min-h-11 w-full items-center rounded-lg px-3 text-left hover:bg-gray-50 md:min-h-9" :disabled="azione.inCorso" data-test="scheda-pagina-pubblica" @click="togglePaginaPubblica">{{ asset.public_token ? 'Disattiva la pagina pubblica con QR' : 'Attiva la pagina pubblica con QR' }}</button>
                                <button v-if="canUpdate" type="button" class="flex min-h-11 w-full items-center rounded-lg px-3 text-left hover:bg-gray-50 md:min-h-9" :disabled="azione.inCorso" data-test="scheda-nascondi" @click="toggleNascondi">{{ asset.public_hidden ? 'Mostra di nuovo sul portale' : 'Nascondi dal portale' }}</button>
                                <button type="button" class="flex min-h-11 w-full items-center rounded-lg px-3 text-left hover:bg-gray-50 md:min-h-9" data-test="scheda-storia" @click="apriStoria">{{ pannelli.storia ? 'Chiudi la storia delle modifiche' : 'Storia delle modifiche' }}</button>
                                <button v-if="can('works.manage')" type="button" class="flex min-h-11 w-full items-center rounded-lg px-3 text-left hover:bg-gray-50 md:min-h-9" @click="pannelli.gestionale = ! pannelli.gestionale">{{ pannelli.gestionale ? 'Chiudi invio al gestionale' : 'Invia al gestionale' }}</button>
                                <button v-if="canUpdate || canDelete" type="button" class="flex min-h-11 w-full items-center rounded-lg px-3 text-left hover:bg-gray-50 md:min-h-9" data-test="scheda-fine-vita" @click="pannelli.fineVita = ! pannelli.fineVita">Abbattimento ed eliminazione</button>
                                <Link :href="`/censimento/${asset.id}?precedente=1`" class="flex min-h-11 items-center rounded-lg border-t border-gray-100 px-3 text-gray-600 hover:bg-gray-50 md:min-h-9">Scheda della veste precedente</Link>
                            </div>
                        </details>
                    </div>
                </div>

                <p v-if="azione.errore" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" data-test="scheda-azione-errore">{{ azione.errore }}</p>
                <p v-if="asset.status === 'removed'" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900" data-test="banner-abbattuto">
                    Elemento abbattuto o rimosso<template v-if="removedOn"> il {{ removedOn }}</template>: la scheda resta in archivio con la sua storia.
                    <template v-if="asset.removal_reason"> Motivo: {{ asset.removal_reason }}.</template>
                </p>
                <p v-else-if="asset.status === 'dismissed'" class="rounded-lg border border-gray-200 bg-gray-100 px-3 py-2 text-sm text-gray-700" data-test="banner-dismesso">
                    Scheda dismessa: fuori dal lavoro di tutti i giorni, consultabile in archivio. Si può rimettere attiva da "Modifica scheda".
                </p>

                <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_380px] lg:items-start">
                    <div class="flex min-w-0 flex-col gap-4">
                        <!-- Misure -->
                        <section :class="CARTA" class="p-4" data-test="sezione-misure">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <h2 class="text-base font-bold text-gray-900">{{ albero ? 'Misure' : 'Misura e geometria' }}</h2>
                                <button v-if="canUpdate && albero && ! modifica.misure" type="button" :class="BOTTONE_PICCOLO" data-test="modifica-misure" @click="modifica.misure = true">Modifica</button>
                            </div>
                            <template v-if="albero">
                                <ModuloAlbero v-if="modifica.misure" :key="`misure-${asset.version}`" :asset="asset" sezione="misure" class="mt-3" @saved="onSaved" @close="modifica.misure = false" />
                                <dl v-else class="mt-3 grid grid-cols-2 gap-x-4 gap-y-3 sm:grid-cols-3 lg:grid-cols-5">
                                    <div><dt class="text-xs text-gray-500">Altezza</dt><dd class="text-lg font-bold text-gray-900">{{ misura(albero.height_m, 'm') }}</dd></div>
                                    <div><dt class="text-xs text-gray-500">Diametro fusto</dt><dd class="text-lg font-bold text-gray-900">{{ misura(albero.dbh_cm, 'cm', 0) }}</dd></div>
                                    <div><dt class="text-xs text-gray-500">Chioma</dt><dd class="text-lg font-bold text-gray-900">{{ misura(albero.crown_diameter_m, 'm') }}</dd></div>
                                    <div><dt class="text-xs text-gray-500">Età stimata</dt><dd class="text-lg font-bold text-gray-900">{{ albero.age_years_est ? `${albero.age_qualifier === 'stimata' ? '~' : ''}${albero.age_years_est} anni` : '—' }}</dd></div>
                                    <div><dt class="text-xs text-gray-500">Stato vegetativo</dt><dd class="text-lg font-bold text-gray-900">{{ voce('stato_vegetativo', albero.vegetative_state) }}</dd></div>
                                </dl>
                                <p v-if="! modifica.misure" class="mt-3 text-[13px] text-gray-500">
                                    Circonferenza {{ misura(albero.trunk_circumference_cm, 'cm', 0) }} · {{ albero.trunk_count ?? 1 }} {{ plurale(albero.trunk_count ?? 1, 'fusto', 'fusti') }} · inserzione chioma {{ misura(albero.crown_insertion_m, 'm') }}
                                    <template v-if="albero.age_qualifier"> · età {{ voce('qualificatore_eta', albero.age_qualifier) }}</template>
                                    <template v-if="asset.surveyed_at"> · rilievo del {{ formatData(asset.surveyed_at) }}</template>
                                    · scheda aggiornata il {{ formatData(asset.updated_at) }}
                                </p>
                                <div v-if="asset.benefici?.voci?.length || asset.co2?.biomassa_kg" class="mt-3 rounded-lg bg-gray-50 px-3 py-2 text-[13px] text-gray-700" data-test="benefici-ambientali">
                                    <span v-if="asset.co2?.biomassa_kg">CO₂ immagazzinata: <strong>{{ num(asset.co2.biomassa_kg, 0) }} kg</strong><template v-if="asset.co2.annuo_kg"> · assorbimento: <strong>{{ num(asset.co2.annuo_kg, 0) }} kg/anno</strong></template></span>
                                    <span v-for="(v, i) in (asset.benefici?.voci ?? [])" :key="v.chiave"><template v-if="i || asset.co2?.biomassa_kg"> · </template>{{ v.etichetta }}: <strong>{{ num(v.valore, v.valore < 10 ? 1 : 0) }} {{ v.unita }}</strong></span>
                                    <span class="mt-1 block text-xs text-gray-500">Valori stimati, non misurati{{ asset.benefici?.metodo ? `: ${asset.benefici.metodo}` : '' }}. Escono sul portale solo se accesi per il committente.</span>
                                </div>
                            </template>
                            <dl v-else class="mt-3 grid grid-cols-2 gap-x-4 gap-y-3 sm:grid-cols-3">
                                <div><dt class="text-xs text-gray-500">Misura</dt><dd class="text-lg font-bold text-gray-900">{{ misuraGeometrica ?? '—' }}</dd></div>
                                <div><dt class="text-xs text-gray-500">Geometria</dt><dd class="text-lg font-bold text-gray-900">{{ asset.geom_geojson?.type ?? '—' }}</dd></div>
                                <div><dt class="text-xs text-gray-500">Tipo</dt><dd class="text-lg font-bold text-gray-900">{{ asset.object_type?.code }}</dd></div>
                            </dl>
                        </section>

                        <!-- Identita' e posizione -->
                        <section :class="CARTA" class="p-4" data-test="sezione-identita">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <h2 class="text-base font-bold text-gray-900">Identità e posizione</h2>
                                <div v-if="canUpdate" class="flex flex-wrap gap-2">
                                    <button v-if="albero && ! modifica.identita" type="button" :class="BOTTONE_PICCOLO" data-test="modifica-identita" @click="modifica.identita = true">Modifica</button>
                                    <button v-if="! modifica.scheda" type="button" :class="BOTTONE_PICCOLO" data-test="modifica-scheda" @click="modifica.scheda = true">{{ albero ? 'Cartellino, stato, area e note' : 'Modifica' }}</button>
                                </div>
                            </div>
                            <ModuloAlbero v-if="modifica.identita && albero" :key="`identita-${asset.version}`" :asset="asset" sezione="identita" class="mt-3" @saved="onSaved" @close="modifica.identita = false" />
                            <AssetEditPanel v-if="modifica.scheda" :key="`scheda-${asset.version}`" :asset="asset" class="mt-3" @saved="onSaved" @close="modifica.scheda = false" />
                            <dl class="mt-3 grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                                <template v-if="albero">
                                    <div><dt class="text-xs text-gray-500">Genere e specie</dt><dd class="font-semibold text-gray-900"><i>{{ nomeBotanico || '—' }}</i>{{ albero.cultivar ? ` '${albero.cultivar}'` : '' }}</dd></div>
                                    <div><dt class="text-xs text-gray-500">Famiglia</dt><dd class="font-semibold text-gray-900">{{ albero.family || '—' }}</dd></div>
                                    <div><dt class="text-xs text-gray-500">Nome comune</dt><dd class="font-semibold text-gray-900">{{ albero.common_name || '—' }}</dd></div>
                                    <div><dt class="text-xs text-gray-500">Fase fisiologica</dt><dd class="font-semibold text-gray-900">{{ voce('fase_fisiologica', albero.age_class) }}</dd></div>
                                </template>
                                <div><dt class="text-xs text-gray-500">Area</dt><dd class="font-semibold text-gray-900">{{ asset.area?.name ?? '—' }}<span v-if="asset.area?.locality?.name" class="font-normal text-gray-500"> · {{ asset.area.locality.name }}</span></dd></div>
                                <div v-if="committente"><dt class="text-xs text-gray-500">Committente</dt><dd class="font-semibold text-gray-900">{{ committente }}</dd></div>
                                <template v-if="albero">
                                    <div><dt class="text-xs text-gray-500">Sito di crescita</dt><dd class="font-semibold text-gray-900">{{ voce('sito_di_crescita', albero.growth_site) }}</dd></div>
                                    <div><dt class="text-xs text-gray-500">Posizione sociale</dt><dd class="font-semibold text-gray-900">{{ voce('posizione_sociale', albero.social_position) }}</dd></div>
                                    <div><dt class="text-xs text-gray-500">Bersaglio</dt><dd class="font-semibold text-gray-900">{{ voce('bersaglio', albero.target) }}</dd></div>
                                </template>
                                <div><dt class="text-xs text-gray-500">Coordinate{{ asset.geom_geojson?.type !== 'Point' ? ' (primo vertice)' : '' }}</dt><dd class="font-semibold text-gray-900" data-test="scheda-coordinate">{{ coordinate }}</dd></div>
                                <div><dt class="text-xs text-gray-500">Rilievo</dt><dd class="font-semibold text-gray-900">{{ formatData(asset.surveyed_at) }}<span v-if="metodoRilievo" class="font-normal text-gray-500"> · {{ metodoRilievo }}</span></dd></div>
                                <template v-if="albero">
                                    <div><dt class="text-xs text-gray-500">Tutele</dt><dd class="font-semibold text-gray-900">{{ tutele }}</dd></div>
                                    <div><dt class="text-xs text-gray-500">Sostegni</dt><dd class="font-semibold text-gray-900">{{ sostegni }}</dd></div>
                                    <div v-if="albero.planted_on"><dt class="text-xs text-gray-500">Data di impianto</dt><dd class="font-semibold text-gray-900">{{ formatData(albero.planted_on) }}</dd></div>
                                </template>
                                <div v-if="asset.tags?.length"><dt class="text-xs text-gray-500">Cartellini fisici</dt><dd class="font-semibold text-gray-900">{{ asset.tags.map((t) => `${t.tag_type.toUpperCase()} ${t.uid}`).join(' · ') }}</dd></div>
                                <div v-for="a in attributi" :key="a.chiave"><dt class="text-xs text-gray-500">{{ a.etichetta }}</dt><dd class="font-semibold text-gray-900">{{ a.valore }}</dd></div>
                            </dl>
                            <div v-if="asset.notes" class="mt-3">
                                <div class="text-xs text-gray-500">Note</div>
                                <p class="whitespace-pre-line text-sm text-gray-900">{{ asset.notes }}</p>
                            </div>
                            <!-- Vincoli del territorio -->
                            <div class="mt-3 border-t border-gray-100 pt-3" data-test="scheda-vincoli">
                                <div class="text-xs text-gray-500">Vincoli territoriali ({{ vincoli.length }})</div>
                                <ul v-if="vincoli.length" class="mt-1 space-y-1 text-sm">
                                    <li v-for="v in vincoli" :key="v.id" class="flex flex-wrap items-center gap-2">
                                        <span class="font-semibold text-gray-900">{{ v.code }}</span><span class="text-gray-600">{{ v.name }}</span>
                                        <span :class="CHIP.neutra">{{ v.source === 'spatial' ? 'da perimetro' : 'a mano' }}</span>
                                        <span v-if="! v.is_public" :class="CHIP.attenzione">non pubblico</span>
                                        <button v-if="canUpdate && v.source === 'manual'" type="button" class="min-h-9 text-[13px] text-red-700 underline-offset-2 hover:underline" @click="scollegaVincolo(v.id)">scollega</button>
                                    </li>
                                </ul>
                                <p v-else class="mt-1 text-sm text-gray-500">Nessun vincolo collegato.</p>
                                <div v-if="canUpdate && vincoliDisponibili.length" class="mt-2 flex flex-wrap gap-2">
                                    <select v-model="vincoloDaCollegare" class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm sm:w-auto" aria-label="Vincolo da collegare">
                                        <option value="">Collega un vincolo…</option>
                                        <option v-for="v in vincoliDisponibili" :key="v.id" :value="v.id">{{ v.code }}<template v-if="v.name"> — {{ v.name }}</template></option>
                                    </select>
                                    <button type="button" :class="BOTTONE_PICCOLO" :disabled="! vincoloDaCollegare" @click="collegaVincolo">Collega</button>
                                </div>
                            </div>
                        </section>

                        <!-- Stabilita' (VTA) -->
                        <section v-if="albero" id="sezione-vta" :class="CARTA" class="p-4" data-test="sezione-vta">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <h2 class="text-base font-bold text-gray-900">Stabilità (VTA)</h2>
                                <button type="button" :class="BOTTONE_PICCOLO" :aria-expanded="pannelli.vta" data-test="apri-valutazioni" @click="pannelli.vta = ! pannelli.vta">
                                    {{ pannelli.vta ? 'Chiudi le valutazioni' : (canUpdate ? 'Valutazioni e nuova valutazione' : 'Tutte le valutazioni') }}
                                </button>
                            </div>
                            <div v-if="ultimaVta" class="mt-3 flex flex-wrap items-start gap-4">
                                <div class="shrink-0"><div class="text-xs text-gray-500">Classe</div><div class="text-3xl font-bold text-gray-900">{{ ultimaVta.failure_class ?? 'n.d.' }}</div></div>
                                <div class="min-w-0 flex-1 text-sm">
                                    <div class="font-semibold text-gray-900">
                                        Valutazione del {{ formatData(ultimaVta.assessed_on) }}
                                        <template v-if="ultimaVta.assessor?.name || ultimaVta.assessor_external"> · {{ ultimaVta.assessor?.name ?? ultimaVta.assessor_external }}</template>
                                        <template v-if="TIPI_VALUTAZIONE[ultimaVta.assessment_type]"> · {{ TIPI_VALUTAZIONE[ultimaVta.assessment_type] }}</template>
                                        <span v-if="ultimaVta.validated_at" :class="CHIP.ok" class="ml-1">validata</span>
                                        <span v-if="ultimaVta.report_number" class="ml-1 text-gray-500">perizia n. {{ ultimaVta.report_number }}</span>
                                    </div>
                                    <p v-if="ultimaVta.outcome" class="text-gray-700">Esito: {{ ESITI[ultimaVta.outcome] ?? ultimaVta.outcome }}</p>
                                    <p v-if="ultimaVta.prescriptions" class="whitespace-pre-line text-gray-700">Prescrizioni: {{ ultimaVta.prescriptions }}</p>
                                    <p class="mt-1" :class="statoVta?.tono === 'errore' ? 'font-semibold text-red-800' : 'text-gray-700'">
                                        {{ statoVta?.testo.replace(/^VTA classe [^·]+ · /, '').replace(/^\w/, (c) => c.toUpperCase()) }}<template v-if="ricontrolloInAgenda"> · in agenda: <Link :href="ricontrolloInAgenda.href" class="text-green-800 underline-offset-2 hover:underline">{{ ricontrolloInAgenda.codice }}</Link></template><template v-else-if="statoVta?.tono === 'errore'"> · senza ordine in agenda</template>
                                    </p>
                                    <p v-if="valutazioni.length > 1" class="text-[13px] text-gray-500">{{ valutazioni.length }} valutazioni registrate.</p>
                                </div>
                            </div>
                            <p v-else class="mt-2 text-sm text-gray-600">Nessuna valutazione di stabilità registrata: l'albero non è mai stato valutato.</p>
                            <TreeVtaPanel
                                v-if="pannelli.vta"
                                :key="`vta-${asset.version}`"
                                :asset="asset"
                                :can-update="canUpdate"
                                :apri-valutazione="apriValutazione"
                                solo-vta
                                nudo
                                class="mt-3 border-t border-gray-100 pt-3"
                                @saved="onSaved"
                            />
                        </section>

                        <!-- Lavori e segnalazioni -->
                        <section :class="CARTA" data-test="sezione-lavori">
                            <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3">
                                <h2 class="text-base font-bold text-gray-900">Lavori e segnalazioni</h2>
                                <Link v-if="can('works.view')" href="/lavori" :class="BOTTONE_PICCOLO">Vedi tutti i lavori</Link>
                            </div>
                            <div v-if="lavoriESegnalazioni.length" class="overflow-x-auto border-t border-gray-100">
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                            <th class="px-4 py-2">Codice</th><th class="px-3 py-2">Che cosa</th><th class="px-3 py-2">Stato</th><th class="px-3 py-2">Periodo</th><th class="px-3 py-2">Squadra</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <tr v-for="e in lavoriESegnalazioni" :key="`${e.tipo}-${e.id}`">
                                            <td class="whitespace-nowrap px-4 py-2 font-semibold text-gray-900"><Link v-if="e.href" :href="e.href" class="underline-offset-2 hover:underline">{{ e.codice }}</Link><template v-else>{{ e.codice }}</template></td>
                                            <td class="px-3 py-2 text-gray-900">{{ e.tipo === 'segnalazione' ? e.dettaglio.split(' · ').slice(2).join(' · ') || e.titolo : e.titolo }}<span v-if="e.tipo === 'segnalazione'" class="text-gray-500"> · segnalazione, gravità {{ e.gravita }}</span></td>
                                            <td class="whitespace-nowrap px-3 py-2 text-gray-700">{{ e.stato_etichetta }}</td>
                                            <td class="whitespace-nowrap px-3 py-2 text-gray-700">{{ e.periodo ?? formatData(e.data) }}</td>
                                            <td class="whitespace-nowrap px-3 py-2 text-gray-700">{{ e.squadra ?? '—' }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <p v-else class="border-t border-gray-100 px-4 py-3 text-sm text-gray-500">Nessun lavoro né segnalazione su questo elemento.</p>
                        </section>

                        <PlantingSitePanel v-if="asset.planting_site" :key="`posto-${asset.version}`" :asset="asset" :can-update="canUpdate" @saved="onSaved" />

                        <!-- Pannelli aperti da "Altro" -->
                        <section v-if="pannelli.storia" :class="CARTA" class="p-4" data-test="storia-modifiche">
                            <h2 class="text-base font-bold text-gray-900">Storia delle modifiche</h2>
                            <p v-if="storia.caricamento" class="mt-2 text-sm text-gray-500">Carico la storia…</p>
                            <p v-else-if="storia.errore" class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ storia.errore }}</p>
                            <p v-else-if="! storia.righe?.length" class="mt-2 text-sm text-gray-500">Nessuna modifica registrata: la scheda è ancora come è stata creata.</p>
                            <div v-else class="mt-3 space-y-3">
                                <div v-for="r in storia.righe" :key="r.versione" class="rounded-lg border border-gray-100">
                                    <div class="flex flex-wrap items-center gap-2 border-b border-gray-100 bg-gray-50 px-3 py-1.5 text-xs text-gray-600">
                                        <span class="font-semibold text-gray-800">{{ r.quando }}</span><span v-if="r.chi">{{ r.chi }}</span>
                                        <span v-if="r.origine && r.origine !== 'web'" :class="CHIP.neutra">{{ r.origine }}</span>
                                    </div>
                                    <div class="overflow-x-auto">
                                        <table class="w-full text-xs">
                                            <tbody>
                                                <tr v-for="m in r.modifiche" :key="m.campo" class="border-b border-gray-50 last:border-0">
                                                    <td class="px-3 py-1.5 font-semibold text-gray-700">{{ m.campo }}</td>
                                                    <td class="px-3 py-1.5 text-gray-500"><span v-if="m.prima !== null" class="line-through decoration-red-300">{{ m.prima }}</span><span v-else>—</span></td>
                                                    <td class="px-3 py-1.5"><span class="rounded bg-green-100 px-1.5 py-0.5 text-green-900">{{ m.dopo ?? '—' }}</span></td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <GestionalePanel v-if="pannelli.gestionale" :asset="asset" />

                        <section v-if="pannelli.fineVita && (canUpdate || canDelete)" :class="CARTA" class="p-4" data-test="sezione-fine-vita">
                            <h2 class="text-base font-bold text-gray-900">Abbattimento ed eliminazione</h2>
                            <template v-if="canUpdate">
                                <div v-if="asset.status === 'removed'" class="mt-2 flex flex-wrap items-center gap-3">
                                    <p class="text-sm text-gray-700">L'abbattimento è registrato<template v-if="removedOn"> con data {{ removedOn }}</template>.</p>
                                    <button type="button" :class="BOTTONE_SECONDARIO" :disabled="removal.busy" data-test="annulla-abbattimento" @click="annullaAbbattimento">Annulla abbattimento</button>
                                </div>
                                <template v-else>
                                    <p class="mt-1 text-sm text-gray-700">La pianta è stata abbattuta o l'elemento rimosso? Registralo qui: la scheda resta in archivio con la sua storia, esce dal censimento attivo e dal bilancio arboreo, e la pagina pubblica col QR viene spenta.</p>
                                    <button v-if="! removal.open" type="button" :class="BOTTONE_SECONDARIO" class="mt-3" data-test="registra-abbattimento" @click="removal.open = true">Registra abbattimento</button>
                                    <div v-else class="mt-3 rounded-lg border border-amber-200 bg-amber-50/50 p-3">
                                        <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                                            <label class="block text-xs"><span class="text-gray-600">Data dell'abbattimento</span><input v-model="removal.date" type="date" data-test="data-abbattimento" class="mt-1 w-full rounded-lg border border-gray-300 bg-white px-2.5 py-2 text-sm"></label>
                                            <label class="block text-xs md:col-span-2"><span class="text-gray-600">Motivo (facoltativo)</span><input v-model="removal.reason" placeholder="es. classe di propensione al cedimento D, schianto, cantiere" data-test="motivo-abbattimento" class="mt-1 w-full rounded-lg border border-gray-300 bg-white px-2.5 py-2 text-sm"></label>
                                        </div>
                                        <div class="mt-3 flex flex-wrap gap-2">
                                            <button type="button" :class="BOTTONE" class="!border-amber-700 !bg-amber-700 hover:!bg-amber-800" :disabled="removal.busy || ! removal.date" data-test="conferma-abbattimento" @click="registraAbbattimento">{{ removal.busy ? 'Registrazione…' : 'Conferma abbattimento' }}</button>
                                            <button type="button" :class="BOTTONE_SECONDARIO" @click="removal.open = false">Annulla</button>
                                        </div>
                                    </div>
                                </template>
                                <p v-if="removal.error" class="mt-2 text-sm text-red-700" data-test="errore-abbattimento">{{ removal.error }}</p>
                            </template>
                            <div v-if="canDelete" class="mt-4 border-t border-gray-100 pt-3">
                                <p class="text-sm text-gray-700">Se questa scheda è stata creata per sbaglio (doppione, punto messo nel posto errato) si può eliminare. Non è possibile quando l'elemento è già entrato da qualche parte: ordini di lavoro, preventivi, rapportini, ispezioni, segnalazioni, non conformità, trattamenti, valutazioni di stabilità, invii al gestionale, cartellini ancora associati.</p>
                                <p class="mt-2 text-sm text-gray-700" data-test="spiegazione-archivio">Per una scheda che non si può eliminare ma non va più gestita c'è l'archivio: da "Cartellino, stato, area e note" si imposta lo stato su "Dismesso". Esce dall'elenco di tutti i giorni e dalle planimetrie, resta consultabile in archivio e si può sempre rimettere attiva.</p>
                                <button type="button" :class="BOTTONE_SECONDARIO" class="mt-3 !border-red-300 !text-red-700 hover:!bg-red-50" :disabled="deletion.busy" data-test="elimina-scheda" @click="eliminaScheda">{{ deletion.busy ? 'Eliminazione…' : 'Elimina scheda' }}</button>
                                <p v-if="deletion.error" class="mt-2 text-sm text-red-700" data-test="errore-eliminazione">{{ deletion.error }}</p>
                            </div>
                        </section>
                    </div>

                    <!-- Colonna laterale -->
                    <aside class="flex min-w-0 flex-col gap-4">
                        <section :class="CARTA" class="p-4" data-test="sezione-foto">
                            <div class="overflow-hidden rounded-lg bg-gray-100" style="aspect-ratio: 4 / 3">
                                <button v-if="asset.photos?.length" type="button" class="block h-full w-full" @click="foto.aperta = foto.aperta ?? asset.photos[0]">
                                    <img :src="asset.photos[0].url" :alt="`Fotografia di ${titolo}`" class="h-full w-full object-cover">
                                </button>
                                <div v-else class="flex h-full items-center justify-center text-sm text-gray-500">Nessuna fotografia</div>
                            </div>
                            <div class="mt-2 flex flex-wrap items-center justify-between gap-2 text-[13px] text-gray-500">
                                <span v-if="asset.photos?.length">Foto del {{ formatData(asset.photos[0].taken_at ?? asset.photos[0].created_at) }} · {{ asset.photos.length }} {{ plurale(asset.photos.length, 'foto', 'foto') }}</span>
                                <span v-else>Ancora nessuna fotografia caricata.</span>
                                <label v-if="canUpdate" :class="BOTTONE_PICCOLO" class="cursor-pointer">
                                    {{ foto.caricamento ? 'Caricamento…' : 'Aggiungi foto' }}
                                    <input type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" :disabled="foto.caricamento" data-test="carica-foto" @change="caricaFoto">
                                </label>
                            </div>
                            <p v-if="foto.errore" class="mt-1 text-[13px] text-red-700">{{ foto.errore }}</p>
                            <div v-if="asset.photos?.length > 1" class="mt-2 grid grid-cols-4 gap-1.5">
                                <button v-for="f in asset.photos.slice(0, 8)" :key="f.id" type="button" class="aspect-square overflow-hidden rounded bg-gray-100" :aria-label="`Apri la foto del ${formatData(f.taken_at ?? f.created_at)}`" @click="foto.aperta = f">
                                    <img :src="f.url" :alt="f.original_filename" class="h-full w-full object-cover" loading="lazy">
                                </button>
                            </div>
                        </section>

                        <section :class="CARTA" class="overflow-hidden" data-test="sezione-mappa">
                            <div ref="mapEl" class="h-56 w-full bg-[#e8ede9]" />
                            <div class="flex flex-wrap gap-x-3 gap-y-1 px-4 py-2.5 text-[13px]">
                                <Link href="/mappa" class="min-h-9 py-2 font-semibold text-green-800 underline-offset-2 hover:underline">Apri sulla mappa</Link>
                                <a v-if="streetView" :href="streetView" target="_blank" rel="noopener" class="min-h-9 py-2 font-semibold text-green-800 underline-offset-2 hover:underline">Street View</a>
                                <a v-if="naviga" :href="naviga" target="_blank" rel="noopener" class="min-h-9 py-2 font-semibold text-green-800 underline-offset-2 hover:underline">Naviga</a>
                            </div>
                        </section>

                        <section :class="CARTA" class="p-4" data-test="sezione-cronologia">
                            <h2 class="text-base font-bold text-gray-900">Cronologia</h2>
                            <ul v-if="cronologia?.eventi?.length" class="mt-1 divide-y divide-gray-100">
                                <li v-for="(e, i) in cronologia.eventi" :key="i" class="flex gap-3 py-2">
                                    <span class="w-20 shrink-0 pt-0.5 text-xs text-gray-500">{{ formatData(e.data) }}</span>
                                    <div class="min-w-0 flex-1">
                                        <div class="text-sm font-semibold text-gray-900">
                                            <Link v-if="e.href && e.tipo !== 'valutazione'" :href="e.href" class="underline-offset-2 hover:underline">{{ e.titolo }}</Link>
                                            <template v-else>{{ e.titolo }}</template>
                                        </div>
                                        <div class="text-[13px] text-gray-500">{{ TIPO_EVENTO[e.tipo] ?? e.tipo }}{{ e.dettaglio ? ` · ${e.dettaglio}` : '' }}</div>
                                        <div v-if="e.foto?.length" class="mt-1 flex gap-1">
                                            <button v-for="f in e.foto" :key="f.id" type="button" class="h-11 w-11 overflow-hidden rounded bg-gray-100" aria-label="Apri la fotografia" @click="foto.aperta = asset.photos?.find((p) => p.id === f.id) ?? f">
                                                <img :src="f.url" alt="" class="h-full w-full object-cover" loading="lazy">
                                            </button>
                                        </div>
                                    </div>
                                </li>
                            </ul>
                            <p v-else class="mt-1 text-sm text-gray-500">Nessun evento registrato.</p>
                            <p v-if="cronologia && cronologia.totale > cronologia.eventi.length" class="mt-1 text-[13px] text-gray-500">Sono mostrati gli ultimi {{ cronologia.eventi.length }} eventi su {{ cronologia.totale }}.</p>
                        </section>
                    </aside>
                </div>
            </template>
        </div>

        <!-- Fotografia a tutto schermo -->
        <Teleport to="body">
            <div v-if="foto.aperta" class="fixed inset-0 z-50 flex flex-col bg-black/90 p-3" data-test="foto-aperta" @click.self="foto.aperta = null">
                <div class="flex items-center justify-between gap-2 text-sm text-white">
                    <span>Foto del {{ formatData(foto.aperta.taken_at ?? foto.aperta.created_at) }}<template v-if="foto.aperta.category"> · {{ foto.aperta.category }}</template></span>
                    <div class="flex gap-2">
                        <a :href="foto.aperta.url" target="_blank" rel="noopener" class="min-h-11 rounded-lg border border-white/40 px-3 py-2">Apri l'originale</a>
                        <button v-if="canUpdate && foto.aperta.id" type="button" class="min-h-11 rounded-lg border border-red-300 px-3 py-2 text-red-200" data-test="elimina-foto" @click="eliminaFoto(foto.aperta)">Elimina</button>
                        <button type="button" class="min-h-11 rounded-lg border border-white/40 px-3 py-2" @click="foto.aperta = null">✕ Chiudi</button>
                    </div>
                </div>
                <img :src="foto.aperta.url" alt="" class="mt-3 min-h-0 flex-1 object-contain">
            </div>
        </Teleport>
    </AppLayout>
</template>
