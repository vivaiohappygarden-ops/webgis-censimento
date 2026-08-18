<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import * as maplibregl from 'maplibre-gl';
import { openFieldDb } from '@/field/db';
import { compressImage } from '@/field/photo';
import { SyncManager } from '@/field/sync';

const page = usePage();
const user = page.props.auth.user;
const canAssociate = computed(() => (user.permissions ?? []).includes('assets.update'));
const canWorks = computed(() => (user.permissions ?? []).includes('works.view'));

const db = openFieldDb(user.tenant_id, user.id);
const sync = new SyncManager(db);

const tab = ref('rilievo');
const state = reactive({ pending: 0, attention: 0, photos: 0, photosFailed: 0, syncing: false, online: navigator.onLine });
const bootstrapped = ref(false);
const areas = ref([]);
const pointTypes = ref([]);
const localAssets = ref([]);
const queueRows = ref([]);
const logRows = ref([]);
const busy = ref(false);
const message = reactive({ text: '', ok: true });

const form = reactive({
    areaId: '', typeId: '', censusCode: '', notes: '',
    lat: '', lon: '', accuracy: null, locating: false,
});

// Scheda elemento aperta (da elenco o da scansione)
const selected = ref(null);

// Elemento a cui riferire la foto di un lavoro con piu' elementi
const workPhotoAssetId = ref('');

// I miei lavori: ordini assegnati all'operatore, replicati sul dispositivo
const localOrders = ref([]);
const selectedOrder = ref(null);
const consuntivo = reactive({ open: false, manHours: null, quantity: null, unit: '', notes: '' });

// Ispezioni su checklist compilate in campo (modelli dal working set)
const localTemplates = ref([]);
const inspectionRun = reactive({ open: false, template: null, assetId: null, assetLabel: '', areaId: '' });
const inspectionAnswers = ref([]);

const assetTemplates = computed(() => localTemplates.value.filter((t) => t.target === 'asset'));
const areaTemplates = computed(() => localTemplates.value.filter((t) => t.target === 'area'));
const inspectionPick = reactive({ assetTemplateId: '', areaTemplateId: '', areaId: '' });

function startInspection(template, { assetId = null, assetLabel = '', areaId = '' } = {}) {
    // Il modello può essere sparito dal device (disattivato in ufficio e
    // rimosso dal pull): mai aprire una checklist su un modello fantasma
    if (! template || ! (template.items ?? []).length) {
        inspectionPick.assetTemplateId = '';
        inspectionPick.areaTemplateId = '';
        setMessage('Modello non più disponibile sul dispositivo: sincronizza e riprova.', false);
        return;
    }
    Object.assign(inspectionRun, { open: true, template, assetId, assetLabel, areaId });
    inspectionAnswers.value = template.items.map((item) => ({
        item_id: item.id, question: item.question, answer_type: item.answer_type,
        value: '', note: '',
    }));
}

// Vero se la checklist aperta contiene lavoro non ancora registrato
function inspectionHasWork() {
    return inspectionRun.open
        && inspectionAnswers.value.some((a) => String(a.value) !== '' || a.note.trim() !== '');
}

function closeInspection() {
    if (inspectionHasWork()
        && ! window.confirm('Chiudere senza registrare l\'ispezione? Le risposte andranno perse.')) {
        return;
    }
    inspectionRun.open = false;
}

const inspectionComplete = computed(() => inspectionAnswers.value.length > 0
    && inspectionAnswers.value.every((a) => {
        if (['ok_ko', 'ok_ko_na'].includes(a.answer_type)) return a.value !== '';
        return String(a.value).trim() !== '';
    }));

async function submitInspection() {
    busy.value = true;
    try {
        const answers = Object.fromEntries(inspectionAnswers.value.map((a) => [
            a.item_id, { value: String(a.value), note: a.note.trim() || null },
        ]));
        await sync.enqueueInspectionComplete({
            templateId: inspectionRun.template.id,
            assetId: inspectionRun.assetId,
            areaId: inspectionRun.assetId ? null : inspectionRun.areaId,
            answers,
        });
        inspectionRun.open = false;
        setMessage(state.online
            ? 'Ispezione registrata: invio in corso.'
            : 'Ispezione registrata sul dispositivo: verrà inviata quando torna la rete.');
        if (state.online) await runSync();
        await refreshLocal();
    } catch {
        setMessage('Registrazione dell\'ispezione non riuscita: riprova.', false);
    } finally {
        busy.value = false;
    }
}

// Segnalazioni dal campo: due bozze SEPARATE (scheda elemento e scheda
// Rilievo), così il testo scritto in un contesto non parte mai nell'altro
const ISSUE_SEVERITY = { low: 'Bassa', medium: 'Media', high: 'Alta', critical: 'Critica' };
const assetIssue = reactive({ open: false, description: '', severity: 'medium' });
const areaIssue = reactive({ areaId: '', description: '', severity: 'medium' });

function resetAssetIssue() {
    Object.assign(assetIssue, { open: false, description: '', severity: 'medium' });
}

async function sendIssue({ description, severity, assetId = null, areaId = null }) {
    busy.value = true;
    try {
        await sync.enqueueIssueCreate({ description, severity, assetId, areaId });
        setMessage(state.online
            ? 'Segnalazione registrata: invio in corso.'
            : 'Segnalazione registrata sul dispositivo: verrà inviata quando torna la rete.');
        if (state.online) await runSync();
        await refreshLocal();
        return true;
    } catch {
        setMessage('Registrazione della segnalazione non riuscita: riprova.', false);
        return false;
    } finally {
        busy.value = false;
    }
}

async function submitAssetIssue() {
    const description = assetIssue.description.trim();
    if (! description) {
        setMessage('Descrivi il problema segnalato.', false);
        return;
    }
    if (await sendIssue({ description, severity: assetIssue.severity, assetId: selected.value.asset.id })) {
        resetAssetIssue();
    }
}

async function submitAreaIssue() {
    const description = areaIssue.description.trim();
    if (! description) {
        setMessage('Descrivi il problema segnalato.', false);
        return;
    }
    if (! areaIssue.areaId) {
        setMessage('Indica l\'area interessata dalla segnalazione.', false);
        return;
    }
    if (await sendIssue({ description, severity: areaIssue.severity, areaId: areaIssue.areaId })) {
        Object.assign(areaIssue, { description: '', severity: 'medium' });
    }
}
const measureForm = reactive({ species: '', height_m: null, dbh_cm: null, crown_diameter_m: null });
const tagForm = reactive({ uid: '', tagType: 'qr' });

// Scansione tag (fotocamera dove disponibile, inserimento manuale sempre)
const scan = reactive({
    uid: '', tagType: 'qr', notFound: false, targetId: '',
    cameraSupported: typeof window !== 'undefined' && 'BarcodeDetector' in window,
    cameraActive: false, cameraError: '',
});
let cameraStream = null;
const videoEl = ref(null);
const scanInput = ref(null);

/** Riporta il cursore nel campo del lettore, pronto per la lettura dopo. */
function focusScanInput() {
    nextTick(() => scanInput.value?.focus());
}

let photoUrls = [];
function revokePhotoUrls() {
    photoUrls.forEach((u) => URL.revokeObjectURL(u));
    photoUrls = [];
}

async function localPhotosFor(assetId) {
    revokePhotoUrls();
    const uploads = await db.photo_uploads.where('asset_id').equals(assetId).toArray();
    const out = [];
    for (const u of uploads) {
        const blobRow = await db.photo_blobs.get(u.photo_id);
        if (! blobRow) continue;
        const url = URL.createObjectURL(blobRow.blob);
        photoUrls.push(url);
        out.push({ photo_id: u.photo_id, url, status: u.status, error: u.last_error });
    }
    return out;
}

async function openAsset(asset) {
    const tree = await db.trees.get(asset.id);
    const tags = await db.asset_tags.where('asset_id').equals(asset.id).toArray();
    const photos = await localPhotosFor(asset.id);
    // La bozza di segnalazione appartiene all'elemento su cui è stata
    // scritta: aprendo un altro elemento si riparte da zero
    resetAssetIssue();
    selected.value = { asset, tree: tree ?? null, tags, photos };
    Object.assign(measureForm, {
        species: tree?.species ?? '',
        height_m: tree?.height_m != null ? Number(tree.height_m) : null,
        dbh_cm: tree?.dbh_cm != null ? Number(tree.dbh_cm) : null,
        crown_diameter_m: tree?.crown_diameter_m != null ? Number(tree.crown_diameter_m) : null,
    });
    Object.assign(tagForm, { uid: '', tagType: 'qr' });
}

// Campo numerico svuotato = valore assente (mai zero implicito)
const numOrNull = (v) => (v === '' || v == null || Number.isNaN(v) ? null : Number(v));

async function saveMeasures() {
    busy.value = true;
    try {
        await sync.enqueueMeasures({
            assetId: selected.value.asset.id,
            measures: {
                species: measureForm.species || null,
                height_m: numOrNull(measureForm.height_m),
                dbh_cm: numOrNull(measureForm.dbh_cm),
                crown_diameter_m: numOrNull(measureForm.crown_diameter_m),
            },
        });
        setMessage(state.online
            ? 'Misure registrate: sincronizzazione in corso.'
            : 'Misure registrate sul dispositivo: verranno inviate quando torna la rete.');
        if (state.online) await runSync();
        await refreshLocal();
        // La scheda resta com'è: ricaricare il modulo cancellerebbe ciò che
        // l'operatore sta già scrivendo per la correzione successiva
        const updated = await db.assets.get(selected.value.asset.id);
        if (updated) selected.value = { ...selected.value, asset: updated };
    } catch {
        setMessage('Salvataggio locale non riuscito: riprova.', false);
    } finally {
        busy.value = false;
    }
}

async function associateTag(assetId, uid, tagType) {
    const cleanUid = String(uid).trim();
    if (! cleanUid) {
        setMessage('Inserisci o scansiona il codice del tag.', false);
        return;
    }
    const already = await sync.findByTag(cleanUid, tagType);
    if (already && already.asset.id === assetId) {
        setMessage('Questo tag è già associato a questo elemento.', false);
        return;
    }
    if (already) {
        setMessage(`Tag già associato a ${already.asset.census_code || 'un altro elemento'}.`, false);
        return;
    }
    busy.value = true;
    try {
        await sync.enqueueTagAssociate({ assetId, uid: cleanUid, tagType });
        setMessage('Tag associato: verrà confermato alla sincronizzazione.');
        if (state.online) await runSync();
        await refreshLocal();
        if (selected.value?.asset.id === assetId) {
            const updated = await db.assets.get(assetId);
            if (updated) await openAsset(updated);
        }
    } catch {
        setMessage('Associazione non riuscita sul dispositivo: riprova.', false);
    } finally {
        busy.value = false;
    }
}

async function doScan() {
    scan.notFound = false;
    const uid = scan.uid.trim();
    if (! uid) return;
    const hit = await sync.findByTag(uid);
    if (hit) {
        stopCamera();
        tab.value = 'elementi';
        await openAsset(hit.asset);
        setMessage(`Tag riconosciuto: ${hit.asset.census_code || hit.asset.id.slice(0, 8)}.`);
    } else {
        scan.notFound = true;
        focusScanInput();
        nextTick(() => scanInput.value?.select());
    }
}

async function startCamera() {
    if (scan.cameraActive) return;
    scan.cameraError = '';
    let stream = null;
    try {
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
        cameraStream = stream;
        scan.cameraActive = true;
        await new Promise((r) => setTimeout(r, 50));
        videoEl.value.srcObject = stream;
        await videoEl.value.play();

        const detector = new window.BarcodeDetector({
            formats: ['qr_code', 'code_128', 'code_39', 'ean_13', 'data_matrix'],
        });
        const tick = async () => {
            if (! scan.cameraActive) return;
            try {
                const codes = await detector.detect(videoEl.value);
                if (codes.length) {
                    scan.uid = codes[0].rawValue;
                    scan.tagType = codes[0].format === 'qr_code' ? 'qr' : 'barcode';
                    stopCamera();
                    await doScan();
                    return;
                }
            } catch {
                // frame non leggibile: si riprova al prossimo giro
            }
            requestAnimationFrame(tick);
        };
        requestAnimationFrame(tick);
    } catch {
        // Lo stream eventualmente già acceso va spento anche in caso di errore
        stream?.getTracks().forEach((t) => t.stop());
        cameraStream = null;
        scan.cameraError = window.isSecureContext
            ? 'Fotocamera non disponibile: inserisci il codice manualmente o usa il lettore.'
            : 'La fotocamera funziona solo con il lucchetto (https). Intanto usa il lettore o digita il codice.';
        scan.cameraActive = false;
    }
}

// Fix GPS veloce e silenzioso: se non arriva in 3,5 secondi la foto parte senza.
// Il limite esterno copre anche il caso del permesso mai risposto, in cui
// getCurrentPosition non richiama nessuna delle due callback.
function quickPosition() {
    const attempt = new Promise((resolve) => {
        if (! navigator.geolocation) return resolve(null);
        try {
            navigator.geolocation.getCurrentPosition(
                (pos) => resolve({
                    lat: pos.coords.latitude,
                    lon: pos.coords.longitude,
                    accuracy: Math.round(pos.coords.accuracy * 10) / 10,
                }),
                () => resolve(null),
                { enableHighAccuracy: true, timeout: 3000, maximumAge: 60000 },
            );
        } catch {
            resolve(null);
        }
    });
    const fallback = new Promise((resolve) => setTimeout(() => resolve(null), 3500));
    return Promise.race([attempt, fallback]);
}

/**
 * Foto scattata durante un lavoro: resta agganciata all'ordine, cosi' puo'
 * comparire nella cronologia pubblica dell'elemento accanto all'intervento.
 * Va scelto l'elemento a cui riferirla quando l'ordine ne tocca piu' d'uno.
 */
async function takeWorkPhoto(event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (! file || ! selectedOrder.value) return;

    const elementi = selectedOrder.value.assets ?? [];
    const assetId = elementi.length === 1 ? elementi[0].asset_id : workPhotoAssetId.value;
    if (! assetId) {
        setMessage('Scegli prima a quale elemento si riferisce la foto.', false);

        return;
    }

    busy.value = true;
    try {
        const takenAt = new Date().toISOString();
        const [blob, position] = await Promise.all([compressImage(file), quickPosition()]);
        await sync.enqueuePhoto({
            assetId,
            blob,
            takenAt,
            position,
            category: 'after',
            workOrderId: selectedOrder.value.id,
        });
        setMessage(state.online
            ? 'Foto del lavoro registrata: invio in corso.'
            : "Foto del lavoro registrata: verrà inviata quando torna la rete.");
        if (state.online) await runSync();
    } catch (err) {
        setMessage(err?.message ?? 'Foto non registrata.', false);
    } finally {
        busy.value = false;
    }
}

async function takePhoto(event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (! file || ! selected.value) return;
    const assetId = selected.value.asset.id;
    busy.value = true;
    try {
        const takenAt = new Date().toISOString();
        const [blob, position] = await Promise.all([compressImage(file), quickPosition()]);
        await sync.enqueuePhoto({ assetId, blob, takenAt, position });
        selected.value = { ...selected.value, photos: await localPhotosFor(assetId) };
        setMessage(state.online
            ? 'Foto registrata: invio in corso.'
            : 'Foto registrata sul dispositivo: verrà inviata quando torna la rete.');
        if (state.online) {
            await runSync();
            if (selected.value?.asset.id === assetId) {
                selected.value = { ...selected.value, photos: await localPhotosFor(assetId) };
            }
        }
    } catch {
        setMessage('Registrazione della foto non riuscita: riprova.', false);
    } finally {
        busy.value = false;
    }
}

async function retryPhoto(photoId) {
    await sync.retryPhoto(photoId);
    if (state.online) await runSync();
    if (selected.value) {
        selected.value = { ...selected.value, photos: await localPhotosFor(selected.value.asset.id) };
    }
}

async function discardPhoto(photoId) {
    if (! window.confirm('Scartare definitivamente questa foto? Non verrà inviata.')) return;
    await sync.discardPhoto(photoId);
    if (selected.value) {
        selected.value = { ...selected.value, photos: await localPhotosFor(selected.value.asset.id) };
    }
}

function switchTab(key) {
    // Un tocco sulla barra con una checklist compilata non deve buttarla via
    if (inspectionHasWork()
        && ! window.confirm('Chiudere senza registrare l\'ispezione? Le risposte andranno perse.')) {
        return;
    }
    stopCamera();
    revokePhotoUrls();
    selected.value = null;
    selectedOrder.value = null;
    consuntivo.open = false;
    inspectionRun.open = false;
    resetAssetIssue();
    tab.value = key;
    refreshLocal();
    if (key === 'scansiona') {
        focusScanInput();
    }
    if (key === 'mappa') {
        nextTick(initOrRefreshMap);
    } else if (geoWatchId !== null) {
        // Il GPS ad alta precisione consuma batteria: si spegne uscendo dalla mappa
        navigator.geolocation.clearWatch(geoWatchId);
        geoWatchId = null;
        mapState.located = false;
    }
}

// ---- Mappa offline: sorgenti GeoJSON costruite dalla replica locale ----
const mapEl = ref(null);
let map = null;
let geoWatchId = null;
let mapFitted = false;
const mapState = reactive({ placing: false, located: false, clientId: '', areaId: '' });

// Committenti presenti fra le aree scaricate sul dispositivo: il filtro
// funziona anche senza rete, perche' legge la replica locale
const localClients = ref([]);
const localAreas = ref([]);

async function refreshMapFilters() {
    const aree = await db.areas.toArray();
    localAreas.value = aree.sort((a, b) => String(a.name).localeCompare(String(b.name)));
    const visti = new Map();
    for (const a of aree) {
        if (a.client_id && ! visti.has(a.client_id)) visti.set(a.client_id, a.client_name || 'Committente');
    }
    localClients.value = [...visti].map(([id, name]) => ({ id, name }))
        .sort((a, b) => a.name.localeCompare(b.name));
    // L'area scelta prima puo' non essere di questo committente
    if (mapState.areaId && ! aree.some((a) => a.id === mapState.areaId
        && (! mapState.clientId || a.client_id === mapState.clientId))) {
        mapState.areaId = '';
    }
}

/** Aree che passano il filtro: le usa sia la mappa sia il conteggio. */
const areeFiltrate = computed(() => localAreas.value.filter((a) => {
    if (mapState.areaId) return a.id === mapState.areaId;
    if (mapState.clientId) return a.client_id === mapState.clientId;

    return true;
}));

const TP_COLORS = { 1: '#16a34a', 2: '#475569', 3: '#d97706', 4: '#dc2626' };

async function localFeatureCollections() {
    const assets = await db.assets.toArray();
    await refreshMapFilters();
    const areas = areeFiltrate.value;
    const idAree = new Set(areas.map((a) => a.id));
    const assetFeatures = assets
        .filter((a) => a.geom_geojson && idAree.has(a.area_id))
        .map((a) => ({
            type: 'Feature',
            geometry: a.geom_geojson,
            properties: {
                id: a.id,
                census_code: a.census_code,
                tp: Number(typesById.value[a.object_type_id]?.code?.slice(1, 2) ?? 0),
                dirty: a.dirty ? 1 : 0,
            },
        }));
    const areaFeatures = areas
        .filter((a) => a.geom_geojson)
        .map((a) => ({ type: 'Feature', geometry: a.geom_geojson, properties: { name: a.name } }));
    return {
        assets: { type: 'FeatureCollection', features: assetFeatures },
        areas: { type: 'FeatureCollection', features: areaFeatures },
    };
}

function fitToData(data) {
    const bounds = new maplibregl.LngLatBounds();
    const extend = (coords) => {
        if (typeof coords[0] === 'number') bounds.extend(coords);
        else coords.forEach(extend);
    };
    data.areas.features.forEach((f) => extend(f.geometry.coordinates));
    data.assets.features.forEach((f) => extend(f.geometry.coordinates));
    if (! bounds.isEmpty()) {
        map.fitBounds(bounds, { padding: 40, maxZoom: 17 });
        mapFitted = true;
    }
}

async function initOrRefreshMap() {
    const data = await localFeatureCollections();
    // L'operatore può aver già cambiato scheda durante la lettura dei dati:
    // creare la mappa su un contenitore nascosto produrrebbe un canvas 0x0
    if (tab.value !== 'mappa') return;

    if (map) {
        map.getSource('el')?.setData(data.assets);
        map.getSource('aree')?.setData(data.areas);
        map.resize();
        // Mappa aperta prima dello scarico dati: si inquadra appena arrivano
        if (! mapFitted) fitToData(data);
        return;
    }
    if (! mapEl.value) return;

    map = new maplibregl.Map({
        container: mapEl.value,
        zoom: 15,
        center: [9.19, 45.465],
        attributionControl: false,
        style: {
            version: 8,
            sources: {
                osm: { type: 'raster', tiles: ['https://tile.openstreetmap.org/{z}/{x}/{y}.png'], tileSize: 256 },
            },
            layers: [
                { id: 'sfondo', type: 'background', paint: { 'background-color': '#e8ede9' } },
                { id: 'osm', type: 'raster', source: 'osm' },
            ],
        },
    });
    map.addControl(new maplibregl.NavigationControl({ showCompass: false }));
    map.addControl(new maplibregl.AttributionControl({
        compact: true,
        customAttribution: '© OpenStreetMap contributors',
    }));

    // 'style.load' e NON 'load': quest'ultimo attende anche le tile di sfondo,
    // che offline falliscono — gli overlay locali devono comparire comunque
    map.once('style.load', () => {
        map.addSource('aree', { type: 'geojson', data: data.areas });
        map.addSource('el', { type: 'geojson', data: data.assets });

        map.addLayer({
            id: 'aree-fill', type: 'fill', source: 'aree',
            paint: { 'fill-color': '#16a34a', 'fill-opacity': 0.08 },
        });
        map.addLayer({
            id: 'aree-line', type: 'line', source: 'aree',
            paint: { 'line-color': '#15803d', 'line-width': 1.5, 'line-dasharray': [3, 2] },
        });

        const colorExpr = ['match', ['get', 'tp'],
            1, TP_COLORS[1], 2, TP_COLORS[2], 3, TP_COLORS[3], 4, TP_COLORS[4], '#2563eb'];
        map.addLayer({
            id: 'el-fill', type: 'fill', source: 'el',
            filter: ['==', ['geometry-type'], 'Polygon'],
            paint: { 'fill-color': colorExpr, 'fill-opacity': 0.35 },
        });
        // Alone ambra sotto le geometrie non ancora inviate (i punti hanno il bordo)
        map.addLayer({
            id: 'el-dirty', type: 'line', source: 'el',
            filter: ['all',
                ['in', ['geometry-type'], ['literal', ['LineString', 'Polygon']]],
                ['==', ['get', 'dirty'], 1]],
            paint: { 'line-color': '#f59e0b', 'line-width': 6, 'line-opacity': 0.7 },
        });
        map.addLayer({
            id: 'el-line', type: 'line', source: 'el',
            filter: ['in', ['geometry-type'], ['literal', ['LineString', 'Polygon']]],
            paint: { 'line-color': colorExpr, 'line-width': 2 },
        });
        map.addLayer({
            id: 'el-point', type: 'circle', source: 'el',
            filter: ['==', ['geometry-type'], 'Point'],
            paint: {
                'circle-color': colorExpr,
                'circle-radius': 7,
                'circle-stroke-width': 2,
                'circle-stroke-color': ['case', ['==', ['get', 'dirty'], 1], '#f59e0b', '#ffffff'],
            },
        });

        // Inquadra le aree di lavoro scaricate
        const bounds = new maplibregl.LngLatBounds();
        const extend = (coords) => {
            if (typeof coords[0] === 'number') bounds.extend(coords);
            else coords.forEach(extend);
        };
        data.areas.features.forEach((f) => extend(f.geometry.coordinates));
        data.assets.features.forEach((f) => extend(f.geometry.coordinates));
        if (! bounds.isEmpty()) map.fitBounds(bounds, { padding: 40, maxZoom: 17 });

        map.on('click', async (e) => {
            if (mapState.placing) {
                // Posizionamento del nuovo rilievo dal tocco sulla mappa
                form.lat = e.lngLat.lat.toFixed(6);
                form.lon = e.lngLat.lng.toFixed(6);
                form.accuracy = null;
                mapState.placing = false;
                switchTab('rilievo');
                setMessage('Posizione impostata dalla mappa: completa il rilievo.');
                return;
            }
            // Tolleranza di tocco da dito: si cerca in un riquadro di 16 px
            const bbox = [[e.point.x - 8, e.point.y - 8], [e.point.x + 8, e.point.y + 8]];
            const hits = map.queryRenderedFeatures(bbox, { layers: ['el-point', 'el-fill', 'el-line'] });
            if (hits.length) {
                const asset = await db.assets.get(hits[0].properties.id);
                if (asset) await openAsset(asset);
            }
        });
    });
}

function locateOnMap() {
    if (! navigator.geolocation || ! map) return;
    if (geoWatchId !== null) navigator.geolocation.clearWatch(geoWatchId);
    // Un secondo tocco ricentra sulla posizione appena questa arriva
    mapState.located = false;
    geoWatchId = navigator.geolocation.watchPosition((pos) => {
        const lngLat = [pos.coords.longitude, pos.coords.latitude];
        const point = { type: 'Point', coordinates: lngLat };
        if (map.getSource('gps')) {
            map.getSource('gps').setData(point);
        } else {
            map.addSource('gps', { type: 'geojson', data: point });
            map.addLayer({
                id: 'gps', type: 'circle', source: 'gps',
                paint: { 'circle-color': '#2563eb', 'circle-radius': 8, 'circle-stroke-width': 3, 'circle-stroke-color': '#ffffff' },
            });
        }
        if (! mapState.located) {
            map.easeTo({ center: lngLat, zoom: 17 });
            mapState.located = true;
        }
    }, () => {
        setMessage('Posizione GPS non disponibile.', false);
    }, { enableHighAccuracy: true });
}

function stopCamera() {
    scan.cameraActive = false;
    cameraStream?.getTracks().forEach((t) => t.stop());
    cameraStream = null;
}

const unsubscribe = sync.onChange((s) => Object.assign(state, s));

function setMessage(text, ok = true) {
    message.text = text;
    message.ok = ok;
}

async function refreshLocal() {
    // La mappa aperta si aggiorna insieme ai dati (sync, nuovi rilievi, tombstone)
    if (map && tab.value === 'mappa') await initOrRefreshMap();
    bootstrapped.value = Boolean(await db.meta.get('bootstrapped_at'));
    areas.value = await db.areas.orderBy('name').toArray();
    const types = await db.catalog_types.toArray();
    typesById.value = Object.fromEntries(types.map((t) => [t.id, t]));
    pointTypes.value = types
        .filter((t) => t.allowed_geometry === 'P')
        .sort((a, b) => a.code.localeCompare(b.code));
    localAssets.value = (await db.assets.orderBy('updated_at').reverse().limit(200).toArray());
    localOrders.value = await db.work_orders.toArray();
    localTemplates.value = await db.inspection_templates.orderBy('name').toArray();
    // Un modello selezionato ma non più sul device non deve restare scelto
    if (inspectionPick.assetTemplateId && ! localTemplates.value.some((t) => t.id === inspectionPick.assetTemplateId)) {
        inspectionPick.assetTemplateId = '';
    }
    if (inspectionPick.areaTemplateId && ! localTemplates.value.some((t) => t.id === inspectionPick.areaTemplateId)) {
        inspectionPick.areaTemplateId = '';
    }
    // La scheda ordine aperta segue le sincronizzazioni in background: mai
    // proporre azioni su uno stato o una versione ormai superati
    if (selectedOrder.value) {
        const current = await db.work_orders.get(selectedOrder.value.id);
        if (current) {
            selectedOrder.value = current;
        } else {
            // L'avviso serve solo se l'operatore stava compilando il consuntivo:
            // dopo una chiusura fatta da lui la scomparsa è quella attesa
            if (consuntivo.open) {
                setMessage('Il lavoro aperto non è più sul dispositivo: probabilmente è stato chiuso o riassegnato da altri.', false);
            }
            selectedOrder.value = null;
            consuntivo.open = false;
        }
    }
    queueRows.value = await db.sync_queue.orderBy('device_seq').toArray();
    logRows.value = (await db.sync_log.orderBy('seq').reverse().limit(30).toArray());
    await sync.notify();
}

async function downloadWorkingSet() {
    busy.value = true;
    setMessage('');
    try {
        await sync.bootstrap();
        await refreshLocal();
        setMessage('Dati di lavoro scaricati sul dispositivo.');
    } catch {
        setMessage('Scarico non riuscito: serve la connessione.', false);
    } finally {
        busy.value = false;
    }
}

function locate() {
    if (! navigator.geolocation) {
        setMessage('Geolocalizzazione non disponibile su questo dispositivo.', false);
        return;
    }
    form.locating = true;
    navigator.geolocation.getCurrentPosition(
        (pos) => {
            form.lat = pos.coords.latitude.toFixed(6);
            form.lon = pos.coords.longitude.toFixed(6);
            form.accuracy = Math.round(pos.coords.accuracy * 10) / 10;
            form.locating = false;
        },
        () => {
            form.locating = false;
            setMessage('Posizione GPS non disponibile: inserisci le coordinate a mano.', false);
        },
        { enableHighAccuracy: true, timeout: 10000 },
    );
}

// Accetta anche la virgola decimale italiana; il campo vuoto NON vale zero
function parseCoordinate(value) {
    const text = String(value).trim().replace(',', '.');
    if (text === '') return NaN;
    return Number(text);
}

async function saveCensus() {
    setMessage('');
    const lat = parseCoordinate(form.lat);
    const lon = parseCoordinate(form.lon);
    if (! form.areaId || ! form.typeId) {
        setMessage('Seleziona area e tipo oggetto.', false);
        return;
    }
    if (! Number.isFinite(lat) || ! Number.isFinite(lon) || Math.abs(lat) > 90 || Math.abs(lon) > 180) {
        setMessage('Coordinate mancanti o fuori intervallo.', false);
        return;
    }

    busy.value = true;
    try {
        await sync.enqueueAssetCreate({
            areaId: form.areaId,
            objectTypeId: form.typeId,
            censusCode: form.censusCode.trim(),
            notes: form.notes.trim(),
            geometry: { type: 'Point', coordinates: [lon, lat] },
            gpsAccuracy: form.accuracy,
            surveyMethod: form.accuracy != null ? 'gps' : 'manual_map',
        });
        Object.assign(form, { censusCode: '', notes: '', lat: '', lon: '', accuracy: null });
        await refreshLocal();
        setMessage(state.online
            ? 'Elemento registrato: verrà sincronizzato ora.'
            : 'Elemento registrato sul dispositivo: verrà inviato quando torna la rete.');
        if (state.online) await runSync();
    } catch {
        setMessage('Salvataggio locale non riuscito: riprova.', false);
    } finally {
        busy.value = false;
    }
}

async function runSync() {
    await sync.syncNow();
    await refreshLocal();
}

// ---- I miei lavori: avvio, sospensione e consuntivo dal campo ----

const WO_STATUS = {
    planned: { label: 'Pianificato', cls: 'bg-gray-200 text-gray-700' },
    assigned: { label: 'Assegnato', cls: 'bg-blue-100 text-blue-800' },
    in_progress: { label: 'In corso', cls: 'bg-green-100 text-green-800' },
    suspended: { label: 'Sospeso', cls: 'bg-amber-100 text-amber-800' },
    completed: { label: 'Completato', cls: 'bg-gray-200 text-gray-600' },
    cancelled: { label: 'Annullato', cls: 'bg-gray-200 text-gray-600' },
};
const WO_PRIORITY = { low: 'Bassa', normal: 'Normale', high: 'Alta', urgent: 'Urgente' };

// Azioni consentite dal campo (rispecchia le regole del server)
const WO_ACTIONS = {
    assigned: [{ to: 'in_progress', label: 'Avvia il lavoro' }],
    in_progress: [{ to: 'suspended', label: 'Sospendi' }],
    suspended: [{ to: 'in_progress', label: 'Riprendi' }],
};

const ORDER_SORT = { in_progress: 0, suspended: 1, assigned: 2, planned: 3, completed: 4, cancelled: 5 };
const sortedOrders = computed(() => [...localOrders.value]
    .sort((a, b) => (ORDER_SORT[a.status] ?? 9) - (ORDER_SORT[b.status] ?? 9) || String(a.code).localeCompare(String(b.code))));

const fmtDate = (iso) => (iso ? new Date(iso).toLocaleDateString('it-IT') : '');

function openOrder(order) {
    consuntivo.open = false;
    selectedOrder.value = order;
}

async function reloadOrder(orderId) {
    await refreshLocal();
    const updated = await db.work_orders.get(orderId);
    selectedOrder.value = updated ?? null;
    return updated;
}

async function orderTransition(order, to) {
    busy.value = true;
    try {
        await sync.enqueueWoTransition({ workOrderId: order.id, status: to });
        // L'ora di avvio serve al consuntivo: si fissa al primo passaggio
        // "in corso" e non si tocca alla ripresa dopo una sospensione
        if (to === 'in_progress' && ! order.field_started_at) {
            await db.work_orders.update(order.id, { field_started_at: new Date().toISOString() });
        }
        setMessage(state.online
            ? 'Stato aggiornato: sincronizzazione in corso.'
            : 'Stato aggiornato sul dispositivo: verrà inviato quando torna la rete.');
        if (state.online) await runSync();
        await reloadOrder(order.id);
    } catch {
        setMessage('Aggiornamento locale non riuscito: riprova.', false);
    } finally {
        busy.value = false;
    }
}

function openConsuntivo(order) {
    Object.assign(consuntivo, {
        open: true,
        manHours: null,
        quantity: null,
        unit: order.work_type?.unit ?? '',
        notes: '',
    });
}

async function completeWithConsuntivo(order) {
    // Le stesse regole del server, verificate PRIMA di accodare: un consuntivo
    // rifiutato dopo la chiusura non avrebbe più una via di correzione dal campo
    const manHours = numOrNull(consuntivo.manHours);
    const quantity = numOrNull(consuntivo.quantity);
    const unit = consuntivo.unit.trim();
    if (manHours !== null && (manHours < 0 || manHours > 9999.99)) {
        setMessage('Ore uomo: inserisci un valore tra 0 e 9999,99.', false);
        return;
    }
    if (quantity !== null && (quantity < 0 || quantity > 99999999)) {
        setMessage('Quantità eseguita: inserisci un valore tra 0 e 99.999.999.', false);
        return;
    }
    if (unit.length > 20) {
        setMessage('Unità di misura: al massimo 20 caratteri.', false);
        return;
    }

    busy.value = true;
    try {
        await sync.enqueueConsuntivoAndComplete({
            workOrderId: order.id,
            startedAt: order.field_started_at ?? new Date().toISOString(),
            endedAt: new Date().toISOString(),
            manHours,
            quantity,
            unit: unit || null,
            notes: consuntivo.notes.trim() || null,
        });
        consuntivo.open = false;
        setMessage(state.online
            ? 'Lavoro completato con consuntivo: sincronizzazione in corso.'
            : 'Lavoro completato sul dispositivo: verrà inviato quando torna la rete.');
        if (state.online) await runSync();
        await reloadOrder(order.id);
    } catch (err) {
        if (err?.code === 'ORDER_GONE') {
            consuntivo.open = false;
            selectedOrder.value = null;
            await refreshLocal();
            setMessage('Questo lavoro non è più sul dispositivo: probabilmente è stato chiuso da un collega. Nulla è stato inviato.', false);
        } else {
            setMessage('Registrazione del consuntivo non riuscita: riprova.', false);
        }
    } finally {
        busy.value = false;
    }
}

async function discardCommand(cmd) {
    // Consuntivi e segnalazioni non sono mai arrivati al server: scartarli
    // li perde e basta, non c'è nessuna "versione del server" che subentra
    const isLocalOnly = ['work_log.add', 'issue.create', 'inspection.complete'].includes(cmd.type);
    const ok = window.confirm(isLocalOnly
        ? `Scartare definitivamente l'operazione #${cmd.device_seq} (${cmd.type})? Non è mai arrivata al server e andrà persa.`
        : `Scartare definitivamente l'operazione #${cmd.device_seq} (${cmd.type})? `
            + 'La modifica locale andrà persa e varrà la versione del server.');
    if (! ok) return;
    await sync.discard(cmd.idempotency_key);
    await refreshLocal();
    setMessage(isLocalOnly
        ? 'Operazione scartata definitivamente.'
        : 'Operazione scartata: al prossimo aggiornamento varrà la versione del server.');
}

async function retryCommand(cmd) {
    await sync.retry(cmd.idempotency_key);
    await refreshLocal();
    if (state.online) await runSync();
}

// Le righe del server portano solo object_type_id: il nome si risolve dal catalogo locale
const typesById = ref({});
const typeLabel = (asset) => asset.object_type?.name
    ?? typesById.value[asset.object_type_id]?.name
    ?? asset.object_type?.code ?? '—';
const fmtTime = (iso) => (iso ? new Date(iso).toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' }) : '');

const QUEUE_LABELS = {
    PENDING: 'In attesa di invio',
    INFLIGHT: 'Invio in corso',
    CONFLICT: 'Conflitto da risolvere',
    NEEDS_ATTENTION: 'Rifiutato: da correggere',
};

const TAG_TYPES = { qr: 'QR', barcode: 'Barcode', nfc: 'NFC', rfid: 'RFID', arbotag: 'Arbotag' };

const badge = computed(() => {
    const toSend = state.pending + (state.photos ?? 0);
    const toReview = state.attention + (state.photosFailed ?? 0);
    if (! state.online) return { text: `Offline — ${toSend} da inviare`, cls: 'bg-gray-700 text-white' };
    if (state.syncing) return { text: 'Sincronizzazione…', cls: 'bg-blue-700 text-white' };
    if (toReview > 0) return { text: `${toReview} da rivedere`, cls: 'bg-red-700 text-white' };
    if (toSend > 0) return { text: `${toSend} da inviare`, cls: 'bg-amber-600 text-white' };
    return { text: 'Sincronizzato', cls: 'bg-green-700 text-white' };
});

function onOnline() {
    sync.notify();
    runSync();
}
function onOffline() {
    sync.notify();
}

onMounted(async () => {
    window.addEventListener('online', onOnline);
    window.addEventListener('offline', onOffline);
    await refreshLocal();
    if (state.online && ! bootstrapped.value) {
        await downloadWorkingSet();
    } else if (state.online && state.pending > 0) {
        await runSync();
    }
});

onBeforeUnmount(() => {
    window.removeEventListener('online', onOnline);
    window.removeEventListener('offline', onOffline);
    if (geoWatchId !== null) navigator.geolocation?.clearWatch(geoWatchId);
    map?.remove();
    stopCamera();
    revokePhotoUrls();
    unsubscribe();
});
</script>

<template>
    <Head title="Operatore" />

    <div class="flex min-h-screen flex-col bg-gray-100">
        <!-- Intestazione con indicatore di sincronizzazione sempre visibile -->
        <header class="sticky top-0 z-10 border-b border-gray-200 bg-white">
            <div class="flex items-center justify-between gap-2 px-4 py-3">
                <div class="min-w-0">
                    <div class="truncate text-sm font-semibold leading-tight">WebGIS Operatore</div>
                    <div class="truncate text-xs text-gray-500">{{ user.name }}</div>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <span class="rounded-full px-3 py-1 text-xs font-medium" :class="badge.cls" data-test="sync-badge">
                        {{ badge.text }}
                    </span>
                    <!-- Ritorno al programma completo: in campo si passa
                         continuamente dall'app alle pagine di gestione -->
                    <a
                        href="/oggi"
                        class="rounded-lg border border-gray-300 px-2.5 py-1.5 text-xs font-medium text-gray-700"
                        data-test="torna-gestione"
                    >Gestione →</a>
                </div>
            </div>
        </header>

        <main class="flex-1 p-4 pb-24">
            <p
                v-if="message.text"
                class="mb-3 rounded-lg px-3 py-2 text-sm"
                :class="message.ok ? 'bg-green-100 text-green-900' : 'bg-red-100 text-red-900'"
                data-test="message"
            >{{ message.text }}</p>

            <!-- RILIEVO -->
            <section v-if="tab === 'rilievo'">
                <h1 class="text-base font-semibold">Nuovo rilievo</h1>
                <p v-if="! bootstrapped" class="mt-2 rounded-lg bg-amber-100 px-3 py-2 text-sm text-amber-900">
                    Prima di lavorare offline scarica i dati di lavoro dalla sezione Sincronizzazione.
                </p>

                <div class="mt-3 space-y-3 rounded-xl border border-gray-200 bg-white p-4">
                    <label class="block text-xs">
                        <span class="text-gray-500">Area di lavoro *</span>
                        <select v-model="form.areaId" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-2.5 text-sm">
                            <option value="" disabled>Seleziona…</option>
                            <option v-for="a in areas" :key="a.id" :value="a.id">{{ a.name }}</option>
                        </select>
                    </label>
                    <label class="block text-xs">
                        <span class="text-gray-500">Tipo oggetto (puntuale) *</span>
                        <select v-model="form.typeId" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-2.5 text-sm">
                            <option value="" disabled>Seleziona…</option>
                            <option v-for="t in pointTypes" :key="t.id" :value="t.id">{{ t.code }} — {{ t.name }}</option>
                        </select>
                    </label>
                    <label class="block text-xs">
                        <span class="text-gray-500">Codice censimento</span>
                        <input v-model="form.censusCode" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm" placeholder="es. ALB-0102">
                    </label>

                    <div class="grid grid-cols-2 gap-3">
                        <label class="block text-xs">
                            <span class="text-gray-500">Latitudine *</span>
                            <input v-model="form.lat" inputmode="decimal" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm" placeholder="45.4652" @input="form.accuracy = null">
                        </label>
                        <label class="block text-xs">
                            <span class="text-gray-500">Longitudine *</span>
                            <input v-model="form.lon" inputmode="decimal" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm" placeholder="9.1905" @input="form.accuracy = null">
                        </label>
                    </div>
                    <div class="flex items-center gap-3">
                        <button
                            class="rounded-lg border border-green-700 px-3 py-2 text-sm font-medium text-green-700"
                            :disabled="form.locating"
                            @click="locate"
                        >{{ form.locating ? 'Rilevamento…' : 'Usa posizione GPS' }}</button>
                        <span v-if="form.accuracy" class="text-xs text-gray-500">Precisione: {{ form.accuracy }} m</span>
                    </div>

                    <label class="block text-xs">
                        <span class="text-gray-500">Note</span>
                        <textarea v-model="form.notes" rows="2" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm" />
                    </label>

                    <button
                        class="w-full rounded-lg bg-green-700 px-4 py-3 text-sm font-semibold text-white disabled:opacity-50"
                        :disabled="busy || ! bootstrapped"
                        data-test="save-census"
                        @click="saveCensus"
                    >Registra elemento</button>
                </div>

                <!-- Ispezione su un'area (checklist dal modello) -->
                <div v-if="areaTemplates.length" class="mt-3 rounded-xl border border-gray-200 bg-white p-4">
                    <h2 class="text-sm font-semibold">Ispezione di un'area</h2>
                    <div class="mt-3 space-y-3">
                        <label class="block text-xs">
                            <span class="text-gray-500">Area *</span>
                            <select v-model="inspectionPick.areaId" data-test="area-inspection-area" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-2.5 text-sm">
                                <option value="" disabled>Seleziona…</option>
                                <option v-for="a in areas" :key="a.id" :value="a.id">{{ a.name }}</option>
                            </select>
                        </label>
                        <label class="block text-xs">
                            <span class="text-gray-500">Modello di controllo *</span>
                            <select v-model="inspectionPick.areaTemplateId" data-test="area-inspection-template" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-2.5 text-sm">
                                <option value="" disabled>Seleziona…</option>
                                <option v-for="t in areaTemplates" :key="t.id" :value="t.id">{{ t.name }}</option>
                            </select>
                        </label>
                        <button
                            class="w-full rounded-lg border border-green-700 px-4 py-2.5 text-sm font-medium text-green-700 disabled:opacity-50"
                            :disabled="! inspectionPick.areaId || ! inspectionPick.areaTemplateId || ! bootstrapped"
                            data-test="area-inspection-start"
                            @click="startInspection(areaTemplates.find((t) => t.id === inspectionPick.areaTemplateId), { areaId: inspectionPick.areaId })"
                        >Compila la checklist</button>
                    </div>
                </div>

                <!-- Segnalazione generica sull'area (senza elemento preciso) -->
                <div class="mt-3 rounded-xl border border-gray-200 bg-white p-4">
                    <h2 class="text-sm font-semibold">Segnala un problema nell'area</h2>
                    <p class="mt-1 text-xs text-gray-400">
                        Per un problema su un elemento preciso apri la sua scheda da Elementi o Scansiona.
                    </p>
                    <div class="mt-3 space-y-3">
                        <label class="block text-xs">
                            <span class="text-gray-500">Area interessata *</span>
                            <select v-model="areaIssue.areaId" data-test="area-issue-area" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-2.5 text-sm">
                                <option value="" disabled>Seleziona…</option>
                                <option v-for="a in areas" :key="a.id" :value="a.id">{{ a.name }}</option>
                            </select>
                        </label>
                        <label class="block text-xs">
                            <span class="text-gray-500">Cosa hai notato *</span>
                            <textarea v-model="areaIssue.description" rows="2" data-test="area-issue-description" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm" placeholder="es. Recinzione divelta vicino all'ingresso nord" />
                        </label>
                        <label class="block text-xs">
                            <span class="text-gray-500">Gravità</span>
                            <select v-model="areaIssue.severity" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-2 text-sm">
                                <option v-for="(label, value) in ISSUE_SEVERITY" :key="value" :value="value">{{ label }}</option>
                            </select>
                        </label>
                        <button
                            class="w-full rounded-lg border border-green-700 px-4 py-2.5 text-sm font-medium text-green-700 disabled:opacity-50"
                            :disabled="busy || ! bootstrapped"
                            data-test="area-issue-save"
                            @click="submitAreaIssue"
                        >Invia la segnalazione</button>
                    </div>
                </div>
            </section>

            <!-- ELEMENTI -->
            <section v-if="tab === 'elementi'">
                <h1 class="text-base font-semibold">Elementi sul dispositivo ({{ localAssets.length }})</h1>
                <ul class="mt-3 divide-y divide-gray-100 rounded-xl border border-gray-200 bg-white" data-test="local-assets">
                    <li
                        v-for="a in localAssets"
                        :key="a.id"
                        class="flex cursor-pointer items-center gap-3 px-4 py-2.5 text-sm hover:bg-gray-50"
                        @click="openAsset(a)"
                    >
                        <div class="min-w-0 flex-1">
                            <div class="font-medium">{{ a.census_code || a.id.slice(0, 8) }}</div>
                            <div class="truncate text-xs text-gray-500">{{ typeLabel(a) }}</div>
                        </div>
                        <span
                            v-if="a.dirty"
                            class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800"
                        >da inviare</span>
                        <span v-else class="text-xs text-gray-400">v{{ a.version }}</span>
                    </li>
                    <li v-if="! localAssets.length" class="px-4 py-5 text-sm text-gray-400">
                        Nessun elemento sul dispositivo. Scarica i dati di lavoro.
                    </li>
                </ul>
            </section>

            <!-- MAPPA (i dati vengono dalla replica locale: funziona offline) -->
            <section v-show="tab === 'mappa'" class="-m-4 h-full">
                <div class="relative" style="height: calc(100vh - 3.5rem - 3.5rem);">
                    <div ref="mapEl" class="h-full w-full" data-test="field-map" />
                    <div class="absolute left-3 right-3 top-3 flex flex-col gap-2">
                        <!-- Filtri: chi guardo e dove. Leggono la copia locale,
                             quindi funzionano anche senza rete -->
                        <div class="flex gap-2">
                            <select
                                v-if="localClients.length"
                                v-model="mapState.clientId"
                                data-test="mappa-campo-committente"
                                class="min-w-0 flex-1 rounded-lg bg-white px-2 py-2 text-xs shadow"
                                @change="mapState.areaId = ''; initOrRefreshMap()"
                            >
                                <option value="">Tutti i committenti</option>
                                <option v-for="c in localClients" :key="c.id" :value="c.id">{{ c.name }}</option>
                            </select>
                            <select
                                v-model="mapState.areaId"
                                data-test="mappa-campo-area"
                                class="min-w-0 flex-1 rounded-lg bg-white px-2 py-2 text-xs shadow"
                                @change="initOrRefreshMap()"
                            >
                                <option value="">Tutte le aree</option>
                                <option
                                    v-for="a in localAreas.filter((x) => ! mapState.clientId || x.client_id === mapState.clientId)"
                                    :key="a.id"
                                    :value="a.id"
                                >{{ a.name }}</option>
                            </select>
                        </div>
                        <button
                            class="self-start rounded-lg bg-white px-3 py-2 text-xs font-medium shadow"
                            @click="locateOnMap"
                        >La mia posizione</button>
                        <button
                            class="self-start rounded-lg px-3 py-2 text-xs font-medium shadow"
                            :class="mapState.placing ? 'bg-green-700 text-white' : 'bg-white'"
                            data-test="map-place"
                            @click="mapState.placing = ! mapState.placing"
                        >{{ mapState.placing ? 'Tocca la mappa per il rilievo…' : 'Nuovo rilievo dalla mappa' }}</button>
                    </div>
                </div>
            </section>

            <!-- SCANSIONA -->
            <section v-if="tab === 'scansiona'">
                <h1 class="text-base font-semibold">Scansiona tag</h1>
                <div class="mt-3 space-y-3 rounded-xl border border-gray-200 bg-white p-4">
                    <!-- Il lettore a pistola scrive come una tastiera e chiude con
                         Invio: il campo e' il primo elemento, sempre a fuoco, e la
                         ricerca parte da sola. La fotocamera resta un'alternativa -->
                    <label class="block text-xs">
                        <span class="text-gray-500">Codice del tag (spara con il lettore o digita)</span>
                        <input
                            ref="scanInput"
                            v-model="scan.uid"
                            data-test="scan-uid"
                            autofocus
                            autocomplete="off"
                            autocapitalize="off"
                            spellcheck="false"
                            class="mt-1 w-full rounded-lg border-2 border-green-700 px-3 py-3 text-base"
                            placeholder="es. QR-000123"
                            @keyup.enter="doScan"
                        >
                    </label>
                    <button
                        class="w-full rounded-lg bg-green-700 px-4 py-3 text-base font-semibold text-white"
                        data-test="scan-search"
                        @click="doScan"
                    >Cerca elemento</button>

                    <div class="flex items-center gap-2 text-xs">
                        <label class="flex-1">
                            <span class="text-gray-500">Tipo tag</span>
                            <select v-model="scan.tagType" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-2 text-sm">
                                <option v-for="(label, value) in TAG_TYPES" :key="value" :value="value">{{ label }}</option>
                            </select>
                        </label>
                        <button
                            v-if="scan.cameraSupported && ! scan.cameraActive"
                            class="mt-4 rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700"
                            @click="startCamera"
                        >Usa la fotocamera</button>
                    </div>

                    <div v-if="scan.cameraActive" class="overflow-hidden rounded-lg bg-black">
                        <video ref="videoEl" class="h-56 w-full object-cover" muted playsinline />
                        <button class="w-full bg-gray-800 py-2 text-sm font-medium text-white" @click="stopCamera">
                            Ferma la fotocamera
                        </button>
                    </div>
                    <p v-if="scan.cameraError" class="text-xs text-amber-700">{{ scan.cameraError }}</p>

                    <div v-if="scan.notFound" class="rounded-lg bg-amber-50 p-3">
                        <p class="text-sm text-amber-900">Tag non riconosciuto. Puoi associarlo a un elemento:</p>
                        <select v-model="scan.targetId" data-test="scan-target" class="mt-2 w-full rounded-lg border border-gray-300 px-2 py-2 text-sm">
                            <option value="" disabled>Scegli l'elemento…</option>
                            <option v-for="a in localAssets" :key="a.id" :value="a.id">
                                {{ a.census_code || a.id.slice(0, 8) }} — {{ typeLabel(a) }}
                            </option>
                        </select>
                        <button
                            v-if="canAssociate"
                            class="mt-2 w-full rounded-lg bg-green-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                            :disabled="! scan.targetId || busy"
                            data-test="scan-associate"
                            @click="associateTag(scan.targetId, scan.uid, scan.tagType); scan.notFound = false;"
                        >Associa il tag</button>
                    </div>
                </div>
            </section>

            <!-- LAVORI -->
            <section v-if="tab === 'lavori'">
                <h1 class="text-base font-semibold">I miei lavori ({{ localOrders.length }})</h1>
                <ul class="mt-3 divide-y divide-gray-100 rounded-xl border border-gray-200 bg-white" data-test="wo-list">
                    <li
                        v-for="o in sortedOrders"
                        :key="o.id"
                        class="cursor-pointer px-4 py-2.5 text-sm hover:bg-gray-50"
                        data-test="wo-item"
                        @click="openOrder(o)"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-medium">{{ o.code }}</span>
                            <span class="flex items-center gap-1.5">
                                <span
                                    v-if="o.dirty"
                                    class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800"
                                >da inviare</span>
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="WO_STATUS[o.status]?.cls"
                                >{{ WO_STATUS[o.status]?.label ?? o.status }}</span>
                            </span>
                        </div>
                        <div class="mt-0.5 truncate text-xs text-gray-600">{{ o.title }}</div>
                        <div class="mt-0.5 text-xs text-gray-500">
                            {{ o.work_type?.name ?? 'Lavorazione non indicata' }}
                            · {{ (o.assets ?? []).length }} {{ (o.assets ?? []).length === 1 ? 'elemento' : 'elementi' }}
                            <template v-if="o.planned_start"> · dal {{ fmtDate(o.planned_start) }}</template>
                            <template v-if="o.priority === 'high' || o.priority === 'urgent'">
                                · Priorità {{ WO_PRIORITY[o.priority].toLowerCase() }}
                            </template>
                        </div>
                    </li>
                    <li v-if="! localOrders.length" class="px-4 py-5 text-sm text-gray-400">
                        Nessun lavoro assegnato sul dispositivo. Scarica i dati di lavoro dalla sezione Sync.
                    </li>
                </ul>
            </section>

            <!-- SINCRONIZZAZIONE -->
            <section v-if="tab === 'sync'">
                <h1 class="text-base font-semibold">Sincronizzazione</h1>

                <div class="mt-3 space-y-3">
                    <div class="rounded-xl border border-gray-200 bg-white p-4">
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-gray-500">Stato rete</span>
                            <span :class="state.online ? 'font-medium text-green-700' : 'font-medium text-gray-700'">
                                {{ state.online ? 'Connesso' : 'Offline' }}
                            </span>
                        </div>
                        <div class="mt-2 flex items-center justify-between text-sm">
                            <span class="text-gray-500">Operazioni in coda</span>
                            <span class="font-medium" data-test="queue-count">{{ state.pending }}</span>
                        </div>
                        <div class="mt-2 flex items-center justify-between text-sm">
                            <span class="text-gray-500">Foto da inviare</span>
                            <span class="font-medium" data-test="photo-count">{{ state.photos }}</span>
                        </div>
                        <div class="mt-2 flex items-center justify-between text-sm">
                            <span class="text-gray-500">Da rivedere (conflitti/rifiuti)</span>
                            <span class="font-medium" :class="state.attention ? 'text-red-700' : ''">{{ state.attention }}</span>
                        </div>
                        <div class="mt-3 grid grid-cols-2 gap-2">
                            <button
                                class="rounded-lg border border-green-700 px-3 py-2.5 text-sm font-medium text-green-700 disabled:opacity-50"
                                :disabled="busy || ! state.online"
                                data-test="download-ws"
                                @click="downloadWorkingSet"
                            >Scarica i dati di lavoro</button>
                            <button
                                class="rounded-lg bg-green-700 px-3 py-2.5 text-sm font-semibold text-white disabled:opacity-50"
                                :disabled="state.syncing || ! state.online"
                                data-test="sync-now"
                                @click="runSync"
                            >Sincronizza ora</button>
                        </div>
                    </div>

                    <div v-if="queueRows.length" class="rounded-xl border border-gray-200 bg-white">
                        <h2 class="border-b border-gray-100 px-4 py-2.5 text-sm font-semibold">Coda operazioni</h2>
                        <ul class="divide-y divide-gray-50">
                            <li v-for="c in queueRows" :key="c.idempotency_key" class="px-4 py-2 text-sm">
                                <div class="flex items-center justify-between">
                                    <span class="font-medium">#{{ c.device_seq }} · {{ c.type }}</span>
                                    <span class="text-xs" :class="c.status === 'PENDING' ? 'text-amber-700' : c.status === 'CONFLICT' || c.status === 'NEEDS_ATTENTION' ? 'text-red-700' : 'text-gray-500'">
                                        {{ QUEUE_LABELS[c.status] ?? c.status }}
                                    </span>
                                </div>
                                <p v-if="c.last_error" class="mt-0.5 text-xs text-red-600">{{ c.last_error }}</p>
                                <div v-if="c.status === 'CONFLICT' || c.status === 'NEEDS_ATTENTION'" class="mt-1.5 flex gap-2">
                                    <button
                                        v-if="c.status === 'NEEDS_ATTENTION'"
                                        class="rounded border border-green-700 px-2.5 py-1 text-xs font-medium text-green-700"
                                        @click="retryCommand(c)"
                                    >Riprova</button>
                                    <button
                                        class="rounded border border-red-300 px-2.5 py-1 text-xs font-medium text-red-700"
                                        @click="discardCommand(c)"
                                    >Scarta (vale il server)</button>
                                </div>
                            </li>
                        </ul>
                    </div>

                    <div class="rounded-xl border border-gray-200 bg-white">
                        <h2 class="border-b border-gray-100 px-4 py-2.5 text-sm font-semibold">Registro attività</h2>
                        <ul class="divide-y divide-gray-50" data-test="sync-log">
                            <li v-for="l in logRows" :key="l.seq" class="px-4 py-1.5 text-xs">
                                <span class="text-gray-400">{{ fmtTime(l.ts) }}</span>
                                <span class="ml-2" :class="l.level === 'error' ? 'text-red-700' : l.level === 'warn' ? 'text-amber-700' : 'text-gray-700'">
                                    {{ l.message }}
                                </span>
                            </li>
                            <li v-if="! logRows.length" class="px-4 py-3 text-xs text-gray-400">Nessuna attività registrata.</li>
                        </ul>
                    </div>
                </div>
            </section>
        </main>

        <!-- Scheda elemento (sovrapposta; la barra in basso resta raggiungibile) -->
        <div v-if="selected" class="fixed inset-x-0 bottom-14 top-0 z-20 flex flex-col bg-gray-100" data-test="asset-detail">
            <header class="border-b border-gray-200 bg-white px-4 py-3">
                <button class="text-sm font-medium text-green-700" @click="selected = null">← Indietro</button>
                <div class="mt-1 flex items-center justify-between">
                    <div>
                        <div class="text-base font-semibold">{{ selected.asset.census_code || selected.asset.id.slice(0, 8) }}</div>
                        <div class="text-xs text-gray-500">{{ typeLabel(selected.asset) }}</div>
                    </div>
                    <span
                        v-if="selected.asset.dirty"
                        class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800"
                    >da inviare</span>
                    <span v-else class="text-xs text-gray-400">v{{ selected.asset.version }}</span>
                </div>
            </header>

            <div class="flex-1 space-y-3 overflow-y-auto p-4">
                <p
                    v-if="message.text"
                    class="rounded-lg px-3 py-2 text-sm"
                    :class="message.ok ? 'bg-green-100 text-green-900' : 'bg-red-100 text-red-900'"
                    data-test="detail-message"
                >{{ message.text }}</p>

                <!-- Misure albero -->
                <div v-if="selected.tree" class="rounded-xl border border-gray-200 bg-white p-4">
                    <h2 class="text-sm font-semibold">Misure albero</h2>
                    <div class="mt-2 grid grid-cols-2 gap-3">
                        <label class="col-span-2 block text-xs">
                            <span class="text-gray-500">Specie</span>
                            <input v-model="measureForm.species" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm">
                        </label>
                        <label class="block text-xs">
                            <span class="text-gray-500">Altezza (m)</span>
                            <input v-model.number="measureForm.height_m" type="number" step="0.1" min="0" data-test="measure-height" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm">
                        </label>
                        <label class="block text-xs">
                            <span class="text-gray-500">Diametro fusto (cm)</span>
                            <input v-model.number="measureForm.dbh_cm" type="number" step="0.5" min="0" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm">
                        </label>
                        <label class="block text-xs">
                            <span class="text-gray-500">Diametro chioma (m)</span>
                            <input v-model.number="measureForm.crown_diameter_m" type="number" step="0.5" min="0" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm">
                        </label>
                    </div>
                    <button
                        class="mt-3 w-full rounded-lg bg-green-700 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50"
                        :disabled="busy"
                        data-test="save-measures"
                        @click="saveMeasures"
                    >Salva misure</button>
                </div>

                <!-- Tag fisici -->
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <h2 class="text-sm font-semibold">Tag fisici ({{ selected.tags.length }})</h2>
                    <ul v-if="selected.tags.length" class="mt-2 space-y-1">
                        <li v-for="t in selected.tags" :key="t.id" class="text-sm">
                            <span class="font-medium">{{ TAG_TYPES[t.tag_type] ?? t.tag_type }}</span>
                            <span class="ml-2 text-gray-600">{{ t.uid }}</span>
                        </li>
                    </ul>
                    <p v-else class="mt-2 text-sm text-gray-400">Nessun tag associato.</p>

                    <div v-if="canAssociate" class="mt-3 grid grid-cols-3 gap-2">
                        <select v-model="tagForm.tagType" class="rounded-lg border border-gray-300 px-2 py-2 text-sm">
                            <option v-for="(label, value) in TAG_TYPES" :key="value" :value="value">{{ label }}</option>
                        </select>
                        <input v-model="tagForm.uid" data-test="tag-uid" placeholder="Codice tag" class="col-span-2 rounded-lg border border-gray-300 px-2.5 py-2 text-sm">
                    </div>
                    <button
                        v-if="canAssociate"
                        class="mt-2 w-full rounded-lg border border-green-700 px-4 py-2 text-sm font-medium text-green-700 disabled:opacity-50"
                        :disabled="busy"
                        data-test="tag-associate"
                        @click="associateTag(selected.asset.id, tagForm.uid, tagForm.tagType)"
                    >Associa tag</button>
                </div>

                <!-- Foto di campo -->
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-semibold">Foto in coda ({{ selected.photos.length }})</h2>
                        <label v-if="canAssociate" class="cursor-pointer rounded-lg bg-green-700 px-3 py-1.5 text-sm font-medium text-white">
                            {{ busy ? 'Elaborazione…' : 'Scatta o scegli foto' }}
                            <input type="file" accept="image/*" capture="environment" class="hidden" :disabled="busy" data-test="photo-input" @change="takePhoto">
                        </label>
                    </div>
                    <div v-if="selected.photos.length" class="mt-3 grid grid-cols-3 gap-2" data-test="photo-grid">
                        <div v-for="p in selected.photos" :key="p.photo_id">
                            <div class="relative aspect-square overflow-hidden rounded-lg bg-gray-100">
                                <img :src="p.url" class="h-full w-full object-cover" loading="lazy">
                                <span
                                    class="absolute bottom-1 left-1 rounded px-1.5 py-0.5 text-[10px] text-white"
                                    :class="p.status === 'failed' ? 'bg-red-700' : 'bg-black/60'"
                                >{{ p.status === 'failed' ? 'rifiutata' : 'da inviare' }}</span>
                            </div>
                            <div v-if="p.status === 'failed'" class="mt-1 flex gap-1">
                                <button class="flex-1 rounded border border-green-700 py-0.5 text-[10px] font-medium text-green-700" @click="retryPhoto(p.photo_id)">Riprova</button>
                                <button class="flex-1 rounded border border-red-300 py-0.5 text-[10px] font-medium text-red-700" @click="discardPhoto(p.photo_id)">Scarta</button>
                            </div>
                        </div>
                    </div>
                    <p v-else class="mt-2 text-xs text-gray-400">
                        Le foto scattate qui restano sul dispositivo finché non c'è rete, poi partono da sole.
                    </p>
                </div>

                <!-- Ispezione sull'elemento -->
                <div v-if="assetTemplates.length" class="rounded-xl border border-gray-200 bg-white p-4">
                    <h2 class="text-sm font-semibold">Ispezione</h2>
                    <div class="mt-2 grid grid-cols-3 gap-2">
                        <select v-model="inspectionPick.assetTemplateId" data-test="asset-inspection-template" class="col-span-2 rounded-lg border border-gray-300 px-2 py-2 text-sm">
                            <option value="" disabled>Scegli il modello…</option>
                            <option v-for="t in assetTemplates" :key="t.id" :value="t.id">{{ t.name }}</option>
                        </select>
                        <button
                            class="rounded-lg border border-green-700 px-3 py-2 text-sm font-medium text-green-700 disabled:opacity-50"
                            :disabled="! inspectionPick.assetTemplateId"
                            data-test="asset-inspection-start"
                            @click="startInspection(assetTemplates.find((t) => t.id === inspectionPick.assetTemplateId), { assetId: selected.asset.id, assetLabel: selected.asset.census_code || selected.asset.id.slice(0, 8) })"
                        >Avvia</button>
                    </div>
                </div>

                <!-- Segnalazione sull'elemento -->
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-semibold">Segnalazione</h2>
                        <button
                            v-if="! assetIssue.open"
                            class="rounded-lg border border-green-700 px-3 py-1.5 text-xs font-medium text-green-700"
                            data-test="asset-issue-open"
                            @click="assetIssue.open = true"
                        >Segnala un problema</button>
                    </div>
                    <div v-if="assetIssue.open" class="mt-3 space-y-3">
                        <label class="block text-xs">
                            <span class="text-gray-500">Cosa hai notato *</span>
                            <textarea v-model="assetIssue.description" rows="2" data-test="asset-issue-description" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm" placeholder="es. Ramo spezzato pericolante sul lato strada" />
                        </label>
                        <label class="block text-xs">
                            <span class="text-gray-500">Gravità</span>
                            <select v-model="assetIssue.severity" data-test="asset-issue-severity" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-2 text-sm">
                                <option v-for="(label, value) in ISSUE_SEVERITY" :key="value" :value="value">{{ label }}</option>
                            </select>
                        </label>
                        <div class="grid grid-cols-2 gap-2">
                            <button
                                class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700"
                                :disabled="busy"
                                @click="resetAssetIssue"
                            >Annulla</button>
                            <button
                                class="rounded-lg bg-green-700 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50"
                                :disabled="busy"
                                data-test="asset-issue-save"
                                @click="submitAssetIssue"
                            >Invia la segnalazione</button>
                        </div>
                    </div>
                    <p v-else class="mt-2 text-xs text-gray-400">
                        Un problema su questo elemento arriva in ufficio alla sincronizzazione.
                    </p>
                </div>

                <!-- Note -->
                <div v-if="selected.asset.notes" class="rounded-xl border border-gray-200 bg-white p-4">
                    <h2 class="text-sm font-semibold">Note</h2>
                    <p class="mt-1 whitespace-pre-line text-sm text-gray-700">{{ selected.asset.notes }}</p>
                </div>
            </div>
        </div>

        <!-- Scheda ordine di lavoro (stesso schema della scheda elemento) -->
        <div v-if="selectedOrder" class="fixed inset-x-0 bottom-14 top-0 z-20 flex flex-col bg-gray-100" data-test="wo-detail">
            <header class="border-b border-gray-200 bg-white px-4 py-3">
                <button class="text-sm font-medium text-green-700" @click="selectedOrder = null; consuntivo.open = false">← Indietro</button>
                <div class="mt-1 flex items-center justify-between">
                    <div>
                        <div class="text-base font-semibold">{{ selectedOrder.code }}</div>
                        <div class="text-xs text-gray-500">{{ selectedOrder.title }}</div>
                    </div>
                    <span
                        class="rounded-full px-2 py-0.5 text-xs font-medium"
                        :class="WO_STATUS[selectedOrder.status]?.cls"
                        data-test="wo-detail-status"
                    >{{ WO_STATUS[selectedOrder.status]?.label ?? selectedOrder.status }}</span>
                </div>
            </header>

            <div class="flex-1 space-y-3 overflow-y-auto p-4">
                <p
                    v-if="message.text"
                    class="rounded-lg px-3 py-2 text-sm"
                    :class="message.ok ? 'bg-green-100 text-green-900' : 'bg-red-100 text-red-900'"
                    data-test="wo-message"
                >{{ message.text }}</p>

                <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm">
                    <dl class="space-y-1.5">
                        <div class="flex justify-between gap-3">
                            <dt class="text-gray-500">Lavorazione</dt>
                            <dd class="text-right font-medium">{{ selectedOrder.work_type?.name ?? '—' }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-gray-500">Area</dt>
                            <dd class="text-right font-medium">{{ selectedOrder.area?.name ?? '—' }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-gray-500">Squadra</dt>
                            <dd class="text-right font-medium">{{ selectedOrder.team?.name ?? '—' }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-gray-500">Priorità</dt>
                            <dd class="text-right font-medium">{{ WO_PRIORITY[selectedOrder.priority] ?? '—' }}</dd>
                        </div>
                        <div v-if="selectedOrder.planned_start" class="flex justify-between gap-3">
                            <dt class="text-gray-500">Periodo previsto</dt>
                            <dd class="text-right font-medium">
                                {{ fmtDate(selectedOrder.planned_start) }}<template v-if="selectedOrder.planned_end"> → {{ fmtDate(selectedOrder.planned_end) }}</template>
                            </dd>
                        </div>
                    </dl>
                    <p v-if="selectedOrder.description" class="mt-3 whitespace-pre-line border-t border-gray-100 pt-3 text-sm text-gray-700">
                        {{ selectedOrder.description }}
                    </p>
                    <p v-if="selectedOrder.risks" class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900">
                        Rischi segnalati: {{ selectedOrder.risks }}
                    </p>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <h2 class="text-sm font-semibold">Elementi da lavorare ({{ (selectedOrder.assets ?? []).length }})</h2>
                    <ul v-if="(selectedOrder.assets ?? []).length" class="mt-2 divide-y divide-gray-50">
                        <li v-for="row in selectedOrder.assets" :key="row.id" class="flex items-center justify-between py-1.5 text-sm">
                            <span class="font-medium">{{ row.asset?.census_code || (row.asset_id ?? '').slice(0, 8) }}</span>
                            <span class="text-xs text-gray-500">
                                {{ row.work_type?.name ?? '' }}
                                <template v-if="row.planned_quantity"> · {{ Number(row.planned_quantity) }} {{ row.unit ?? '' }}</template>
                            </span>
                        </li>
                    </ul>
                    <p v-else class="mt-2 text-sm text-gray-400">Nessun elemento specifico: l'ordine copre l'area indicata.</p>
                </div>

                <!-- Azioni di campo -->
                <div v-if="! consuntivo.open" class="space-y-2">
                    <button
                        v-for="action in WO_ACTIONS[selectedOrder.status] ?? []"
                        :key="action.to"
                        class="w-full rounded-lg bg-green-700 px-4 py-3 text-sm font-semibold text-white disabled:opacity-50"
                        :disabled="busy"
                        :data-test="`wo-action-${action.to}`"
                        @click="orderTransition(selectedOrder, action.to)"
                    >{{ action.label }}</button>
                    <button
                        v-if="selectedOrder.status === 'in_progress'"
                        class="w-full rounded-lg border border-green-700 px-4 py-3 text-sm font-semibold text-green-700 disabled:opacity-50"
                        :disabled="busy"
                        data-test="wo-open-consuntivo"
                        @click="openConsuntivo(selectedOrder)"
                    >Completa con consuntivo</button>
                    <template v-if="selectedOrder.status === 'in_progress'">
                        <select
                            v-if="(selectedOrder.assets ?? []).length > 1"
                            v-model="workPhotoAssetId"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
                            data-test="wo-photo-asset"
                        >
                            <option value="">Elemento della foto…</option>
                            <option v-for="row in selectedOrder.assets" :key="row.id" :value="row.asset_id">
                                {{ row.asset?.census_code || (row.asset_id ?? '').slice(0, 8) }}
                            </option>
                        </select>
                        <label class="block w-full rounded-lg border border-green-700 px-4 py-3 text-center text-sm font-semibold text-green-700">
                            Foto del lavoro
                            <input type="file" accept="image/*" capture="environment" class="hidden" :disabled="busy" data-test="wo-photo-input" @change="takeWorkPhoto">
                        </label>
                    </template>

                    <p v-if="selectedOrder.status === 'planned'" class="rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-500">
                        Lavoro pianificato: potrà essere avviato quando il responsabile lo assegna.
                    </p>
                    <p v-if="selectedOrder.status === 'completed'" class="rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-500">
                        Lavoro completato: uscirà dall'elenco alla prossima sincronizzazione.
                    </p>
                </div>

                <!-- Consuntivo di chiusura -->
                <div v-else class="rounded-xl border border-gray-200 bg-white p-4" data-test="wo-consuntivo">
                    <h2 class="text-sm font-semibold">Consuntivo del lavoro</h2>
                    <div class="mt-2 grid grid-cols-2 gap-3">
                        <label class="block text-xs">
                            <span class="text-gray-500">Ore uomo</span>
                            <input v-model.number="consuntivo.manHours" type="number" step="0.5" min="0" max="9999.99" data-test="wo-man-hours" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm">
                        </label>
                        <label class="block text-xs">
                            <span class="text-gray-500">Quantità eseguita</span>
                            <input v-model.number="consuntivo.quantity" type="number" step="0.01" min="0" max="99999999" data-test="wo-quantity" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm">
                        </label>
                        <label class="block text-xs">
                            <span class="text-gray-500">Unità di misura</span>
                            <input v-model="consuntivo.unit" maxlength="20" data-test="wo-unit" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm" placeholder="es. mq, cad">
                        </label>
                    </div>
                    <label class="mt-3 block text-xs">
                        <span class="text-gray-500">Note di lavoro</span>
                        <textarea v-model="consuntivo.notes" rows="2" data-test="wo-notes" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm" />
                    </label>
                    <div class="mt-3 grid grid-cols-2 gap-2">
                        <button
                            class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700"
                            :disabled="busy"
                            @click="consuntivo.open = false"
                        >Annulla</button>
                        <button
                            class="rounded-lg bg-green-700 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50"
                            :disabled="busy"
                            data-test="wo-consuntivo-save"
                            @click="completeWithConsuntivo(selectedOrder)"
                        >Completa il lavoro</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Checklist di ispezione a schermo intero (funziona offline) -->
        <div v-if="inspectionRun.open" class="fixed inset-x-0 bottom-14 top-0 z-40 flex flex-col bg-gray-100" data-test="inspection-run">
            <header class="border-b border-gray-200 bg-white px-4 py-3">
                <button class="text-sm font-medium text-green-700" @click="closeInspection">← Annulla</button>
                <div class="mt-1">
                    <div class="text-base font-semibold">{{ inspectionRun.template.name }}</div>
                    <div class="text-xs text-gray-500">
                        {{ inspectionRun.assetId ? `Elemento ${inspectionRun.assetLabel}` : (areas.find((a) => a.id === inspectionRun.areaId)?.name ?? 'Area') }}
                        <template v-if="inspectionRun.template.standard_ref"> · {{ inspectionRun.template.standard_ref }}</template>
                    </div>
                </div>
            </header>

            <div class="flex-1 space-y-3 overflow-y-auto p-4">
                <div v-for="(answer, index) in inspectionAnswers" :key="answer.item_id" class="rounded-xl border border-gray-200 bg-white p-3" data-test="field-check-item">
                    <p class="text-sm font-medium">{{ index + 1 }}. {{ answer.question }}</p>
                    <div v-if="['ok_ko', 'ok_ko_na'].includes(answer.answer_type)" class="mt-2 grid gap-2" :class="answer.answer_type === 'ok_ko' ? 'grid-cols-2' : 'grid-cols-3'">
                        <button
                            v-for="option in (answer.answer_type === 'ok_ko' ? ['ok', 'ko'] : ['ok', 'ko', 'na'])"
                            :key="option"
                            class="rounded-lg border py-2.5 text-sm font-semibold"
                            :class="answer.value === option
                                ? (option === 'ko' ? 'border-red-700 bg-red-700 text-white' : option === 'na' ? 'border-gray-500 bg-gray-500 text-white' : 'border-green-700 bg-green-700 text-white')
                                : 'border-gray-300 text-gray-600'"
                            :data-test="`field-check-${option}-${index}`"
                            @click="answer.value = option"
                        >{{ option === 'ok' ? 'OK' : option === 'ko' ? 'KO' : 'N.A.' }}</button>
                    </div>
                    <input
                        v-else
                        v-model="answer.value"
                        :type="answer.answer_type === 'number' ? 'number' : 'text'"
                        :inputmode="answer.answer_type === 'number' ? 'decimal' : 'text'"
                        class="mt-2 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm"
                        placeholder="Risposta"
                    >
                    <input
                        v-model="answer.note"
                        class="mt-2 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-xs"
                        placeholder="Nota (solo se serve spiegare)"
                        :data-test="`field-check-note-${index}`"
                    >
                </div>
            </div>

            <div class="border-t border-gray-200 bg-white p-4">
                <button
                    class="w-full rounded-lg bg-green-700 px-4 py-3 text-sm font-semibold text-white disabled:opacity-50"
                    :disabled="busy || ! inspectionComplete"
                    data-test="field-check-save"
                    @click="submitInspection"
                >Registra l'ispezione</button>
            </div>
        </div>

        <!-- Barra di navigazione inferiore (uso a una mano in campo) -->
        <nav
            class="fixed inset-x-0 bottom-0 z-30 grid h-14 border-t border-gray-200 bg-white"
            :class="canWorks ? 'grid-cols-6' : 'grid-cols-5'"
        >
            <button
                v-for="item in [
                    { key: 'rilievo', label: 'Rilievo' },
                    { key: 'mappa', label: 'Mappa' },
                    ...(canWorks ? [{ key: 'lavori', label: 'Lavori' }] : []),
                    { key: 'elementi', label: 'Elementi' },
                    { key: 'scansiona', label: 'Scansiona' },
                    { key: 'sync', label: 'Sync' },
                ]"
                :key="item.key"
                class="py-3.5 text-xs font-medium sm:text-sm"
                :class="tab === item.key ? 'border-t-2 border-green-700 text-green-800' : 'text-gray-500'"
                :data-test="`tab-${item.key}`"
                @click="switchTab(item.key)"
            >{{ item.label }}</button>
        </nav>
    </div>
</template>
