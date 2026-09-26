<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import * as maplibregl from 'maplibre-gl';
import { STATI } from '@/portale/formato';

/*
 * La mappa del territorio del Comune: le sue aree e i suoi elementi in
 * gestione, dai riquadri vettoriali riservati (/api/v1/portal/tiles), con lo
 * stato a quattro voci della mappa pubblica. Gli sfondi sono quelli del
 * portale pubblico piu' quelli propri del Comune (ortofoto, carta tecnica).
 */
const props = defineProps({
    sfondi: { type: Array, default: () => [] },
    // { sw: [lon, lat], ne: [lon, lat] } del territorio, per aprire gia' inquadrati
    estensione: { type: Object, default: null },
    evidenziato: { type: String, default: null },
    altezza: { type: String, default: 'h-[380px] md:h-[460px]' },
});
const emit = defineEmits(['apri', 'area']);

const contenitore = ref(null);
const sfondoScelto = ref(props.sfondi[0]?.id ?? null);
let map = null;
let popup = null;

const LEGENDA = ['sano', 'cura', 'potare', 'verifica', 'altro'];
const coloreStato = ['match', ['get', 'stato'],
    ...LEGENDA.flatMap((s) => [s, STATI[s].colore]),
    STATI.altro.colore];

const riduciMovimento = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

function sfondoCorrente() {
    return props.sfondi.find((s) => s.id === sfondoScelto.value) ?? props.sfondi[0] ?? null;
}

function sorgenteSfondo(sfondo) {
    return {
        type: 'raster',
        tiles: [sfondo.url],
        tileSize: 256,
        // Oltre questo livello il fornitore non ha immagini: senza
        // dichiararlo la mappa si svuota ingrandendo
        maxzoom: sfondo.zoom_massimo ?? 19,
        attribution: sfondo.attribuzione ?? '',
    };
}

/** Raggio della chioma in pixel dal diametro in metri: esatto a ogni zoom con base 2. */
function raggioChioma(latitudine) {
    const cosLat = Math.cos(((latitudine ?? 42) * Math.PI) / 180) || 1;
    const pixelPerMetro = (zoom) => (2 ** zoom) / (78271.517 * cosLat);
    const raggio = (zoom) => ['*', ['/', ['coalesce', ['to-number', ['get', 'chioma_m']], 0], 2], pixelPerMetro(zoom)];

    return ['interpolate', ['exponential', 2], ['zoom'], 15, raggio(15), 22, raggio(22)];
}

function stile() {
    const sfondo = sfondoCorrente();
    const latitudine = props.estensione ? (props.estensione.sw[1] + props.estensione.ne[1]) / 2 : 42;
    const sources = {
        territorio: {
            type: 'vector',
            tiles: [`${window.location.origin}/api/v1/portal/tiles/{z}/{x}/{y}`],
            minzoom: 5,
            maxzoom: 22,
        },
    };
    const layers = [{ id: 'fondo', type: 'background', paint: { 'background-color': '#e8ede9' } }];
    if (sfondo) {
        sources.sfondo = sorgenteSfondo(sfondo);
        layers.push({ id: 'sfondo', type: 'raster', source: 'sfondo' });
    }
    const scuro = Boolean(sfondo?.scuro);
    layers.push(
        {
            id: 'aree-fill', type: 'fill', source: 'territorio', 'source-layer': 'aree',
            paint: { 'fill-color': '#15803d', 'fill-opacity': scuro ? 0.18 : 0.1 },
        },
        {
            id: 'aree-line', type: 'line', source: 'territorio', 'source-layer': 'aree',
            paint: { 'line-color': scuro ? '#86efac' : '#15803d', 'line-width': 2, 'line-dasharray': [3, 2] },
        },
        {
            id: 'elementi-poligoni', type: 'fill', source: 'territorio', 'source-layer': 'elementi',
            filter: ['==', ['geometry-type'], 'Polygon'],
            paint: { 'fill-color': coloreStato, 'fill-opacity': 0.35 },
        },
        {
            id: 'elementi-linee', type: 'line', source: 'territorio', 'source-layer': 'elementi',
            filter: ['in', ['geometry-type'], ['literal', ['LineString', 'Polygon']]],
            paint: { 'line-color': coloreStato, 'line-width': 2.5 },
        },
        // La chioma alla misura vera, sotto il punto: da vicino l'albero
        // occupa sulla carta lo spazio che occupa in strada
        {
            id: 'chiome', type: 'circle', source: 'territorio', 'source-layer': 'elementi', minzoom: 15.5,
            filter: ['all', ['==', ['geometry-type'], 'Point'], ['>', ['coalesce', ['to-number', ['get', 'chioma_m']], 0], 0]],
            paint: {
                'circle-radius': raggioChioma(latitudine),
                'circle-color': 'rgba(255, 255, 255, 0.16)',
                'circle-stroke-width': 1.2,
                'circle-stroke-color': coloreStato,
                'circle-stroke-opacity': 0.75,
            },
        },
        {
            id: 'punti', type: 'circle', source: 'territorio', 'source-layer': 'elementi',
            filter: ['==', ['geometry-type'], 'Point'],
            paint: {
                'circle-radius': ['interpolate', ['linear'], ['zoom'], 12, 3.5, 16, 6.5, 19, 9],
                'circle-color': coloreStato,
                'circle-stroke-width': 1.5,
                'circle-stroke-color': 'rgba(255,255,255,0.9)',
            },
        },
        {
            id: 'evidenziato', type: 'circle', source: 'territorio', 'source-layer': 'elementi',
            filter: ['==', ['get', 'id'], props.evidenziato ?? ''],
            paint: {
                'circle-radius': ['interpolate', ['linear'], ['zoom'], 12, 9, 16, 14, 19, 18],
                'circle-color': 'rgba(0,0,0,0)',
                'circle-stroke-width': 3,
                'circle-stroke-color': '#111827',
            },
        },
    );

    return { version: 8, sources, layers };
}

function adatta(animato = false) {
    if (! map) return;
    if (props.estensione) {
        const bounds = new maplibregl.LngLatBounds(props.estensione.sw, props.estensione.ne);
        map.fitBounds(bounds, { padding: 48, maxZoom: 17, animate: animato && ! riduciMovimento() });
    } else {
        map.jumpTo({ center: [12.5, 42.5], zoom: 5 });
    }
}

/** Porta la mappa su un elemento (dall'elenco o dalla ricerca). */
function vaiA({ lon, lat }, zoom = 18) {
    if (! map || lon === null || lon === undefined) return;
    const dove = { center: [lon, lat], zoom: Math.max(map.getZoom(), zoom) };
    if (riduciMovimento()) map.jumpTo(dove);
    else map.flyTo({ ...dove, speed: 1.4 });
}

function cambiaSfondo() {
    if (! map) return;
    const sfondo = sfondoCorrente();
    if (map.getLayer('sfondo')) map.removeLayer('sfondo');
    if (map.getSource('sfondo')) map.removeSource('sfondo');
    if (sfondo) {
        map.addSource('sfondo', sorgenteSfondo(sfondo));
        map.addLayer({ id: 'sfondo', type: 'raster', source: 'sfondo' }, 'aree-fill');
        const scuro = Boolean(sfondo.scuro);
        map.setPaintProperty('aree-line', 'line-color', scuro ? '#86efac' : '#15803d');
        map.setPaintProperty('aree-fill', 'fill-opacity', scuro ? 0.18 : 0.1);
    }
}

const STRATI_ELEMENTI = ['punti', 'elementi-poligoni', 'elementi-linee'];

onMounted(() => {
    map = new maplibregl.Map({
        container: contenitore.value,
        style: stile(),
        attributionControl: { compact: true },
        center: [12.5, 42.5],
        zoom: 5,
    });
    // Aggancio per le verifiche automatiche nel browser (Playwright)
    window.__mappaPortale = map;
    map.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');
    map.addControl(new maplibregl.ScaleControl({ unit: 'metric' }));
    // L'inquadratura non aspetta lo stile: cosi' la mappa nasce gia' sul
    // territorio, e si conferma a stile caricato (il contenitore puo' essere
    // cambiato di misura nel frattempo)
    adatta(false);
    map.on('load', () => { map.resize(); adatta(false); });

    map.on('click', (e) => {
        const elemento = map.queryRenderedFeatures(e.point, { layers: STRATI_ELEMENTI.filter((l) => map.getLayer(l)) })[0];
        if (elemento?.properties?.id) {
            popup?.remove();
            emit('apri', { id: elemento.properties.id, codice: elemento.properties.codice, lon: e.lngLat.lng, lat: e.lngLat.lat });

            return;
        }
        const area = map.queryRenderedFeatures(e.point, { layers: ['aree-fill'] })[0];
        if (area?.properties?.nome) {
            popup?.remove();
            popup = new maplibregl.Popup({ closeButton: true, closeOnClick: true, maxWidth: '280px' })
                .setLngLat(e.lngLat)
                .setText(area.properties.nome + (area.properties.codice ? ` (${area.properties.codice})` : ''))
                .addTo(map);
            emit('area', { id: area.properties.id, nome: area.properties.nome });
        }
    });
    const mano = () => { map.getCanvas().style.cursor = 'pointer'; };
    const freccia = () => { map.getCanvas().style.cursor = ''; };
    [...STRATI_ELEMENTI, 'aree-fill'].forEach((l) => { map.on('mouseenter', l, mano); map.on('mouseleave', l, freccia); });
});

watch(() => props.evidenziato, (id) => {
    if (map?.getLayer('evidenziato')) map.setFilter('evidenziato', ['==', ['get', 'id'], id ?? '']);
});
watch(() => props.estensione, () => { if (map?.loaded()) adatta(true); });

onBeforeUnmount(() => { popup?.remove(); map?.remove(); map = null; });

defineExpose({ vaiA, adatta });
</script>

<template>
    <div class="relative overflow-hidden rounded-[10px] border border-gray-200 bg-[#e8ede9]" :class="altezza" data-test="portale-mappa">
        <div ref="contenitore" class="h-full w-full"></div>

        <div class="pointer-events-none absolute inset-x-0 top-0 flex flex-wrap items-start justify-between gap-2 p-2 pr-14">
            <label v-if="sfondi.length > 1" class="pointer-events-auto">
                <span class="sr-only">Sfondo della mappa</span>
                <select
                    v-model="sfondoScelto"
                    class="min-h-11 rounded-lg border border-gray-300 bg-white/95 px-2.5 text-sm font-medium text-gray-900 shadow-sm md:min-h-9"
                    data-test="portale-mappa-sfondo"
                    @change="cambiaSfondo"
                >
                    <option v-for="s in sfondi" :key="s.id" :value="s.id">{{ s.nome }}</option>
                </select>
            </label>
            <slot name="sopra" />
        </div>

        <ul class="pointer-events-none absolute bottom-9 left-2 flex max-w-[calc(100%-1rem)] flex-wrap gap-x-3 gap-y-1 rounded-lg bg-white/92 px-2.5 py-1.5 text-[12px] font-medium text-gray-800 shadow-sm" aria-label="Legenda">
            <li v-for="s in LEGENDA" :key="s" class="flex items-center gap-1.5">
                <span class="inline-block h-2.5 w-2.5 rounded-full" :style="{ background: STATI[s].colore }" aria-hidden="true"></span>{{ STATI[s].etichetta }}
            </li>
            <li class="flex items-center gap-1.5"><span class="inline-block h-2.5 w-3 border border-dashed border-green-700 bg-green-700/10" aria-hidden="true"></span>Aree</li>
        </ul>
    </div>
</template>
