<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import * as maplibregl from 'maplibre-gl';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import AssetEditPanel from '@/Components/AssetEditPanel.vue';
import GestionalePanel from '@/Components/GestionalePanel.vue';
import PlantingSitePanel from '@/Components/PlantingSitePanel.vue';
import TreeVtaPanel from '@/Components/TreeVtaPanel.vue';
import AvvisoErrore from '@/Components/AvvisoErrore.vue';
import { usaCaricamento } from '@/caricamento';
import { avvisoCaricamento } from '@/avvisi';
import { fetchPdf } from '@/pdf';
import { statusLabel } from '@/assetStatus';

const props = defineProps({ assetId: { type: String, required: true } });

const page = usePage();
const permissions = computed(() => page.props.auth?.user?.permissions ?? []);
const canUpdate = computed(() => permissions.value.includes('assets.update'));
const canDelete = computed(() => permissions.value.includes('assets.delete'));
// Arrivo dallo scadenzario VTA con "Valuta": il modulo si apre da solo
const apriValutazione = new URLSearchParams(window.location.search).get('vta') === '1';

const asset = ref(null);

// Street View di Google sul punto dell'elemento: per linee e superfici si
// prende il primo vertice disegnato. È un semplice collegamento esterno,
// senza chiavi né richieste: si apre solo se l'utente lo clicca
const streetView = computed(() => {
    const g = asset.value?.geom_geojson;
    const p = ! g ? null
        : g.type === 'Point' ? g.coordinates
        : g.type === 'MultiPoint' ? g.coordinates[0]
        : g.type === 'LineString' ? g.coordinates[0]
        : g.type === 'Polygon' ? g.coordinates[0]?.[0]
        : g.type === 'MultiLineString' ? g.coordinates[0]?.[0]
        : g.type === 'MultiPolygon' ? g.coordinates[0]?.[0]?.[0]
        : null;

    return Array.isArray(p) && p.length >= 2
        ? `https://www.google.com/maps/@?api=1&map_action=pano&viewpoint=${p[1]},${p[0]}`
        : null;
});

// Senza la scheda la pagina resterebbe bianca: se il caricamento fallisce si
// dice perché e si offre di riprovare
const { avviso, riprovaInCorso, carica, riprova } = usaCaricamento();
const editing = ref(false);
const pdfBusy = ref(false);
const pdfError = ref('');

// Stampa componibile: prima di stampare si scelgono le sezioni della scheda
const stampaAperta = ref(false);
const SEZIONI_SCHEDA = {
    dendro: 'Dati dendrometrici e agronomici',
    vta: 'Ultima valutazione di stabilità',
    attributi: 'Attributi del tipo',
    foto: 'Documentazione fotografica',
};
const stampaSezioni = reactive(Object.fromEntries(Object.keys(SEZIONI_SCHEDA).map((k) => [k, true])));

async function openPdf() {
    pdfBusy.value = true;
    pdfError.value = '';
    const scelte = Object.keys(stampaSezioni).filter((k) => stampaSezioni[k]);
    const query = scelte.length === Object.keys(stampaSezioni).length
        ? '' : `?sezioni=${scelte.join(',')}`;
    const { error } = await fetchPdf(`/api/v1/assets/${props.assetId}/pdf${query}`);
    if (error) pdfError.value = error;
    else stampaAperta.value = false;
    pdfBusy.value = false;
}

// Pagina pubblica (QR): attivazione per elemento, revocabile
const publicBusy = ref(false);
const origin = window.location.origin;

async function togglePublicPage() {
    publicBusy.value = true;
    pdfError.value = '';
    try {
        if (asset.value.public_token) {
            if (! window.confirm('Disattivare la pagina pubblica? Il QR già stampato smetterà di funzionare.')) return;
            await axios.delete(`/api/v1/assets/${props.assetId}/public-page`);
        } else {
            await axios.post(`/api/v1/assets/${props.assetId}/public-page`);
        }
        // Ricarica completa: il cambio incrementa la versione della scheda
        // e un salvataggio con la versione vecchia verrebbe rifiutato
        await load();
    } catch (err) {
        pdfError.value = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
            ?? err.response?.data?.message ?? 'Operazione non riuscita';
    } finally {
        publicBusy.value = false;
    }
}

// Esclusione dal portale pubblico del committente: il portale pubblica
// tutto il patrimonio, questa è l'eccezione decisa caso per caso
async function toggleNascondi() {
    publicBusy.value = true;
    pdfError.value = '';
    try {
        await axios.patch(`/api/v1/assets/${props.assetId}`, {
            public_hidden: ! asset.value.public_hidden,
            version: asset.value.version,
        });
        await load();
    } catch (err) {
        pdfError.value = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
            ?? err.response?.data?.message ?? 'Operazione non riuscita';
    } finally {
        publicBusy.value = false;
    }
}

// Vincoli che gravano sull'elemento: quelli geografici arrivano dal
// perimetro, gli altri si collegano a mano
const vincoli = ref([]);
const vincoliDisponibili = ref([]);
const vincoloDaCollegare = ref('');

// --- Storia delle modifiche -------------------------------------------------
// Si carica solo quando la si apre: e' una consultazione, non un dato di scheda
const storia = ref(null);
const storiaAperta = ref(false);
const storiaInCaricamento = ref(false);
const storiaErrore = ref('');

async function apriStoria() {
    storiaAperta.value = ! storiaAperta.value;
    if (! storiaAperta.value || storia.value !== null) return;
    storiaInCaricamento.value = true;
    storiaErrore.value = '';
    try {
        const { data } = await axios.get(`/api/v1/assets/${props.assetId}/versioni`);
        storia.value = data.data;
    } catch (err) {
        storiaErrore.value = avvisoCaricamento(err);
    } finally {
        storiaInCaricamento.value = false;
    }
}

async function caricaVincoli() {
    try {
        const [collegati, elenco] = await Promise.all([
            axios.get(`/api/v1/assets/${props.assetId}/constraints`),
            axios.get('/api/v1/constraints', { params: { per_page: 100 } }),
        ]);
        vincoli.value = collegati.data.data;
        vincoliDisponibili.value = elenco.data.data;
    } catch {
        vincoli.value = [];
        vincoliDisponibili.value = [];
    }
}

async function collegaVincolo() {
    if (! vincoloDaCollegare.value) return;
    try {
        const { data } = await axios.post(`/api/v1/assets/${props.assetId}/constraints`, {
            constraint_id: vincoloDaCollegare.value,
        });
        vincoli.value = data.data;
        vincoloDaCollegare.value = '';
    } catch (err) {
        pdfError.value = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
            ?? err.response?.data?.message ?? 'Collegamento non riuscito';
    }
}

async function scollegaVincolo(id) {
    try {
        const { data } = await axios.delete(`/api/v1/assets/${props.assetId}/constraints/${id}`);
        vincoli.value = data.data;
    } catch (err) {
        pdfError.value = err.response?.data?.message ?? 'Operazione non riuscita';
    }
}

async function openTag() {
    pdfBusy.value = true;
    pdfError.value = '';
    const { error } = await fetchPdf(`/api/v1/assets/${props.assetId}/public-tag`);
    if (error) pdfError.value = error;
    pdfBusy.value = false;
}

async function onSaved() {
    editing.value = false;
    await load();
}

// Abbattimento/rimozione: una sola registrazione tiene allineati stato della
// scheda, data di fine validità, scheda albero e pagina pubblica col QR
const oggi = () => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};
const removal = reactive({ open: false, date: oggi(), reason: '', busy: false, error: '' });
const deletion = reactive({ busy: false, error: '' });

async function saveRemoval() {
    removal.busy = true;
    removal.error = '';
    try {
        await axios.post(`/api/v1/assets/${props.assetId}/removal`, {
            removed_on: removal.date,
            removal_reason: removal.reason || null,
            version: asset.value.version,
        });
        removal.open = false;
        removal.reason = '';
        await load();
    } catch (err) {
        removal.error = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
            ?? err.response?.data?.message ?? 'Registrazione non riuscita';
    } finally {
        removal.busy = false;
    }
}

async function cancelRemoval() {
    if (! window.confirm('Annullare la registrazione dell\'abbattimento? La scheda torna attiva.')) return;
    removal.busy = true;
    removal.error = '';
    try {
        await axios.delete(`/api/v1/assets/${props.assetId}/removal`);
        await load();
    } catch (err) {
        removal.error = err.response?.data?.message ?? 'Operazione non riuscita';
    } finally {
        removal.busy = false;
    }
}

async function deleteAsset() {
    const nome = asset.value.census_code || asset.value.object_type?.name || 'questa scheda';
    if (! window.confirm(`Eliminare definitivamente ${nome} dal censimento? Da usare solo per un rilievo sbagliato: se la pianta è stata abbattuta registra invece l'abbattimento.`)) return;
    deletion.busy = true;
    deletion.error = '';
    try {
        await axios.delete(`/api/v1/assets/${props.assetId}`);
        router.visit('/censimento');
    } catch (err) {
        deletion.error = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
            ?? err.response?.data?.message ?? 'Eliminazione non riuscita';
        deletion.busy = false;
    }
}

// Le date arrivano dall'API in formato ISO con l'ora: qui serve solo il giorno
const removedOn = computed(() => {
    const iso = String(asset.value?.valid_to ?? '').slice(0, 10);
    if (! iso) return null;
    const [y, m, d] = iso.split('-');

    return `${d}/${m}/${y}`;
});
const uploading = ref(false);
const uploadError = ref('');
const mapEl = ref(null);
let map = null;

async function load() {
    const { data } = await axios.get(`/api/v1/assets/${props.assetId}`);
    asset.value = data.data;
    // La scheda e' cambiata: la storia gia' letta non e' piu' aggiornata
    storia.value = null;
    storiaAperta.value = false;
    await caricaVincoli();
}

function initMap() {
    if (!asset.value?.geom_geojson || map) return;

    map = new maplibregl.Map({
        container: mapEl.value,
        zoom: 17,
        interactive: false,
        attributionControl: false,
        style: {
            version: 8,
            sources: {
                osm: {
                    type: 'raster',
                    tiles: ['https://tile.openstreetmap.org/{z}/{x}/{y}.png'],
                    tileSize: 256,
                    // Oltre questo livello lo sfondo non ha immagini
                    maxzoom: 19,
                },
            },
            layers: [
                { id: 'sfondo', type: 'background', paint: { 'background-color': '#e8ede9' } },
                { id: 'osm', type: 'raster', source: 'osm' },
            ],
        },
    });

    map.on('load', () => {
        map.addSource('geom', { type: 'geojson', data: asset.value.geom_geojson });
        map.addLayer({
            id: 'geom-fill', type: 'fill', source: 'geom',
            filter: ['==', ['geometry-type'], 'Polygon'],
            paint: { 'fill-color': '#16a34a', 'fill-opacity': 0.35 },
        });
        map.addLayer({
            id: 'geom-line', type: 'line', source: 'geom',
            paint: { 'line-color': '#15803d', 'line-width': 2.5 },
        });
        // La chioma a dimensione reale, se il diametro e' censito: stesso
        // calcolo della Mappa (un metro = 2^zoom / (78271,517 x cos(lat)) pixel)
        const diametro = Number(asset.value.tree?.crown_diameter_m) || 0;
        if (diametro > 0 && asset.value.geom_geojson.type === 'Point') {
            const lat = asset.value.geom_geojson.coordinates[1];
            const cosLat = Math.cos((lat * Math.PI) / 180);
            const pixelPerMetro = (zoom) => (2 ** zoom) / (78271.517 * cosLat);
            map.addLayer({
                id: 'chioma', type: 'circle', source: 'geom',
                paint: {
                    'circle-color': '#16a34a',
                    'circle-opacity': 0.16,
                    'circle-stroke-color': '#15803d',
                    'circle-stroke-opacity': 0.4,
                    'circle-stroke-width': 1,
                    'circle-radius': ['interpolate', ['exponential', 2], ['zoom'],
                        14, (diametro / 2) * pixelPerMetro(14),
                        20, (diametro / 2) * pixelPerMetro(20),
                    ],
                },
            });
        }
        map.addLayer({
            id: 'geom-point', type: 'circle', source: 'geom',
            filter: ['==', ['geometry-type'], 'Point'],
            paint: { 'circle-color': '#16a34a', 'circle-radius': 8, 'circle-stroke-width': 2, 'circle-stroke-color': '#fff' },
        });

        const bounds = new maplibregl.LngLatBounds();
        const extend = (coords) => {
            if (typeof coords[0] === 'number') bounds.extend(coords);
            else coords.forEach(extend);
        };
        extend(asset.value.geom_geojson.coordinates);
        map.fitBounds(bounds, { padding: 40, maxZoom: 18 });
    });
}

async function uploadPhoto(event) {
    const file = event.target.files?.[0];
    if (!file) return;

    uploading.value = true;
    uploadError.value = '';
    const form = new FormData();
    form.append('photo', file);
    form.append('category', 'census');

    try {
        await axios.post(`/api/v1/assets/${props.assetId}/photos`, form);
        await load();
    } catch (err) {
        uploadError.value = err.response?.data?.message ?? 'Errore nel caricamento';
    } finally {
        uploading.value = false;
        event.target.value = '';
    }
}

const measure = computed(() => {
    if (!asset.value) return null;
    if (asset.value.computed_area_sqm) {
        return `${Number(asset.value.computed_area_sqm).toLocaleString('it-IT')} m² (perimetro ${Number(asset.value.computed_perimeter_m).toLocaleString('it-IT')} m)`;
    }
    if (asset.value.computed_length_m) return `${Number(asset.value.computed_length_m).toLocaleString('it-IT')} m`;
    return null;
});

const attributeRows = computed(() => Object.entries(asset.value?.attributes ?? {}));

onMounted(async () => {
    if (await carica(load)) initMap();
});

onBeforeUnmount(() => map?.remove());
</script>

<template>
    <Head :title="asset?.census_code || 'Scheda elemento'" />

    <AppLayout>
        <div class="p-6">
            <Link href="/censimento" class="text-sm text-green-700 hover:underline">← Torna al censimento</Link>

            <AvvisoErrore class="mt-3" :messaggio="avviso" :in-corso="riprovaInCorso" @riprova="riprova" />

            <div v-if="asset" class="mt-3 grid grid-cols-1 gap-6 xl:grid-cols-3">
                <!-- Scheda -->
                <div class="xl:col-span-2">
                    <div class="rounded-xl border border-gray-200 bg-white p-6">
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="text-xs uppercase tracking-wide text-gray-400">
                                    {{ asset.object_type?.code }}
                                </div>
                                <h1 class="text-xl font-semibold">
                                    {{ asset.census_code || asset.object_type?.name }}
                                </h1>
                                <p class="text-sm text-gray-500">{{ asset.object_type?.name }}</p>
                            </div>
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                <a
                                    v-if="streetView"
                                    :href="streetView"
                                    target="_blank"
                                    rel="noopener"
                                    data-test="street-view"
                                    title="Apre Google Street View sul punto dell'elemento, in un'altra scheda"
                                    class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50"
                                >Street View</a>
                                <button
                                    class="rounded-lg border border-green-700 px-3 py-1.5 text-xs font-medium text-green-700 hover:bg-green-50 disabled:opacity-50"
                                    :disabled="pdfBusy"
                                    data-test="asset-pdf"
                                    @click="stampaAperta = ! stampaAperta"
                                >Scheda PDF</button>
                                <button
                                    v-if="asset.public_token"
                                    class="rounded-lg border border-green-700 px-3 py-1.5 text-xs font-medium text-green-700 hover:bg-green-50 disabled:opacity-50"
                                    :disabled="pdfBusy"
                                    data-test="asset-tag"
                                    @click="openTag"
                                >Cartellino QR</button>
                                <button
                                    v-if="canUpdate"
                                    class="rounded-lg border px-3 py-1.5 text-xs font-medium disabled:opacity-50"
                                    :class="asset.public_token ? 'border-gray-300 text-gray-600 hover:bg-gray-50' : 'border-green-700 text-green-700 hover:bg-green-50'"
                                    :disabled="publicBusy"
                                    data-test="asset-public-toggle"
                                    @click="togglePublicPage"
                                >{{ asset.public_token ? 'Disattiva pagina pubblica' : 'Attiva pagina pubblica' }}</button>
                                <button
                                    v-if="canUpdate && ! editing"
                                    class="rounded-lg border border-green-700 px-3 py-1.5 text-xs font-medium text-green-700 hover:bg-green-50"
                                    @click="editing = true"
                                >Modifica</button>
                                <span
                                    class="rounded-full px-3 py-1 text-xs font-medium"
                                    data-test="asset-stato"
                                    :class="{
                                        'bg-green-100 text-green-800': asset.status === 'active',
                                        'bg-amber-100 text-amber-900': asset.status === 'removed',
                                        'bg-gray-100 text-gray-600': ! ['active', 'removed'].includes(asset.status),
                                    }"
                                >
                                    {{ statusLabel(asset.status) }}
                                </span>
                            </div>
                        </div>

                        <div v-if="stampaAperta" class="mb-3 rounded-lg border border-gray-200 bg-gray-50 p-3" data-test="stampa-sezioni">
                            <p class="text-xs font-medium text-gray-600">Sezioni della scheda PDF (testata e dati generali escono sempre)</p>
                            <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1.5 text-sm">
                                <label v-for="(etichetta, chiave) in SEZIONI_SCHEDA" :key="chiave" class="flex items-center gap-1.5">
                                    <input v-model="stampaSezioni[chiave]" type="checkbox" class="rounded border-gray-300" :data-test="`sezione-${chiave}`"> {{ etichetta }}
                                </label>
                            </div>
                            <button
                                class="mt-2 rounded-lg bg-green-700 px-3 py-1.5 text-xs font-medium text-white hover:bg-green-800 disabled:opacity-50"
                                :disabled="pdfBusy"
                                data-test="stampa-avvia"
                                @click="openPdf"
                            >{{ pdfBusy ? 'Stampa…' : 'Stampa' }}</button>
                        </div>
                        <p v-if="pdfError" class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ pdfError }}</p>

                        <p
                            v-if="asset.status === 'removed'"
                            class="mb-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900"
                            data-test="banner-abbattuto"
                        >
                            Elemento abbattuto/rimosso<template v-if="removedOn"> il {{ removedOn }}</template>.
                            <template v-if="asset.removal_reason">Motivo: {{ asset.removal_reason }}.</template>
                            Resta nell'archivio del censimento, ma non compare più nell'elenco
                            di tutti i giorni né nella consistenza del patrimonio.
                        </p>

                        <p
                            v-else-if="asset.status === 'dismissed'"
                            class="mb-3 rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-700"
                            data-test="banner-dismesso"
                        >
                            Elemento dismesso: sta nell'archivio del censimento e non compare più
                            nell'elenco di tutti i giorni, nelle planimetrie né nelle consistenze.
                            Per riprenderlo in gestione riporta lo stato ad Attivo dal pannello di modifica.
                        </p>

                        <p v-if="asset.public_token" class="mb-3 rounded-lg bg-green-50 px-3 py-2 text-xs text-green-900" data-test="public-url">
                            Pagina pubblica attiva:
                            <a :href="`/p/${asset.public_token}`" target="_blank" class="font-medium underline">{{ `${origin}/p/${asset.public_token}` }}</a>
                        </p>

                        <p
                            v-if="canUpdate"
                            class="mb-3 flex flex-wrap items-center gap-2 rounded-lg px-3 py-2 text-xs"
                            :class="asset.public_hidden ? 'bg-amber-50 text-amber-900' : 'bg-gray-50 text-gray-600'"
                            data-test="portale-visibilita"
                        >
                            <span>
                                {{ asset.public_hidden
                                    ? 'Nascosto dal portale pubblico del committente.'
                                    : 'Visibile sul portale pubblico del committente, se attivo.' }}
                            </span>
                            <button
                                class="font-medium underline disabled:opacity-50"
                                :disabled="publicBusy"
                                data-test="portale-toggle"
                                @click="toggleNascondi"
                            >{{ asset.public_hidden ? 'Mostra di nuovo' : 'Nascondi dal portale' }}</button>
                        </p>

                        <AssetEditPanel
                            v-if="editing"
                            :key="asset.version"
                            :asset="asset"
                            class="mt-4"
                            @saved="onSaved"
                            @close="editing = false"
                        />

                        <dl class="mt-4 grid grid-cols-1 gap-x-8 gap-y-2 text-sm md:grid-cols-2">
                            <div class="flex justify-between border-b border-gray-50 py-1.5">
                                <dt class="text-gray-500">Area</dt>
                                <dd class="font-medium">{{ asset.area?.name || '—' }}</dd>
                            </div>
                            <div class="flex justify-between border-b border-gray-50 py-1.5">
                                <dt class="text-gray-500">Misura</dt>
                                <dd class="font-medium">{{ measure || '—' }}</dd>
                            </div>
                            <div class="flex justify-between border-b border-gray-50 py-1.5">
                                <dt class="text-gray-500">Metodo rilievo</dt>
                                <dd>{{ asset.survey_method || '—' }}</dd>
                            </div>
                            <div class="flex justify-between border-b border-gray-50 py-1.5">
                                <dt class="text-gray-500">Precisione GPS</dt>
                                <dd>{{ asset.gps_accuracy_m ? `${asset.gps_accuracy_m} m` : '—' }}</dd>
                            </div>
                            <div class="flex justify-between border-b border-gray-50 py-1.5">
                                <dt class="text-gray-500">Versione scheda</dt>
                                <dd>v{{ asset.version }} ({{ asset.versions_count }} modifiche storicizzate)</dd>
                            </div>
                            <div class="flex justify-between border-b border-gray-50 py-1.5">
                                <dt class="text-gray-500">Aggiornato</dt>
                                <dd>{{ new Date(asset.updated_at).toLocaleString('it-IT') }}</dd>
                            </div>
                        </dl>

                        <template v-if="attributeRows.length">
                            <h2 class="mt-5 text-sm font-semibold">Attributi</h2>
                            <dl class="mt-2 grid grid-cols-1 gap-x-8 gap-y-1 text-sm md:grid-cols-2">
                                <div v-for="[key, value] in attributeRows" :key="key" class="flex justify-between border-b border-gray-50 py-1.5">
                                    <dt class="text-gray-500">{{ key }}</dt>
                                    <dd class="font-medium">{{ value }}</dd>
                                </div>
                            </dl>
                        </template>

                        <template v-if="asset.notes">
                            <h2 class="mt-5 text-sm font-semibold">Note</h2>
                            <p class="mt-1 whitespace-pre-line text-sm text-gray-700">{{ asset.notes }}</p>
                        </template>

                        <template v-if="asset.tags?.length">
                            <h2 class="mt-5 text-sm font-semibold">Tag fisici</h2>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <span
                                    v-for="tag in asset.tags"
                                    :key="tag.id"
                                    class="rounded-full bg-blue-50 px-3 py-1 text-xs font-medium text-blue-800"
                                >
                                    {{ tag.tag_type.toUpperCase() }} · {{ tag.uid }}
                                </span>
                            </div>
                        </template>
                    </div>

                    <!-- Scheda albero + VTA -->
                    <TreeVtaPanel
                        v-if="asset.tree"
                        :key="`tree-${asset.version}`"
                        :asset="asset"
                        :can-update="canUpdate"
                        :apri-valutazione="apriValutazione"
                        @saved="load"
                    />

                    <!-- Posto libero -->
                    <PlantingSitePanel
                        v-if="asset.planting_site"
                        :key="`site-${asset.version}`"
                        :asset="asset"
                        :can-update="canUpdate"
                        :apri-valutazione="apriValutazione"
                        @saved="load"
                    />

                    <!-- Foto -->
                    <div class="mt-6 rounded-xl border border-gray-200 bg-white p-6">
                        <div class="flex items-center justify-between">
                            <h2 class="text-sm font-semibold">Fotografie ({{ asset.photos?.length ?? 0 }})</h2>
                            <label v-if="canUpdate" class="cursor-pointer rounded-lg bg-green-700 px-3 py-1.5 text-sm font-medium text-white hover:bg-green-800">
                                {{ uploading ? 'Caricamento…' : 'Carica foto' }}
                                <input type="file" accept="image/jpeg,image/png,image/webp" class="hidden" :disabled="uploading" @change="uploadPhoto">
                            </label>
                        </div>
                        <p v-if="uploadError" class="mt-2 text-sm text-red-600">{{ uploadError }}</p>

                        <div v-if="asset.photos?.length" class="mt-4 grid grid-cols-2 gap-3 md:grid-cols-4">
                            <a
                                v-for="photo in asset.photos"
                                :key="photo.id"
                                :href="photo.url"
                                target="_blank"
                                class="group relative block aspect-square overflow-hidden rounded-lg bg-gray-100"
                            >
                                <img :src="photo.url" :alt="photo.original_filename" class="h-full w-full object-cover transition group-hover:scale-105" loading="lazy">
                                <span class="absolute bottom-1 left-1 rounded bg-black/60 px-1.5 py-0.5 text-[10px] text-white">
                                    {{ photo.category }}
                                </span>
                            </a>
                        </div>
                        <p v-else class="mt-3 text-sm text-gray-400">Nessuna fotografia caricata.</p>
                    </div>

                    <!-- Storia delle modifiche -->
                    <div class="mt-6 rounded-xl border border-gray-200 bg-white p-6">
                        <div class="flex items-center justify-between">
                            <h2 class="text-sm font-semibold">Storia delle modifiche</h2>
                            <button
                                class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50"
                                data-test="apri-storia"
                                @click="apriStoria"
                            >{{ storiaAperta ? 'Nascondi' : 'Mostra' }}</button>
                        </div>

                        <template v-if="storiaAperta">
                            <p v-if="storiaInCaricamento" class="mt-3 text-sm text-gray-400">Caricamento…</p>
                            <p v-else-if="storiaErrore" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ storiaErrore }}</p>
                            <p v-else-if="! storia?.length" class="mt-3 text-sm text-gray-400">
                                Nessuna modifica registrata: la scheda è ancora come è stata creata.
                            </p>

                            <div v-else class="mt-3 space-y-4" data-test="storia-modifiche">
                                <div v-for="r in storia" :key="r.versione" class="rounded-lg border border-gray-100">
                                    <div class="flex flex-wrap items-center gap-2 border-b border-gray-100 bg-gray-50 px-3 py-1.5 text-xs text-gray-600">
                                        <span class="font-medium text-gray-800">{{ r.quando }}</span>
                                        <span v-if="r.chi">{{ r.chi }}</span>
                                        <span v-if="r.origine && r.origine !== 'web'" class="rounded bg-gray-200 px-1.5 text-[10px] uppercase">{{ r.origine }}</span>
                                    </div>
                                    <div class="overflow-x-auto">
                                        <table class="w-full text-xs">
                                            <tbody>
                                                <tr v-for="m in r.modifiche" :key="m.campo" class="border-b border-gray-50 last:border-0">
                                                    <td class="px-3 py-1.5 font-medium text-gray-700">{{ m.campo }}</td>
                                                    <td class="px-3 py-1.5 text-gray-500">
                                                        <span v-if="m.prima !== null" class="line-through decoration-red-300">{{ m.prima }}</span>
                                                        <span v-else>—</span>
                                                    </td>
                                                    <td class="px-3 py-1.5">
                                                        <span class="rounded bg-green-100 px-1.5 py-0.5 text-green-900">{{ m.dopo ?? '—' }}</span>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>

                    <!-- Vincoli del territorio -->
                    <div class="mt-6 rounded-xl border border-gray-200 bg-white p-6">
                        <h2 class="text-sm font-semibold">Vincoli ({{ vincoli.length }})</h2>
                        <p class="mt-0.5 text-xs text-gray-500">
                            Compaiono nella scheda pubblica dell'elemento, con il documento scaricabile.
                            L'anagrafica dei vincoli si gestisce in Territorio.
                        </p>

                        <ul v-if="vincoli.length" class="mt-3 divide-y divide-gray-50">
                            <li v-for="v in vincoli" :key="v.id" class="flex flex-wrap items-center gap-2 py-2 text-sm">
                                <span class="font-medium">{{ v.code }}</span>
                                <span class="text-gray-500">{{ v.name }}</span>
                                <span class="rounded bg-gray-100 px-1.5 text-[10px] uppercase text-gray-500">
                                    {{ v.source === 'spatial' ? 'da perimetro' : 'a mano' }}
                                </span>
                                <span v-if="! v.is_public" class="rounded bg-amber-100 px-1.5 text-[10px] uppercase text-amber-800">non pubblico</span>
                                <button
                                    v-if="canUpdate && v.source === 'manual'"
                                    class="ml-auto text-xs text-red-500 hover:underline"
                                    @click="scollegaVincolo(v.id)"
                                >scollega</button>
                            </li>
                        </ul>
                        <p v-else class="mt-3 text-sm text-gray-400">Nessun vincolo collegato.</p>

                        <div v-if="canUpdate && vincoliDisponibili.length" class="mt-3 flex flex-wrap gap-2">
                            <select v-model="vincoloDaCollegare" class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm sm:w-auto">
                                <option value="">Collega un vincolo…</option>
                                <option v-for="v in vincoliDisponibili" :key="v.id" :value="v.id">
                                    {{ v.code }}<template v-if="v.name"> — {{ v.name }}</template>
                                </option>
                            </select>
                            <button
                                class="rounded-lg border border-green-700 px-3 py-1.5 text-xs font-medium text-green-700 hover:bg-green-50 disabled:opacity-50"
                                :disabled="! vincoloDaCollegare"
                                @click="collegaVincolo"
                            >Collega</button>
                        </div>
                    </div>

                    <GestionalePanel :asset="asset" class="mt-6" />

                    <!-- Fine vita della scheda: abbattimento (si conserva) o eliminazione (rilievo sbagliato) -->
                    <div v-if="canUpdate || canDelete" class="mt-6 rounded-xl border border-gray-200 bg-white p-6">
                        <h2 class="text-sm font-semibold">Abbattimento ed eliminazione</h2>

                        <template v-if="canUpdate">
                            <div v-if="asset.status === 'removed'" class="mt-3 flex flex-wrap items-center gap-3">
                                <p class="text-sm text-gray-600">
                                    L'abbattimento è registrato<template v-if="removedOn"> con data {{ removedOn }}</template>.
                                </p>
                                <button
                                    class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                                    :disabled="removal.busy"
                                    data-test="annulla-abbattimento"
                                    @click="cancelRemoval"
                                >Annulla abbattimento</button>
                            </div>

                            <template v-else>
                                <p class="mt-1 text-sm text-gray-600">
                                    La pianta è stata abbattuta o l'elemento rimosso? Registralo qui: la scheda
                                    resta in archivio con la sua storia, esce dal censimento attivo e dal bilancio
                                    arboreo, e la pagina pubblica col QR viene spenta.
                                </p>
                                <button
                                    v-if="! removal.open"
                                    class="mt-3 rounded-lg border border-amber-700 px-3 py-1.5 text-xs font-medium text-amber-800 hover:bg-amber-50"
                                    data-test="registra-abbattimento"
                                    @click="removal.open = true"
                                >Registra abbattimento</button>

                                <div v-else class="mt-3 rounded-xl border border-amber-200 bg-amber-50/50 p-4">
                                    <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                                        <label class="block text-xs">
                                            <span class="text-gray-500">Data dell'abbattimento</span>
                                            <input
                                                v-model="removal.date"
                                                type="date"
                                                data-test="data-abbattimento"
                                                class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm"
                                            >
                                        </label>
                                        <label class="block text-xs md:col-span-2">
                                            <span class="text-gray-500">Motivo (facoltativo)</span>
                                            <input
                                                v-model="removal.reason"
                                                placeholder="es. classe di propensione al cedimento D, schianto, cantiere"
                                                data-test="motivo-abbattimento"
                                                class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm"
                                            >
                                        </label>
                                    </div>
                                    <div class="mt-3 flex gap-2">
                                        <button
                                            class="rounded-lg bg-amber-700 px-4 py-2 text-sm font-medium text-white hover:bg-amber-800 disabled:opacity-50"
                                            :disabled="removal.busy || ! removal.date"
                                            data-test="conferma-abbattimento"
                                            @click="saveRemoval"
                                        >{{ removal.busy ? 'Registrazione…' : 'Conferma abbattimento' }}</button>
                                        <button
                                            class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50"
                                            @click="removal.open = false"
                                        >Annulla</button>
                                    </div>
                                </div>
                            </template>

                            <p v-if="removal.error" class="mt-2 text-sm text-red-600" data-test="errore-abbattimento">{{ removal.error }}</p>
                        </template>

                        <div v-if="canDelete" class="mt-4 border-t border-gray-100 pt-4">
                            <p class="text-sm text-gray-600">
                                Se questa scheda è stata creata per sbaglio (doppione, punto messo nel posto
                                errato) puoi eliminarla. Non è possibile quando l'elemento è già entrato da
                                qualche parte: ordini di lavoro, preventivi, rapportini, ispezioni,
                                segnalazioni, non conformità, trattamenti, valutazioni di stabilità, invii al
                                gestionale, tag fisici ancora associati. In quel caso la scheda è già storia
                                di qualcun altro e non si elimina.
                            </p>
                            <!-- La strada per i doppioni non eliminabili: l'archivio, non
                                 l'abbattimento (che scriverebbe la data di una pianta mai abbattuta) -->
                            <p class="mt-2 text-sm text-gray-600" data-test="spiegazione-archivio">
                                Per una scheda che non si può eliminare ma non va più gestita — il doppione
                                già collegato a un ordine, l'elemento uscito dal contratto — c'è l'archivio:
                                premi "Modifica" in cima alla scheda e imposta lo stato su "Dismesso".
                                La scheda esce dall'elenco di tutti i giorni e dalle planimetrie, resta
                                consultabile nell'archivio del censimento e si può sempre rimettere Attiva.
                            </p>
                            <button
                                class="mt-3 rounded-lg border border-red-300 px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-50 disabled:opacity-50"
                                :disabled="deletion.busy"
                                data-test="elimina-scheda"
                                @click="deleteAsset"
                            >{{ deletion.busy ? 'Eliminazione…' : 'Elimina scheda' }}</button>
                            <p v-if="deletion.error" class="mt-2 text-sm text-red-600" data-test="errore-eliminazione">{{ deletion.error }}</p>
                        </div>
                    </div>
                </div>

                <!-- Mini mappa -->
                <div>
                    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                        <div ref="mapEl" class="h-72 w-full" />
                        <div class="p-3 text-xs text-gray-500">
                            Posizione dell'elemento — <a :href="`/mappa`" class="text-green-700 hover:underline">apri la mappa completa</a>
                        </div>
                    </div>
                </div>
            </div>

            <div v-else class="mt-10 text-center text-gray-400">Caricamento scheda…</div>
        </div>
    </AppLayout>
</template>
