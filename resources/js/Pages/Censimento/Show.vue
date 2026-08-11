<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import * as maplibregl from 'maplibre-gl';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import AssetEditPanel from '@/Components/AssetEditPanel.vue';
import PlantingSitePanel from '@/Components/PlantingSitePanel.vue';
import TreeVtaPanel from '@/Components/TreeVtaPanel.vue';

const props = defineProps({ assetId: { type: String, required: true } });

const page = usePage();
const canUpdate = computed(() => (page.props.auth?.user?.permissions ?? []).includes('assets.update'));

const asset = ref(null);
const editing = ref(false);

async function onSaved() {
    editing.value = false;
    await load();
}
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
                                    v-if="canUpdate && ! editing"
                                    class="rounded-lg border border-green-700 px-3 py-1.5 text-xs font-medium text-green-700 hover:bg-green-50"
                                    @click="editing = true"
                                >Modifica</button>
                                <span
                                    class="rounded-full px-3 py-1 text-xs font-medium"
                                    :class="asset.status === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'"
                                >
                                    {{ asset.status }}
                                </span>
                            </div>
                        </div>

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
