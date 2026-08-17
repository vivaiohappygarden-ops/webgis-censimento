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
import { fetchPdf } from '@/pdf';
import { statusLabel } from '@/assetStatus';

const props = defineProps({ assetId: { type: String, required: true } });

const page = usePage();
const permissions = computed(() => page.props.auth?.user?.permissions ?? []);
const canUpdate = computed(() => permissions.value.includes('assets.update'));
const canDelete = computed(() => permissions.value.includes('assets.delete'));

const asset = ref(null);
const editing = ref(false);
const pdfBusy = ref(false);
const pdfError = ref('');

async function openPdf() {
    pdfBusy.value = true;
    pdfError.value = '';
    const { error } = await fetchPdf(`/api/v1/assets/${props.assetId}/pdf`);
    if (error) pdfError.value = error;
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
    await load();
    initMap();
});

onBeforeUnmount(() => map?.remove());
</script>

<template>
    <Head :title="asset?.census_code || 'Scheda elemento'" />

    <AppLayout>
        <div class="p-6">
            <Link href="/censimento" class="text-sm text-green-700 hover:underline">← Torna al censimento</Link>

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
                            <div class="flex items-center gap-2">
                                <button
                                    class="rounded-lg border border-green-700 px-3 py-1.5 text-xs font-medium text-green-700 hover:bg-green-50 disabled:opacity-50"
                                    :disabled="pdfBusy"
                                    data-test="asset-pdf"
                                    @click="openPdf"
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

                        <p v-if="pdfError" class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ pdfError }}</p>

                        <p
                            v-if="asset.status === 'removed'"
                            class="mb-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900"
                            data-test="banner-abbattuto"
                        >
                            Elemento abbattuto/rimosso<template v-if="removedOn"> il {{ removedOn }}</template>.
                            <template v-if="asset.removal_reason">Motivo: {{ asset.removal_reason }}.</template>
                            Resta in archivio, ma non compare più nell'elenco del censimento
                            né nella consistenza del patrimonio.
                        </p>

                        <p v-if="asset.public_token" class="mb-3 rounded-lg bg-green-50 px-3 py-2 text-xs text-green-900" data-test="public-url">
                            Pagina pubblica attiva:
                            <a :href="`/p/${asset.public_token}`" target="_blank" class="font-medium underline">{{ `${origin}/p/${asset.public_token}` }}</a>
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
                        @saved="load"
                    />

                    <!-- Posto libero -->
                    <PlantingSitePanel
                        v-if="asset.planting_site"
                        :key="`site-${asset.version}`"
                        :asset="asset"
                        :can-update="canUpdate"
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
                                errato) puoi eliminarla. Non è possibile se l'elemento è già stato usato in
                                ordini di lavoro, ispezioni, segnalazioni o trattamenti.
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
