<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import * as maplibregl from 'maplibre-gl';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';

const page = usePage();
const canCreate = computed(() => (page.props.auth?.user?.permissions ?? []).includes('assets.create'));

const mapEl = ref(null);
let map = null;

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
const pointTypes = ref([]);
const creating = reactive({ active: false, typeId: '', areaId: '', saving: false, message: '' });
const assetLayers = ['assets-fill', 'assets-line', 'assets-point'];

const tilesUrl = () => `${window.location.origin}/api/v1/tiles/assets/{z}/{x}/{y}?v=${Date.now()}`;

function applyVisibility() {
    const active = TP.filter((t) => tpVisible[t.code]).map((t) => t.code);
    const tpFilter = ['in', ['slice', ['get', 'type_code'], 1, 2], ['literal', active]];
    map.setFilter('assets-fill', ['all', ['==', ['geometry-type'], 'Polygon'], tpFilter]);
    map.setFilter('assets-line', ['all', ['==', ['geometry-type'], 'LineString'], tpFilter]);
    map.setFilter('assets-point', ['all', ['==', ['geometry-type'], 'Point'], tpFilter]);
}

function refreshTiles() {
    map.getSource('assets').setTiles([tilesUrl()]);
}

async function onMapClick(e) {
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
        creating.message = 'Elemento salvato ✓';
        refreshTiles();
    } catch (err) {
        creating.message = err.response?.data?.message ?? 'Errore nel salvataggio';
    } finally {
        creating.saving = false;
    }
}

function onFeatureClick(e) {
    if (creating.active) return;
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
    map.addControl(new maplibregl.NavigationControl(), 'top-right');
    map.addControl(new maplibregl.ScaleControl({ unit: 'metric' }));

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

    const [areasRes, catalogRes] = await Promise.all([
        axios.get('/api/v1/areas', { params: { per_page: 100 } }),
        axios.get('/api/v1/catalog'),
    ]);

    areas.value = areasRes.data.data;
    const features = areas.value
        .filter((a) => a.geom_geojson)
        .map((a) => ({ type: 'Feature', geometry: a.geom_geojson, properties: { id: a.id, name: a.name } }));

    const setAree = () => map.getSource('aree')?.setData({ type: 'FeatureCollection', features });
    if (map.loaded()) setAree();
    else map.on('load', setAree);

    if (features.length > 0) {
        const bounds = new maplibregl.LngLatBounds();
        const extend = (coords) => {
            if (typeof coords[0] === 'number') bounds.extend(coords);
            else coords.forEach(extend);
        };
        features.forEach((f) => extend(f.geometry.coordinates));
        map.fitBounds(bounds, { padding: 60, maxZoom: 17 });
    }

    pointTypes.value = catalogRes.data.data.flatMap((m) =>
        m.sub_types.flatMap((s) =>
            s.object_types
                .filter((t) => t.allowed_geometry === 'P')
                .map((t) => ({ id: t.id, label: `${t.code} — ${t.name}` }))
        )
    );
});

onBeforeUnmount(() => map?.remove());
</script>

<template>
    <Head title="Mappa" />

    <AppLayout>
        <div class="relative h-full">
            <div ref="mapEl" class="h-full w-full" />

            <!-- Pannello layer -->
            <div class="absolute left-4 top-4 w-60 rounded-xl bg-white/95 p-4 shadow-lg backdrop-blur">
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

                <template v-if="canCreate">
                    <hr class="my-3 border-gray-200">
                    <label class="flex cursor-pointer items-center gap-2 text-sm font-medium">
                        <input v-model="creating.active" type="checkbox" class="rounded border-gray-300">
                        ➕ Nuovo elemento
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
                        <p v-if="creating.message" class="text-xs font-medium" :class="creating.message.includes('✓') ? 'text-green-700' : 'text-red-600'">
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
                    <div class="flex justify-between"><dt class="text-gray-500">Stato</dt><dd>{{ selected.status }}</dd></div>
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
