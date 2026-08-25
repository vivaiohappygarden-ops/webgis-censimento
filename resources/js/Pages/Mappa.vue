<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import * as maplibregl from 'maplibre-gl';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import AvvisoErrore from '@/Components/AvvisoErrore.vue';
import { usaCaricamento } from '@/caricamento';
import { statusLabel } from '@/assetStatus';

const page = usePage();
const permissions = computed(() => page.props.auth?.user?.permissions ?? []);

// Se aree, catalogo o committenti non arrivano, i filtri e i tipi da disegnare
// restano vuoti: senza avviso sembrerebbe che non ci sia nulla da scegliere
const { avviso, riprovaInCorso, carica, riprova } = usaCaricamento();
const canCreate = computed(() => permissions.value.includes('assets.create'));
const canViewClients = computed(() => permissions.value.includes('clients.view'));

const mapEl = ref(null);
let map = null;
let geolocate = null;

const TP = [
    { code: '1', label: 'Vegetazione', color: '#16a34a' },
    { code: '2', label: 'Arredo urbano', color: '#475569' },
    { code: '3', label: 'Fruizione e gestione', color: '#d97706' },
    { code: '4', label: 'Fattori ambientali', color: '#dc2626' },
];
const tpVisible = reactive({ 1: true, 2: true, 3: true, 4: true });

const colorExpr = [
    'match', ['slice', ['get', 'type_code'], 1, 2],
    '1', '#16a34a', '2', '#475569', '3', '#d97706', '4', '#dc2626',
    '#2563eb',
];

const selected = ref(null);
const areas = ref([]);
const clients = ref([]);
const localities = ref([]);
const pointTypes = ref([]);

// Vista: di chi guardo il verde e dove. Gli abbattuti restano in archivio
// ma non sulla mappa di tutti i giorni
const vista = reactive({ clientId: '', areaId: '', showRemoved: false, busy: false });

// Chiome a dimensione reale: il cerchio cresce con il diametro censito
const chiomeVisibili = ref(true);

function applicaChiome() {
    map?.setLayoutProperty('chiome', 'visibility', chiomeVisibili.value ? 'visible' : 'none');
}
// Posizione del dispositivo: il browser la dà solo su siti sicuri (https),
// quindi il motivo del rifiuto va spiegato, non nascosto
const posizione = reactive({ error: '', located: false, cercando: false });
let posizioneTimer = null;
const creating = reactive({ active: false, typeId: '', areaId: '', saving: false, message: '', ok: false });
const drawing = reactive({
    active: false, vertices: [], name: '', code: '', localityId: '', saving: false, message: '', ok: false,
});
const canCreateAreas = computed(() => (page.props.auth?.user?.permissions ?? []).includes('areas.create'));
const assetLayers = ['assets-fill', 'assets-line', 'assets-point'];

const tilesUrl = () => {
    const params = new URLSearchParams({ v: String(Date.now()) });
    if (vista.clientId) params.set('client_id', vista.clientId);
    if (vista.areaId) params.set('area_id', vista.areaId);
    if (! vista.showRemoved) params.set('hide_removed', '1');

    return `${window.location.origin}/api/v1/tiles/assets/{z}/{x}/{y}?${params.toString()}`;
};

function applyVisibility() {
    const active = TP.filter((t) => tpVisible[t.code]).map((t) => t.code);
    const tpFilter = ['in', ['slice', ['get', 'type_code'], 1, 2], ['literal', active]];
    map.setFilter('assets-fill', ['all', ['==', ['geometry-type'], 'Polygon'], tpFilter]);
    map.setFilter('assets-line', ['all', ['==', ['geometry-type'], 'LineString'], tpFilter]);
    map.setFilter('assets-point', ['all', ['==', ['geometry-type'], 'Point'], tpFilter]);
    // Le chiome seguono la categoria del loro albero: spegnendo la
    // Vegetazione non devono restare cerchi orfani sulla mappa
    map.setFilter('chiome', ['all',
        ['==', ['geometry-type'], 'Point'],
        ['>', ['coalesce', ['to-number', ['get', 'chioma_m']], 0], 0],
        tpFilter,
    ]);
}

function refreshTiles() {
    map.getSource('assets').setTiles([tilesUrl()]);
}

function updateDrawPreview() {
    const pts = drawing.vertices;
    const features = [];
    if (pts.length >= 1) {
        features.push({ type: 'Feature', geometry: { type: 'MultiPoint', coordinates: pts }, properties: {} });
    }
    if (pts.length >= 2) {
        features.push({ type: 'Feature', geometry: { type: 'LineString', coordinates: [...pts, pts[0]] }, properties: {} });
    }
    if (pts.length >= 3) {
        features.push({ type: 'Feature', geometry: { type: 'Polygon', coordinates: [[...pts, pts[0]]] }, properties: {} });
    }
    map.getSource('draw')?.setData({ type: 'FeatureCollection', features });
}

function resetDrawing() {
    Object.assign(drawing, { vertices: [], name: '', code: '', message: '' });
    updateDrawPreview();
}

async function saveDrawnArea() {
    if (drawing.vertices.length < 3 || !drawing.localityId || !drawing.name) {
        drawing.ok = false;
        drawing.message = 'Servono almeno 3 vertici, una località e un nome.';
        return;
    }
    drawing.saving = true;
    drawing.message = '';
    try {
        const ring = [...drawing.vertices, drawing.vertices[0]];
        const { data } = await axios.post('/api/v1/areas', {
            locality_id: drawing.localityId,
            name: drawing.name,
            code: drawing.code || null,
            geometry: { type: 'Polygon', coordinates: [ring] },
        });
        areas.value.push(data.data);
        refreshAreasSource();
        drawing.ok = true;
        drawing.message = `Area salvata (${Number(data.data.computed_area_sqm).toLocaleString('it-IT')} m²)`;
        Object.assign(drawing, { vertices: [], name: '', code: '' });
        updateDrawPreview();
    } catch (err) {
        drawing.ok = false;
        drawing.message = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
            ?? err.response?.data?.message ?? 'Errore nel salvataggio';
    } finally {
        drawing.saving = false;
    }
}

// Le aree disegnate: quelle del committente scelto, o la sola area scelta
const visibleAreas = computed(() => (vista.areaId
    ? areas.value.filter((a) => a.id === vista.areaId)
    : areas.value));

function refreshAreasSource() {
    const features = visibleAreas.value
        .filter((a) => a.geom_geojson)
        .map((a) => ({ type: 'Feature', geometry: a.geom_geojson, properties: { id: a.id, name: a.name } }));
    map.getSource('aree')?.setData({ type: 'FeatureCollection', features });
}

/** Inquadra le aree visibili; restituisce false se non c'è niente da inquadrare. */
function fitToAreas(maxZoom = 17) {
    const geoms = visibleAreas.value.map((a) => a.geom_geojson).filter(Boolean);
    if (! geoms.length || ! map) return false;

    const bounds = new maplibregl.LngLatBounds();
    const extend = (coords) => {
        if (typeof coords[0] === 'number') bounds.extend(coords);
        else coords.forEach(extend);
    };
    geoms.forEach((g) => extend(g.coordinates));
    map.fitBounds(bounds, { padding: 60, maxZoom });

    return true;
}

async function loadAreas() {
    areas.value = await tutteLePagine('/api/v1/areas', { client_id: vista.clientId || undefined });
    // L'area scelta prima può non essere di questo committente
    const known = (id) => areas.value.some((a) => a.id === id);
    if (vista.areaId && ! known(vista.areaId)) vista.areaId = '';
    if (creating.areaId && ! known(creating.areaId)) creating.areaId = '';
}

/**
 * Un elenco intero, non solo la prima pagina.
 *
 * I filtri della mappa chiedevano 200 voci e il server ne restituisce al
 * massimo 100: con più di cento aree, le altre sparivano dalle tendine senza
 * che niente lo dicesse.
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

/**
 * Tutto ciò che circonda la mappa: aree, catalogo, località e committenti.
 * Sta in una funzione sola perché il pulsante "Riprova" possa rimetterlo a
 * posto senza costringere a ricaricare la pagina.
 */
async function caricaContorno() {
    const [aree, catalogRes, localita, committenti] = await Promise.all([
        tutteLePagine('/api/v1/areas'),
        axios.get('/api/v1/catalog'),
        tutteLePagine('/api/v1/localities'),
        canViewClients.value ? tutteLePagine('/api/v1/clients') : Promise.resolve([]),
    ]);
    localities.value = localita;
    clients.value = committenti;
    areas.value = aree;
    pointTypes.value = catalogRes.data.data.flatMap((m) =>
        m.sub_types.flatMap((s) =>
            s.object_types
                .filter((t) => t.allowed_geometry === 'P')
                .map((t) => ({ id: t.id, label: `${t.code} — ${t.name}` }))
        )
    );
    refreshAreasSource();
}

/** Cambio di committente/area/abbattuti: nuove tessere, nuove aree, nuova inquadratura. */
async function applyVista(refit = true) {
    vista.busy = true;
    try {
        await carica(loadAreas);
        refreshAreasSource();
        refreshTiles();
        if (refit) fitToAreas(vista.areaId ? 19 : 17);
    } finally {
        vista.busy = false;
    }
}

// Il browser dà la posizione solo in contesto sicuro: senza https il rifiuto
// arriva subito e va spiegato, altrimenti sembra un difetto dell'applicazione
function messaggioPosizione(error) {
    if (! window.isSecureContext) {
        return 'La posizione non è disponibile perché il sito è in http: il browser la fornisce solo sui siti sicuri (https). '
            + 'Nel frattempo la mappa si apre sulle aree censite.';
    }
    if (error?.code === 1) {
        return 'Posizione negata: consentila al sito nelle impostazioni del browser, poi premi di nuovo "La mia posizione".';
    }

    return 'Posizione non disponibile in questo momento.';
}

function vaiAllaPosizione() {
    posizione.error = '';
    if (! navigator.geolocation) {
        posizione.error = 'Questo browser non fornisce la posizione.';

        return;
    }
    geolocate?.trigger();
    // Alcuni browser, quando la posizione è bloccata, non rispondono affatto:
    // senza questa attesa la mappa resterebbe ferma senza spiegare perché
    posizione.cercando = true;
    clearTimeout(posizioneTimer);
    posizioneTimer = setTimeout(() => {
        posizione.cercando = false;
        if (! posizione.located) {
            posizione.error = messaggioPosizione(null);
            fitToAreas();
        }
    }, 12000);
}

async function onMapClick(e) {
    if (drawing.active) {
        drawing.vertices.push([e.lngLat.lng, e.lngLat.lat]);
        updateDrawPreview();
        return;
    }
    if (!creating.active || !creating.typeId || !creating.areaId) return;

    creating.saving = true;
    creating.message = '';
    try {
        await axios.post('/api/v1/assets', {
            area_id: creating.areaId,
            object_type_id: creating.typeId,
            survey_method: 'manual_map',
            geometry: { type: 'Point', coordinates: [e.lngLat.lng, e.lngLat.lat] },
        });
        creating.ok = true;
        creating.message = 'Elemento salvato';
        refreshTiles();
    } catch (err) {
        creating.ok = false;
        creating.message = err.response?.data?.message ?? 'Errore nel salvataggio';
    } finally {
        creating.saving = false;
    }
}

function onFeatureClick(e) {
    if (creating.active || drawing.active) return;
    const f = e.features?.[0];
    if (!f) return;
    selected.value = { ...f.properties };
}

onMounted(async () => {
    map = new maplibregl.Map({
        container: mapEl.value,
        center: [9.191, 45.465],
        zoom: 15,
        attributionControl: { compact: true },
        style: {
            version: 8,
            sources: {
                osm: {
                    type: 'raster',
                    tiles: ['https://tile.openstreetmap.org/{z}/{x}/{y}.png'],
                    tileSize: 256,
                    // Oltre questo livello lo sfondo non ha immagini: senza
                    // dichiararlo la mappa si svuota ingrandendo
                    maxzoom: 19,
                    attribution: '© OpenStreetMap contributors',
                },
                assets: { type: 'vector', tiles: [tilesUrl()], minzoom: 5, maxzoom: 22 },
            },
            layers: [
                { id: 'sfondo', type: 'background', paint: { 'background-color': '#e8ede9' } },
                { id: 'osm', type: 'raster', source: 'osm' },
            ],
        },
    });
    // Aggancio per le verifiche automatiche nel browser (Playwright):
    // permette di spostare la mappa e interrogare i layer disegnati
    window.__mappa = map;
    map.addControl(new maplibregl.NavigationControl(), 'top-right');
    map.addControl(new maplibregl.ScaleControl({ unit: 'metric' }));

    // Posizione del dispositivo: il puntino blu con il cerchio di precisione
    geolocate = new maplibregl.GeolocateControl({
        positionOptions: { enableHighAccuracy: true, timeout: 10000 },
        showAccuracyCircle: true,
        showUserLocation: true,
        fitBoundsOptions: { maxZoom: 18 },
    });
    map.addControl(geolocate, 'top-right');
    geolocate.on('geolocate', () => {
        clearTimeout(posizioneTimer);
        posizione.located = true;
        posizione.cercando = false;
        posizione.error = '';
    });
    geolocate.on('error', (e) => {
        clearTimeout(posizioneTimer);
        posizione.cercando = false;
        posizione.error = messaggioPosizione(e);
        // Ripiego: se la posizione non arriva, almeno si vede il verde censito
        if (! posizione.located) fitToAreas();
    });

    map.on('load', () => {
        map.addSource('aree', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
        map.addLayer({
            id: 'aree-fill', type: 'fill', source: 'aree',
            paint: { 'fill-color': '#16a34a', 'fill-opacity': 0.05 },
        });
        map.addLayer({
            id: 'aree-line', type: 'line', source: 'aree',
            paint: { 'line-color': '#15803d', 'line-width': 1.5, 'line-dasharray': [3, 2] },
        });
        map.addSource('draw', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
        map.addLayer({
            id: 'draw-fill', type: 'fill', source: 'draw',
            filter: ['==', ['geometry-type'], 'Polygon'],
            paint: { 'fill-color': '#2563eb', 'fill-opacity': 0.15 },
        });
        map.addLayer({
            id: 'draw-line', type: 'line', source: 'draw',
            filter: ['==', ['geometry-type'], 'LineString'],
            paint: { 'line-color': '#2563eb', 'line-width': 2, 'line-dasharray': [2, 1] },
        });
        map.addLayer({
            id: 'draw-pts', type: 'circle', source: 'draw',
            filter: ['==', ['geometry-type'], 'Point'],
            paint: { 'circle-color': '#2563eb', 'circle-radius': 4, 'circle-stroke-width': 1.5, 'circle-stroke-color': '#fff' },
        });
        map.addLayer({
            id: 'assets-fill', type: 'fill', source: 'assets', 'source-layer': 'assets',
            filter: ['==', ['geometry-type'], 'Polygon'],
            paint: { 'fill-color': colorExpr, 'fill-opacity': 0.35 },
        });
        map.addLayer({
            id: 'assets-line', type: 'line', source: 'assets', 'source-layer': 'assets',
            filter: ['==', ['geometry-type'], 'LineString'],
            paint: { 'line-color': colorExpr, 'line-width': 2.5 },
        });
        /*
         * Chioma a dimensione reale: un cerchio con il raggio vero in metri,
         * calcolato dal diametro censito. Da vicino la mappa diventa una
         * carta della copertura arborea.
         *
         * La conversione metri -> pixel dipende dallo zoom e dalla latitudine:
         * a MapLibre un metro vale 2^zoom / (78271,517 x cos(lat)) pixel. Con
         * due sole tappe e interpolazione esponenziale in base 2 la formula
         * e' esatta a ogni zoom. Il coseno segue la latitudine inquadrata
         * (ricalcolato a fine spostamento oltre una soglia): fissarlo su un
         * punto qualsiasi disegnerebbe chiome ~12% piu' grandi a Palermo.
         */
        const raggioChiome = () => {
            const cosLat = Math.cos((map.getCenter().lat * Math.PI) / 180);
            const pixelPerMetro = (zoom) => (2 ** zoom) / (78271.517 * cosLat);

            return ['interpolate', ['exponential', 2], ['zoom'],
                15, ['*', ['/', ['to-number', ['get', 'chioma_m']], 2], pixelPerMetro(15)],
                22, ['*', ['/', ['to-number', ['get', 'chioma_m']], 2], pixelPerMetro(22)],
            ];
        };
        let latChiome = map.getCenter().lat;
        map.addLayer({
            id: 'chiome', type: 'circle', source: 'assets', 'source-layer': 'assets',
            minzoom: 15.5,
            filter: ['all',
                ['==', ['geometry-type'], 'Point'],
                ['>', ['coalesce', ['to-number', ['get', 'chioma_m']], 0], 0],
            ],
            paint: {
                'circle-color': '#16a34a',
                'circle-opacity': 0.16,
                'circle-stroke-color': '#15803d',
                'circle-stroke-opacity': 0.4,
                'circle-stroke-width': 1,
                'circle-radius': raggioChiome(),
            },
        });
        map.on('moveend', () => {
            // 0,1 grado sono ~11 km: sotto, l'errore del coseno non si vede
            if (Math.abs(map.getCenter().lat - latChiome) < 0.1) return;
            latChiome = map.getCenter().lat;
            map.setPaintProperty('chiome', 'circle-radius', raggioChiome());
        });
        map.addLayer({
            id: 'assets-point', type: 'circle', source: 'assets', 'source-layer': 'assets',
            filter: ['==', ['geometry-type'], 'Point'],
            paint: {
                'circle-color': colorExpr,
                'circle-radius': ['interpolate', ['linear'], ['zoom'], 12, 3, 16, 7],
                'circle-stroke-width': 1.5,
                'circle-stroke-color': '#ffffff',
            },
        });

        for (const layer of assetLayers) {
            map.on('click', layer, onFeatureClick);
            map.on('mouseenter', layer, () => { map.getCanvas().style.cursor = 'pointer'; });
            map.on('mouseleave', layer, () => { map.getCanvas().style.cursor = ''; });
        }
        map.on('click', onMapClick);
    });

    await carica(caricaContorno);

    const setAree = () => {
        refreshAreasSource();
        // La posizione del dispositivo ha la precedenza: si inquadrano le
        // aree solo finché non è arrivata
        if (! posizione.located) fitToAreas();
    };
    if (map.loaded()) setAree();
    else map.on('load', setAree);

    // Si parte da dove si è: se il browser non dà la posizione, la vista
    // resta sulle aree censite e il motivo si legge nel pannello.
    // Fuori dall'evento "load" della mappa: quello aspetta il primo disegno
    // completo, che su una connessione lenta può tardare parecchio
    vaiAllaPosizione();

});

onBeforeUnmount(() => {
    clearTimeout(posizioneTimer);
    map?.remove();
});
</script>

<template>
    <Head title="Mappa" />

    <AppLayout>
        <div class="relative h-full">
            <div v-if="avviso" class="absolute inset-x-3 top-3 z-20">
                <AvvisoErrore :messaggio="avviso" :in-corso="riprovaInCorso" @riprova="riprova" />
            </div>
            <div ref="mapEl" class="h-full w-full" />

            <!-- Pannello layer -->
            <div class="absolute left-4 top-4 max-h-[calc(100%-2rem)] w-60 overflow-y-auto rounded-xl bg-white/95 p-4 shadow-lg backdrop-blur">
                <h2 class="mb-2 text-sm font-semibold">Vista</h2>
                <button
                    class="w-full rounded-lg border border-green-700 px-2 py-1.5 text-xs font-medium text-green-700 hover:bg-green-50 disabled:opacity-50"
                    :disabled="posizione.cercando"
                    data-test="mia-posizione"
                    @click="vaiAllaPosizione"
                >{{ posizione.cercando ? 'Ricerca della posizione…' : 'La mia posizione' }}</button>
                <p v-if="posizione.error" class="mt-2 text-xs text-amber-700" data-test="errore-posizione">{{ posizione.error }}</p>

                <select
                    v-if="canViewClients"
                    v-model="vista.clientId"
                    data-test="mappa-committente"
                    class="mt-2 w-full rounded-lg border border-gray-300 px-2 py-1.5 text-xs"
                    @change="applyVista()"
                >
                    <option value="">Tutti i committenti</option>
                    <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <select
                    v-model="vista.areaId"
                    data-test="mappa-area"
                    class="mt-2 w-full rounded-lg border border-gray-300 px-2 py-1.5 text-xs"
                    @change="applyVista()"
                >
                    <option value="">Tutte le aree</option>
                    <option v-for="a in areas" :key="a.id" :value="a.id">{{ a.name }}</option>
                </select>
                <label class="mt-2 flex cursor-pointer items-center gap-2 text-xs text-gray-600">
                    <input
                        v-model="vista.showRemoved"
                        type="checkbox"
                        data-test="mappa-abbattuti"
                        class="rounded border-gray-300"
                        @change="applyVista(false)"
                    >
                    Mostra anche gli abbattuti
                </label>
                <label class="flex items-center gap-2 text-sm text-gray-600">
                    <input v-model="chiomeVisibili" type="checkbox" data-test="mostra-chiome" class="rounded border-gray-300" @change="applicaChiome">
                    Chiome a dimensione reale
                </label>

                <hr class="my-3 border-gray-200">
                <h2 class="mb-2 text-sm font-semibold">Layer</h2>
                <label
                    v-for="tp in TP"
                    :key="tp.code"
                    class="flex cursor-pointer items-center gap-2 py-1 text-sm"
                >
                    <input v-model="tpVisible[tp.code]" type="checkbox" class="rounded border-gray-300" @change="applyVisibility">
                    <span class="inline-block h-3 w-3 rounded-full" :style="{ background: tp.color }" />
                    {{ tp.label }}
                </label>

                <template v-if="canCreateAreas">
                    <hr class="my-3 border-gray-200">
                    <label class="flex cursor-pointer items-center gap-2 text-sm font-medium">
                        <input v-model="drawing.active" type="checkbox" class="rounded border-gray-300" @change="resetDrawing">
                        Disegna area
                    </label>
                    <div v-if="drawing.active" class="mt-2 space-y-2">
                        <p class="text-xs text-gray-500">
                            Clicca sulla mappa per aggiungere i vertici ({{ drawing.vertices.length }}).
                        </p>
                        <select v-model="drawing.localityId" class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-xs">
                            <option value="" disabled>Località…</option>
                            <option v-for="l in localities" :key="l.id" :value="l.id">{{ l.name }}</option>
                        </select>
                        <input v-model="drawing.name" placeholder="Nome area *" class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-xs">
                        <input v-model="drawing.code" placeholder="Codice (opzionale)" class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-xs">
                        <div class="flex gap-2">
                            <button
                                class="flex-1 rounded-lg bg-green-700 px-2 py-1.5 text-xs font-medium text-white hover:bg-green-800 disabled:opacity-50"
                                :disabled="drawing.saving || drawing.vertices.length < 3"
                                @click="saveDrawnArea"
                            >{{ drawing.saving ? 'Salvataggio…' : 'Salva area' }}</button>
                            <button class="rounded-lg border border-gray-300 px-2 py-1.5 text-xs" @click="resetDrawing">Ricomincia</button>
                        </div>
                        <p v-if="drawing.message" class="text-xs font-medium" :class="drawing.ok ? 'text-green-700' : 'text-red-600'">
                            {{ drawing.message }}
                        </p>
                    </div>
                </template>

                <template v-if="canCreate">
                    <hr class="my-3 border-gray-200">
                    <label class="flex cursor-pointer items-center gap-2 text-sm font-medium">
                        <input v-model="creating.active" type="checkbox" class="rounded border-gray-300">
                        Nuovo elemento
                    </label>
                    <div v-if="creating.active" class="mt-2 space-y-2">
                        <select v-model="creating.areaId" class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-xs">
                            <option value="" disabled>Area…</option>
                            <option v-for="a in areas" :key="a.id" :value="a.id">{{ a.name }}</option>
                        </select>
                        <select v-model="creating.typeId" class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-xs">
                            <option value="" disabled>Tipo oggetto (puntuale)…</option>
                            <option v-for="t in pointTypes" :key="t.id" :value="t.id">{{ t.label }}</option>
                        </select>
                        <p class="text-xs text-gray-500">
                            {{ creating.saving ? 'Salvataggio…' : 'Clicca sulla mappa per posizionare l\'elemento.' }}
                        </p>
                        <p v-if="creating.message" class="text-xs font-medium" :class="creating.ok ? 'text-green-700' : 'text-red-600'">
                            {{ creating.message }}
                        </p>
                    </div>
                </template>
            </div>

            <!-- Scheda elemento selezionato -->
            <div v-if="selected" class="absolute bottom-4 left-4 w-72 rounded-xl bg-white p-4 shadow-lg">
                <div class="flex items-start justify-between">
                    <div>
                        <div class="text-xs uppercase tracking-wide text-gray-400">{{ selected.type_code }}</div>
                        <div class="font-semibold">{{ selected.type_name }}</div>
                    </div>
                    <button class="text-gray-400 hover:text-gray-600" @click="selected = null">✕</button>
                </div>
                <dl class="mt-2 space-y-1 text-sm">
                    <div class="flex justify-between"><dt class="text-gray-500">Codice</dt><dd>{{ selected.census_code || '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Stato</dt><dd>{{ statusLabel(selected.status) }}</dd></div>
                </dl>
                <a
                    :href="`/censimento/${selected.id}`"
                    class="mt-3 block rounded-lg bg-green-700 px-3 py-2 text-center text-sm font-medium text-white hover:bg-green-800"
                >
                    Apri scheda
                </a>
            </div>
        </div>
    </AppLayout>
</template>
