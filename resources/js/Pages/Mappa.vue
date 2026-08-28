<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import * as maplibregl from 'maplibre-gl';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import AvvisoErrore from '@/Components/AvvisoErrore.vue';
import ScegliCommittente from '@/Components/ScegliCommittente.vue';
import ScegliVoce from '@/Components/ScegliVoce.vue';
import { usaCaricamento } from '@/caricamento';
import { avvisoCaricamento } from '@/avvisi';
import { statusLabel } from '@/assetStatus';
import { contornoSiIncrocia } from '@/geometria';

const page = usePage();
const permissions = computed(() => page.props.auth?.user?.permissions ?? []);

// Se aree, catalogo o committenti non arrivano, i filtri e i tipi da disegnare
// restano vuoti: senza avviso sembrerebbe che non ci sia nulla da scegliere
const { avviso, riprovaInCorso, carica, riprova } = usaCaricamento();
const canCreate = computed(() => permissions.value.includes('assets.create'));
const canUpdate = computed(() => permissions.value.includes('assets.update'));
const canViewClients = computed(() => permissions.value.includes('clients.view'));
const canManageWorks = computed(() => permissions.value.includes('works.manage'));

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
// Tutti i tipi del catalogo, con la geometria che ciascuno richiede:
// P si posiziona con un clic, L e S si disegnano a punti
const tipiCensibili = ref([]);
// Le voci della tendina: la geometria da disegnare si legge nel nome
const tipiVoci = computed(() => tipiCensibili.value.map((t) => ({
    id: t.id,
    label: t.label,
    nome: t.geo === 'P' ? t.label
        : `${t.label} · ${t.geo === 'L' ? 'linea da disegnare' : 'superficie da disegnare'}`,
})));

// Vista: di chi guardo il verde e dove. Gli abbattuti restano in archivio
// ma non sulla mappa di tutti i giorni
const vista = reactive({ clientId: '', areaId: '', showRemoved: false, busy: false });

// Chiome a dimensione reale: il cerchio cresce con il diametro censito
const chiomeVisibili = ref(true);

// Sfondo della mappa: strade (OpenStreetMap) o ortofoto satellitare (Esri).
// La scelta resta per la prossima visita: chi censisce il verde lavora
// quasi sempre sul satellite
const sfondoMappa = ref(localStorage.getItem('webgis:sfondo-mappa') === 'satellite' ? 'satellite' : 'strade');

function applicaSfondo() {
    map?.setLayoutProperty('osm', 'visibility', sfondoMappa.value === 'strade' ? 'visible' : 'none');
    map?.setLayoutProperty('satellite', 'visibility', sfondoMappa.value === 'satellite' ? 'visible' : 'none');
    localStorage.setItem('webgis:sfondo-mappa', sfondoMappa.value);
}

function applicaChiome() {
    map?.setLayoutProperty('chiome', 'visibility', chiomeVisibili.value ? 'visible' : 'none');
}
// Posizione del dispositivo: il browser la dà solo su siti sicuri (https),
// quindi il motivo del rifiuto va spiegato, non nascosto
const posizione = reactive({ error: '', located: false, cercando: false });
let posizioneTimer = null;
const creating = reactive({ active: false, typeId: '', areaId: '', vertices: [], saving: false, message: '', ok: false });

// Ridisegno della geometria di un elemento gia' censito (sposta un punto,
// corregge il contorno di una siepe o di un prato)
const redraw = reactive({
    active: false, id: null, codice: '', geo: 'P', version: null,
    vertices: [], saving: false, message: '', ok: false,
});
const drawing = reactive({
    active: false, vertices: [], name: '', code: '', localityId: '', saving: false, message: '', ok: false,
});

// Selezione di elementi sulla mappa per farne un ordine di lavoro: la
// quantità di ciascuno la propone il server dalla geometria
const selezione = reactive({
    active: false, ids: [], codici: {}, titolo: '', workTypeId: '', clientId: '',
    saving: false, message: '', ok: false, creato: null,
});
const workTypes = ref([]);
// La geometria non si vede nel nome: l'unità di misura sì, e fa capire
// quale quantità verrà proposta (mq, m, cad)
const lavorazioniVoci = computed(() => workTypes.value.map((w) => ({
    ...w,
    nome: w.unit ? `${w.name} (${w.unit})` : w.name,
})));
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

/** Anteprima dei vertici sul livello di disegno: chiusa per le superfici, aperta per le linee. */
function disegnaAnteprima(pts, chiudi) {
    const features = [];
    if (pts.length >= 1) {
        features.push({ type: 'Feature', geometry: { type: 'MultiPoint', coordinates: pts }, properties: {} });
    }
    if (pts.length >= 2) {
        features.push({ type: 'Feature', geometry: { type: 'LineString', coordinates: chiudi ? [...pts, pts[0]] : pts }, properties: {} });
    }
    if (chiudi && pts.length >= 3) {
        features.push({ type: 'Feature', geometry: { type: 'Polygon', coordinates: [[...pts, pts[0]]] }, properties: {} });
    }
    map.getSource('draw')?.setData({ type: 'FeatureCollection', features });
}

function updateDrawPreview() {
    disegnaAnteprima(drawing.vertices, true);
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
    if (contornoSiIncrocia(drawing.vertices)) {
        drawing.ok = false;
        drawing.message = 'Il contorno si incrocia su se stesso: sposta o togli i punti che si accavallano.';
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
    const [aree, catalogRes, localita, committenti, lavorazioni] = await Promise.all([
        tutteLePagine('/api/v1/areas'),
        axios.get('/api/v1/catalog'),
        tutteLePagine('/api/v1/localities'),
        canViewClients.value ? tutteLePagine('/api/v1/clients') : Promise.resolve([]),
        canManageWorks.value ? axios.get('/api/v1/work-types') : Promise.resolve(null),
    ]);
    localities.value = localita;
    clients.value = committenti;
    areas.value = aree;
    workTypes.value = lavorazioni?.data.data ?? [];
    tipiCensibili.value = catalogRes.data.data.flatMap((m) =>
        m.sub_types.flatMap((s) =>
            s.object_types.map((t) => ({
                id: t.id, geo: t.allowed_geometry, label: `${t.code} — ${t.name}`,
            }))
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

// La geometria che il tipo scelto richiede: P un clic, L e S un disegno
const geoScelta = computed(() => tipiCensibili.value.find((t) => t.id === creating.typeId)?.geo ?? 'P');

function anteprimaCreazione() {
    disegnaAnteprima(creating.vertices, geoScelta.value === 'S');
}

// Un solo strumento per volta sulla mappa: disegno area, nuovo elemento,
// ridisegno o selezione. Attivarne uno spegne gli altri, o i clic
// andrebbero a due mani
watch(() => creating.active, (attivo) => {
    if (attivo) {
        drawing.active = false;
        redraw.active = false;
        selezione.active = false;
    }
    creating.vertices = [];
    creating.message = '';
    anteprimaCreazione();
});
watch(() => drawing.active, (attivo) => {
    if (attivo) {
        creating.active = false;
        redraw.active = false;
        selezione.active = false;
    } else {
        // Spento da un altro strumento: i vertici abbandonati non devono
        // restare disegnati come un poligono fantasma
        drawing.vertices = [];
    }
    updateDrawPreview();
});
watch(() => selezione.active, (attivo) => {
    if (attivo) {
        creating.active = false;
        drawing.active = false;
        redraw.active = false;
        // Il committente del filtro della vista è quasi sempre quello giusto
        if (! selezione.clientId) selezione.clientId = vista.clientId || '';
    } else {
        svuotaSelezione();
        selezione.message = '';
        selezione.creato = null;
        // Il precompilato non deve sopravvivere a un cambio di filtro: alla
        // prossima attivazione si riparte dal committente della vista
        selezione.clientId = '';
    }
});

// Il ridisegno può essere spento da un altro strumento: i suoi punti non
// devono restare disegnati come un'anteprima fantasma
watch(() => redraw.active, (attivo) => {
    if (! attivo) {
        redraw.vertices = [];
        disegnaAnteprima([], false);
    }
});

// Durante un disegno o una selezione il doppio clic non deve far saltare
// l'inquadratura
watch(() => drawing.active || creating.active || redraw.active || selezione.active, (attivo) => {
    if (! map) return;
    if (attivo) map.doubleClickZoom.disable();
    else map.doubleClickZoom.enable();
});
// Cambiando tipo cambia la geometria: i punti gia' cliccati non valgono piu'
watch(() => creating.typeId, () => {
    creating.vertices = [];
    anteprimaCreazione();
});

async function onMapClick(e) {
    if (drawing.active) {
        drawing.vertices.push([e.lngLat.lng, e.lngLat.lat]);
        updateDrawPreview();
        return;
    }
    if (redraw.active) {
        if (redraw.saving) return;
        // Per un punto l'ultimo clic sostituisce il precedente
        if (redraw.geo === 'P') redraw.vertices = [[e.lngLat.lng, e.lngLat.lat]];
        else redraw.vertices.push([e.lngLat.lng, e.lngLat.lat]);
        redraw.message = '';
        disegnaAnteprima(redraw.vertices, redraw.geo === 'S');
        return;
    }
    if (!creating.active || !creating.typeId || !creating.areaId) return;

    // Linee e superfici si disegnano a punti e si salvano con il pulsante
    if (geoScelta.value !== 'P') {
        if (creating.saving) return;
        creating.vertices.push([e.lngLat.lng, e.lngLat.lat]);
        creating.message = '';
        anteprimaCreazione();
        return;
    }

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

/** La misura vera calcolata dal database, per dirla subito a chi ha disegnato. */
function misuraDi(elemento, geo) {
    const valore = Number(geo === 'L' ? elemento?.computed_length_m : elemento?.computed_area_sqm);
    if (! Number.isFinite(valore) || valore <= 0) return null;

    return geo === 'L'
        ? `${valore.toLocaleString('it-IT', { maximumFractionDigits: 1 })} m`
        : `${valore.toLocaleString('it-IT', { maximumFractionDigits: 0 })} m²`;
}

async function salvaElementoDisegnato() {
    const pts = creating.vertices;
    if (geoScelta.value === 'S' && contornoSiIncrocia(pts)) {
        creating.ok = false;
        creating.message = 'Il contorno si incrocia su se stesso: sposta o togli i punti che si accavallano.';
        return;
    }
    const geometry = geoScelta.value === 'L'
        ? { type: 'LineString', coordinates: pts }
        : { type: 'Polygon', coordinates: [[...pts, pts[0]]] };

    creating.saving = true;
    creating.message = '';
    try {
        const { data } = await axios.post('/api/v1/assets', {
            area_id: creating.areaId,
            object_type_id: creating.typeId,
            survey_method: 'manual_map',
            geometry,
        });
        creating.ok = true;
        const misura = misuraDi(data.data, geoScelta.value);
        creating.message = misura ? `Elemento salvato (${misura})` : 'Elemento salvato';
        creating.vertices = [];
        anteprimaCreazione();
        refreshTiles();
    } catch (err) {
        creating.ok = false;
        creating.message = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
            ?? err.response?.data?.message ?? 'Errore nel salvataggio';
    } finally {
        creating.saving = false;
    }
}

function annullaUltimoPunto() {
    creating.vertices.pop();
    anteprimaCreazione();
}

/** Dal pannello dell'elemento scelto: si ridisegna da capo la sua geometria. */
async function avviaRidisegno() {
    if (! selected.value) return;
    const richiesto = selected.value.id;
    redraw.message = '';
    try {
        const { data } = await axios.get(`/api/v1/assets/${richiesto}`);
        redraw.id = data.data.id;
        redraw.codice = data.data.census_code || '';
        redraw.geo = data.data.object_type?.allowed_geometry ?? 'P';
        redraw.version = data.data.version;
        redraw.vertices = [];
        redraw.ok = false;
        creating.active = false;
        drawing.active = false;
        selezione.active = false;
        redraw.active = true;
        disegnaAnteprima([], false);
        selected.value = null;
    } catch (err) {
        // Nel frattempo la scheda puo' essere stata chiusa o cambiata:
        // l'errore non deve resuscitarla vuota ne' finire su un altro elemento
        if (selected.value?.id === richiesto) {
            selected.value = { ...selected.value, erroreRidisegno: avvisoCaricamento(err) };
        }
    }
}

const minimoPunti = computed(() => ({ P: 1, L: 2, S: 3 }[redraw.geo] ?? 1));

async function salvaRidisegno() {
    const pts = redraw.vertices;
    if (redraw.geo === 'S' && contornoSiIncrocia(pts)) {
        redraw.ok = false;
        redraw.message = 'Il contorno si incrocia su se stesso: sposta o togli i punti che si accavallano.';
        return;
    }
    const geometry = redraw.geo === 'P'
        ? { type: 'Point', coordinates: pts[0] }
        : redraw.geo === 'L'
            ? { type: 'LineString', coordinates: pts }
            : { type: 'Polygon', coordinates: [[...pts, pts[0]]] };

    redraw.saving = true;
    redraw.message = '';
    try {
        const { data } = await axios.patch(`/api/v1/assets/${redraw.id}`, {
            geometry,
            // La geometria ora e' disegnata a video: il metodo di rilievo
            // e la precisione GPS di prima non la descrivono piu'
            survey_method: 'manual_map',
            gps_accuracy_m: null,
            version: redraw.version,
        });
        redraw.ok = true;
        const misura = misuraDi(data.data, redraw.geo);
        redraw.message = misura ? `Geometria aggiornata (${misura})` : 'Geometria aggiornata';
        redraw.version = data.data.version;
        redraw.vertices = [];
        disegnaAnteprima([], false);
        refreshTiles();
    } catch (err) {
        redraw.ok = false;
        redraw.message = err.response?.status === 409
            ? 'Un altro utente ha modificato l\'elemento nel frattempo: chiudi questo riquadro con \u2715, riclicca l\'elemento sulla mappa e premi di nuovo "Ridisegna sulla mappa".'
            : (Object.values(err.response?.data?.errors ?? {})[0]?.[0]
                ?? err.response?.data?.message ?? 'Errore nel salvataggio');
    } finally {
        redraw.saving = false;
    }
}

function chiudiRidisegno() {
    redraw.active = false;
    redraw.vertices = [];
    redraw.message = '';
    disegnaAnteprima([], false);
}

// Il gestore del clic e' registrato su ogni livello (superfici, linee,
// punti): dove una linea passa sopra un prato scatterebbe due volte e la
// selezione commuterebbe due elementi con un clic solo. Si tiene l'evento
// gia' servito e si prende il solo elemento piu' in vista
let clicServito = null;

function onFeatureClick(e) {
    if (creating.active || drawing.active || redraw.active) return;
    if (e.originalEvent === clicServito) return;
    clicServito = e.originalEvent;
    const f = map.queryRenderedFeatures(e.point, { layers: assetLayers })[0] ?? e.features?.[0];
    if (!f) return;
    if (selezione.active) {
        commutaElemento(f.properties);
        return;
    }
    // Il punto cliccato serve per aprire Street View proprio lì: per un
    // prato o un filare è più utile del centro della geometria
    selected.value = {
        ...f.properties,
        streetView: `https://www.google.com/maps/@?api=1&map_action=pano&viewpoint=${e.lngLat.lat},${e.lngLat.lng}`,
    };
}

// ---- Selezione di elementi per un ordine di lavoro ----

function commutaElemento(props) {
    const id = props.id;
    if (! id) return;
    const dove = selezione.ids.indexOf(id);
    if (dove >= 0) {
        selezione.ids.splice(dove, 1);
        delete selezione.codici[id];
    } else {
        selezione.ids.push(id);
        selezione.codici[id] = props.census_code || String(id).slice(0, 8);
    }
    selezione.message = '';
    aggiornaEvidenzaSelezione();
}

/** L'evidenza ambra sugli elementi scelti: filtri sui livelli dedicati. */
function aggiornaEvidenzaSelezione() {
    if (! map?.getLayer('selezione-linea')) return;
    // Con l'elenco vuoto un id impossibile spegne l'evidenza
    const idFiltro = ['in', ['get', 'id'], ['literal', selezione.ids.length ? [...selezione.ids] : ['-']]];
    map.setFilter('selezione-fill', ['all', ['==', ['geometry-type'], 'Polygon'], idFiltro]);
    map.setFilter('selezione-linea', idFiltro);
    map.setFilter('selezione-punti', ['all', ['==', ['geometry-type'], 'Point'], idFiltro]);
}

function svuotaSelezione() {
    selezione.ids = [];
    selezione.codici = {};
    aggiornaEvidenzaSelezione();
}

async function creaOrdineDaSelezione() {
    selezione.saving = true;
    selezione.message = '';
    selezione.creato = null;
    try {
        const { data } = await axios.post('/api/v1/work-orders', {
            title: selezione.titolo.trim(),
            work_type_id: selezione.workTypeId || undefined,
            client_id: selezione.clientId || undefined,
            asset_ids: [...selezione.ids],
        });
        const n = data.data.assets?.length ?? selezione.ids.length;
        const conQuantita = (data.data.assets ?? []).filter((r) => r.planned_quantity != null).length;
        selezione.ok = true;
        selezione.creato = { id: data.data.id, code: data.data.code };
        selezione.message = `Ordine ${data.data.code} creato in bozza con ${n} element${n === 1 ? 'o' : 'i'}`
            + (conQuantita ? `, quantità dalla mappa per ${conQuantita}.` : '.');
        selezione.titolo = '';
        svuotaSelezione();
    } catch (err) {
        selezione.ok = false;
        selezione.message = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
            ?? err.response?.data?.message ?? 'Errore nella creazione dell\'ordine';
    } finally {
        selezione.saving = false;
    }
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
                // Ortofoto Esri World Imagery: per il censimento del verde le
                // chiome si vedono solo dal satellite, le strade non bastano
                satellite: {
                    type: 'raster',
                    tiles: ['https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}'],
                    tileSize: 256,
                    maxzoom: 19,
                    attribution: 'Esri, Maxar, Earthstar Geographics',
                },
                assets: { type: 'vector', tiles: [tilesUrl()], minzoom: 5, maxzoom: 22 },
            },
            layers: [
                { id: 'sfondo', type: 'background', paint: { 'background-color': '#e8ede9' } },
                { id: 'osm', type: 'raster', source: 'osm' },
                { id: 'satellite', type: 'raster', source: 'satellite', layout: { visibility: 'none' } },
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
        // Lo sfondo scelto l'ultima volta (strade o satellite) si riapplica
        applicaSfondo();

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

        // Evidenza ambra degli elementi scelti per un ordine di lavoro
        // (i filtri partono su un id impossibile: nessuna evidenza)
        map.addLayer({
            id: 'selezione-fill', type: 'fill', source: 'assets', 'source-layer': 'assets',
            filter: ['all', ['==', ['geometry-type'], 'Polygon'], ['in', ['get', 'id'], ['literal', ['-']]]],
            paint: { 'fill-color': '#b45309', 'fill-opacity': 0.25 },
        });
        map.addLayer({
            id: 'selezione-linea', type: 'line', source: 'assets', 'source-layer': 'assets',
            filter: ['in', ['get', 'id'], ['literal', ['-']]],
            paint: { 'line-color': '#b45309', 'line-width': 3 },
        });
        map.addLayer({
            id: 'selezione-punti', type: 'circle', source: 'assets', 'source-layer': 'assets',
            filter: ['all', ['==', ['geometry-type'], 'Point'], ['in', ['get', 'id'], ['literal', ['-']]]],
            paint: {
                'circle-color': '#b45309',
                'circle-opacity': 0,
                'circle-radius': ['interpolate', ['linear'], ['zoom'], 12, 6, 16, 11],
                'circle-stroke-width': 3,
                'circle-stroke-color': '#b45309',
            },
        });
        aggiornaEvidenzaSelezione();

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

                <ScegliCommittente
                    v-if="canViewClients"
                    v-model="vista.clientId"
                    data-test="mappa-committente"
                    class="mt-2 w-full"
                    campo-classe="px-2 py-1.5 text-xs"
                    :committenti="clients"
                    tutti="Tutti i committenti"
                    @cambia="applyVista()"
                />
                <ScegliVoce
                    v-model="vista.areaId"
                    data-test="mappa-area"
                    class="mt-2 w-full"
                    campo-classe="px-2 py-1.5 text-xs"
                    :voci="areas"
                    :campi-ricerca="['name', 'code']"
                    tutti="Tutte le aree"
                    vuoto="Nessuna area trovata."
                    @cambia="applyVista()"
                />
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

                <div class="mt-2 flex items-center gap-3 text-sm text-gray-600">
                    <span class="text-gray-500">Sfondo</span>
                    <label class="flex items-center gap-1.5">
                        <input v-model="sfondoMappa" type="radio" value="strade" data-test="sfondo-strade" class="border-gray-300" @change="applicaSfondo">
                        Strade
                    </label>
                    <label class="flex items-center gap-1.5">
                        <input v-model="sfondoMappa" type="radio" value="satellite" data-test="sfondo-satellite" class="border-gray-300" @change="applicaSfondo">
                        Satellite
                    </label>
                </div>

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
                        <ScegliVoce
                            v-model="drawing.localityId"
                            campo-classe="px-2 py-1.5 text-xs"
                            :voci="localities"
                            :campi-ricerca="['name', 'code']"
                            segnaposto="Località…"
                            vuoto="Nessuna località trovata."
                        />
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
                        <ScegliVoce
                            v-model="creating.areaId"
                            data-test="nuovo-elemento-area"
                            campo-classe="px-2 py-1.5 text-xs"
                            :voci="areas"
                            :campi-ricerca="['name', 'code']"
                            segnaposto="Area…"
                            vuoto="Nessuna area trovata."
                        />
                        <ScegliVoce
                            v-model="creating.typeId"
                            data-test="nuovo-elemento-tipo"
                            campo-classe="px-2 py-1.5 text-xs"
                            campo-nome="nome"
                            :voci="tipiVoci"
                            :campi-ricerca="['label']"
                            segnaposto="Tipo oggetto (codice o nome)…"
                            vuoto="Nessun tipo trovato."
                        />
                        <p class="text-xs text-gray-500">
                            <template v-if="creating.saving">Salvataggio…</template>
                            <template v-else-if="! creating.areaId">Scegli prima l'area: i clic sulla mappa non contano finché manca.</template>
                            <template v-else-if="geoScelta === 'P'">Clicca sulla mappa per posizionare l'elemento.</template>
                            <template v-else>
                                Clicca sulla mappa per aggiungere i punti
                                {{ geoScelta === 'L' ? 'della linea' : 'del contorno' }} ({{ creating.vertices.length }}).
                            </template>
                        </p>
                        <div v-if="geoScelta !== 'P'" class="flex gap-2">
                            <button
                                data-test="salva-elemento-disegnato"
                                class="flex-1 rounded-lg bg-green-700 px-2 py-1.5 text-xs font-medium text-white hover:bg-green-800 disabled:opacity-50"
                                :disabled="creating.saving || ! creating.areaId || creating.vertices.length < (geoScelta === 'L' ? 2 : 3)"
                                @click="salvaElementoDisegnato"
                            >{{ creating.saving ? 'Salvataggio…' : 'Salva elemento' }}</button>
                            <button
                                class="rounded-lg border border-gray-300 px-2 py-1.5 text-xs disabled:opacity-50"
                                :disabled="! creating.vertices.length"
                                @click="annullaUltimoPunto"
                            >← Ultimo punto</button>
                        </div>
                        <p v-if="creating.message" class="text-xs font-medium" :class="creating.ok ? 'text-green-700' : 'text-red-600'">
                            {{ creating.message }}
                        </p>
                    </div>
                </template>

                <template v-if="canManageWorks">
                    <hr class="my-3 border-gray-200">
                    <label class="flex cursor-pointer items-center gap-2 text-sm font-medium">
                        <input v-model="selezione.active" type="checkbox" data-test="selezione-lavoro" class="rounded border-gray-300">
                        Elementi per un lavoro
                    </label>
                    <div v-if="selezione.active" class="mt-2 space-y-2">
                        <p class="text-xs text-gray-500">
                            Clicca gli elementi sulla mappa: un clic li aggiunge, un altro li
                            toglie ({{ selezione.ids.length }}). Con la lavorazione scelta, la
                            quantità di ciascuno si propone dalla misura sulla mappa.
                        </p>
                        <ul
                            v-if="selezione.ids.length"
                            data-test="selezione-elenco"
                            class="max-h-28 space-y-0.5 overflow-y-auto rounded-lg border border-gray-200 px-2 py-1.5 text-xs"
                        >
                            <li v-for="id in selezione.ids" :key="id" class="flex items-center justify-between gap-2">
                                <span>{{ selezione.codici[id] }}</span>
                                <button class="text-gray-400 hover:text-gray-600" :aria-label="`Togli ${selezione.codici[id]}`" @click="commutaElemento({ id })">✕</button>
                            </li>
                        </ul>
                        <input
                            v-model="selezione.titolo"
                            data-test="selezione-titolo"
                            placeholder="Titolo dell'ordine *"
                            class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-xs"
                        >
                        <ScegliVoce
                            v-model="selezione.workTypeId"
                            data-test="selezione-lavorazione"
                            campo-classe="px-2 py-1.5 text-xs"
                            campo-nome="nome"
                            :voci="lavorazioniVoci"
                            :campi-ricerca="['name', 'code']"
                            segnaposto="Lavorazione…"
                            vuoto="Nessuna lavorazione trovata."
                        />
                        <ScegliCommittente
                            v-if="canViewClients"
                            v-model="selezione.clientId"
                            data-test="selezione-committente"
                            campo-classe="px-2 py-1.5 text-xs"
                            :committenti="clients"
                            tutti="Senza committente"
                        />
                        <button
                            data-test="selezione-crea"
                            class="w-full rounded-lg bg-green-700 px-2 py-1.5 text-xs font-medium text-white hover:bg-green-800 disabled:opacity-50"
                            :disabled="selezione.saving || ! selezione.ids.length || ! selezione.titolo.trim()"
                            @click="creaOrdineDaSelezione"
                        >{{ selezione.saving ? 'Creazione…' : 'Crea ordine di lavoro (in bozza)' }}</button>
                        <p v-if="selezione.message" class="text-xs font-medium" :class="selezione.ok ? 'text-green-700' : 'text-red-600'" data-test="selezione-esito">
                            {{ selezione.message }}
                            <a
                                v-if="selezione.creato"
                                :href="`/lavori?ordine=${selezione.creato.id}`"
                                class="underline"
                                data-test="selezione-apri-ordine"
                            >Apri l'ordine</a>
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
                <a
                    v-if="selected.streetView"
                    :href="selected.streetView"
                    target="_blank"
                    rel="noopener"
                    data-test="street-view-mappa"
                    title="Apre Google Street View sul punto cliccato, in un'altra scheda"
                    class="mt-2 block rounded-lg border border-gray-300 px-3 py-2 text-center text-sm font-medium text-gray-700 hover:bg-gray-50"
                >
                    Street View
                </a>
                <button
                    v-if="canUpdate"
                    data-test="ridisegna-elemento"
                    class="mt-2 block w-full rounded-lg border border-green-700 px-3 py-2 text-center text-sm font-medium text-green-700 hover:bg-green-50"
                    @click="avviaRidisegno"
                >
                    Ridisegna sulla mappa
                </button>
                <p v-if="selected.erroreRidisegno" class="mt-2 text-xs font-medium text-red-600">{{ selected.erroreRidisegno }}</p>
            </div>

            <!-- Ridisegno della geometria -->
            <div v-if="redraw.active" class="absolute bottom-4 left-4 w-72 rounded-xl bg-white p-4 shadow-lg" data-test="pannello-ridisegno">
                <div class="flex items-start justify-between">
                    <div>
                        <div class="text-xs uppercase tracking-wide text-gray-400">Ridisegno</div>
                        <div class="font-semibold">{{ redraw.codice || 'Elemento' }}</div>
                    </div>
                    <button class="text-gray-400 hover:text-gray-600" @click="chiudiRidisegno">✕</button>
                </div>
                <p class="mt-2 text-xs text-gray-500">
                    <template v-if="redraw.geo === 'P'">Clicca sulla mappa la nuova posizione (l'ultimo clic vale).</template>
                    <template v-else>
                        Clicca i punti della nuova {{ redraw.geo === 'L' ? 'linea' : 'superficie' }}
                        ({{ redraw.vertices.length }}): il disegno vecchio resta visibile finché non salvi.
                    </template>
                </p>
                <div class="mt-2 flex gap-2">
                    <button
                        data-test="salva-ridisegno"
                        class="flex-1 rounded-lg bg-green-700 px-2 py-1.5 text-xs font-medium text-white hover:bg-green-800 disabled:opacity-50"
                        :disabled="redraw.saving || redraw.vertices.length < minimoPunti"
                        @click="salvaRidisegno"
                    >{{ redraw.saving ? 'Salvataggio…' : 'Salva la nuova geometria' }}</button>
                    <button
                        v-if="redraw.geo !== 'P'"
                        class="rounded-lg border border-gray-300 px-2 py-1.5 text-xs disabled:opacity-50"
                        :disabled="! redraw.vertices.length"
                        @click="redraw.vertices.pop(); disegnaAnteprima(redraw.vertices, redraw.geo === 'S')"
                    >← Ultimo punto</button>
                </div>
                <p v-if="redraw.message" class="mt-2 text-xs font-medium" :class="redraw.ok ? 'text-green-700' : 'text-red-600'">
                    {{ redraw.message }}
                </p>
            </div>
        </div>
    </AppLayout>
</template>
