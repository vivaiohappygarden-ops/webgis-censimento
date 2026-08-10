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
const localities = ref([]);
const pointTypes = ref([]);
const creating = reactive({ active: false, typeId: '', areaId: '', saving: false, message: '', ok: false });
const drawing = reactive({
    active: false, vertices: [], name: '', code: '', localityId: '', saving: false, message: '', ok: false,
});
const canCreateAreas = computed(() => (page.props.auth?.user?.permissions ?? []).includes('areas.create'));
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

function refreshAreasSource() {
    const features = areas.value
        .filter((a) => a.geom_geojson)
        .map((a) => ({ type: 'Feature', geometry: a.geom_geojson, properties: { id: a.id, name: a.name } }));
    map.getSource('aree')?.setData({ type: 'FeatureCollection', features });
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

    const [areasRes, catalogRes, localitiesRes] = await Promise.all([
        axios.get('/api/v1/areas', { params: { per_page: 100 } }),
        axios.get('/api/v1/catalog'),
        axios.get('/api/v1/localities', { params: { per_page: 200 } }),
    ]);
    localities.value = localitiesRes.data.data;

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
