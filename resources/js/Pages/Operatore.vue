<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { openFieldDb } from '@/field/db';
import { SyncManager } from '@/field/sync';

const page = usePage();
const user = page.props.auth.user;
const canAssociate = computed(() => (user.permissions ?? []).includes('assets.update'));

const db = openFieldDb(user.tenant_id, user.id);
const sync = new SyncManager(db);

const tab = ref('rilievo');
const state = reactive({ pending: 0, attention: 0, syncing: false, online: navigator.onLine });
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

async function openAsset(asset) {
    const tree = await db.trees.get(asset.id);
    const tags = await db.asset_tags.where('asset_id').equals(asset.id).toArray();
    selected.value = { asset, tree: tree ?? null, tags };
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
        scan.cameraError = 'Fotocamera non disponibile: inserisci il codice manualmente.';
        scan.cameraActive = false;
    }
}

function switchTab(key) {
    stopCamera();
    selected.value = null;
    tab.value = key;
    refreshLocal();
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
    bootstrapped.value = Boolean(await db.meta.get('bootstrapped_at'));
    areas.value = await db.areas.orderBy('name').toArray();
    const types = await db.catalog_types.toArray();
    typesById.value = Object.fromEntries(types.map((t) => [t.id, t]));
    pointTypes.value = types
        .filter((t) => t.allowed_geometry === 'P')
        .sort((a, b) => a.code.localeCompare(b.code));
    localAssets.value = (await db.assets.orderBy('updated_at').reverse().limit(200).toArray());
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

async function discardCommand(cmd) {
    const ok = window.confirm(
        `Scartare definitivamente l'operazione #${cmd.device_seq} (${cmd.type})? `
        + 'La modifica locale andrà persa e varrà la versione del server.',
    );
    if (! ok) return;
    await sync.discard(cmd.idempotency_key);
    await refreshLocal();
    setMessage('Operazione scartata: al prossimo aggiornamento varrà la versione del server.');
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
    if (! state.online) return { text: `Offline — ${state.pending} da inviare`, cls: 'bg-gray-700 text-white' };
    if (state.syncing) return { text: 'Sincronizzazione…', cls: 'bg-blue-700 text-white' };
    if (state.attention > 0) return { text: `${state.attention} da rivedere`, cls: 'bg-red-700 text-white' };
    if (state.pending > 0) return { text: `${state.pending} da inviare`, cls: 'bg-amber-600 text-white' };
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
    stopCamera();
    unsubscribe();
});
</script>

<template>
    <Head title="Operatore" />

    <div class="flex min-h-screen flex-col bg-gray-100">
        <!-- Intestazione con indicatore di sincronizzazione sempre visibile -->
        <header class="sticky top-0 z-10 border-b border-gray-200 bg-white">
            <div class="flex items-center justify-between px-4 py-3">
                <div>
                    <div class="text-sm font-semibold leading-tight">WebGIS Operatore</div>
                    <div class="text-xs text-gray-500">{{ user.name }}</div>
                </div>
                <span class="rounded-full px-3 py-1 text-xs font-medium" :class="badge.cls" data-test="sync-badge">
                    {{ badge.text }}
                </span>
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

            <!-- SCANSIONA -->
            <section v-if="tab === 'scansiona'">
                <h1 class="text-base font-semibold">Scansiona tag</h1>
                <div class="mt-3 space-y-3 rounded-xl border border-gray-200 bg-white p-4">
                    <div v-if="scan.cameraActive" class="overflow-hidden rounded-lg bg-black">
                        <video ref="videoEl" class="h-56 w-full object-cover" muted playsinline />
                        <button class="w-full bg-gray-800 py-2 text-sm font-medium text-white" @click="stopCamera">
                            Ferma la fotocamera
                        </button>
                    </div>
                    <button
                        v-else-if="scan.cameraSupported"
                        class="w-full rounded-lg border border-green-700 px-3 py-2.5 text-sm font-medium text-green-700"
                        @click="startCamera"
                    >Scansiona con la fotocamera</button>
                    <p v-if="scan.cameraError" class="text-xs text-amber-700">{{ scan.cameraError }}</p>

                    <div class="grid grid-cols-3 gap-2">
                        <label class="block text-xs">
                            <span class="text-gray-500">Tipo tag</span>
                            <select v-model="scan.tagType" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-2 text-sm">
                                <option v-for="(label, value) in TAG_TYPES" :key="value" :value="value">{{ label }}</option>
                            </select>
                        </label>
                        <label class="col-span-2 block text-xs">
                            <span class="text-gray-500">Codice del tag</span>
                            <input v-model="scan.uid" data-test="scan-uid" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm" placeholder="es. QR-000123">
                        </label>
                    </div>
                    <button
                        class="w-full rounded-lg bg-green-700 px-4 py-2.5 text-sm font-semibold text-white"
                        data-test="scan-search"
                        @click="doScan"
                    >Cerca elemento</button>

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
                            <span class="text-gray-500">Da rivedere (conflitti/rifiuti)</span>
                            <span class="font-medium" :class="state.attention ? 'text-red-700' : ''">{{ state.attention }}</span>
                        </div>
                        <div class="mt-3 grid grid-cols-2 gap-2">
                            <button
                                class="rounded-lg border border-green-700 px-3 py-2.5 text-sm font-medium text-green-700 disabled:opacity-50"
                                :disabled="busy || ! state.online"
                                data-test="download-ws"
                                @click="downloadWorkingSet"
                            >Scarica dati di lavoro</button>
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

                <!-- Note -->
                <div v-if="selected.asset.notes" class="rounded-xl border border-gray-200 bg-white p-4">
                    <h2 class="text-sm font-semibold">Note</h2>
                    <p class="mt-1 whitespace-pre-line text-sm text-gray-700">{{ selected.asset.notes }}</p>
                </div>
            </div>
        </div>

        <!-- Barra di navigazione inferiore (uso a una mano in campo) -->
        <nav class="fixed inset-x-0 bottom-0 z-30 grid h-14 grid-cols-4 border-t border-gray-200 bg-white">
            <button
                v-for="item in [
                    { key: 'rilievo', label: 'Rilievo' },
                    { key: 'elementi', label: 'Elementi' },
                    { key: 'scansiona', label: 'Scansiona' },
                    { key: 'sync', label: 'Sync' },
                ]"
                :key="item.key"
                class="py-3.5 text-sm font-medium"
                :class="tab === item.key ? 'border-t-2 border-green-700 text-green-800' : 'text-gray-500'"
                :data-test="`tab-${item.key}`"
                @click="switchTab(item.key)"
            >{{ item.label }}</button>
        </nav>
    </div>
</template>
