<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';

const page = usePage();
const canCreate = computed(() => (page.props.auth?.user?.permissions ?? []).includes('assets.create'));

const rows = ref([]);
const meta = reactive({ total: 0, current_page: 1, last_page: 1 });
const loading = ref(false);
const filters = reactive({ q: '', status: '', page: 1 });

// Import GeoJSON / CAM
const importer = reactive({ open: false, format: 'geojson', areaId: '', file: null, report: null, busy: false, error: '' });
const importAreas = ref([]);

// Export CAM: un layer del Modello Dati per volta (P/L/S + macro-categoria)
const CAM_LAYERS = {
    P1: 'P1 Vegetazione (punti: alberi)',
    L1: 'L1 Vegetazione (linee: siepi, filari)',
    S1: 'S1 Vegetazione (superfici: prati, aiuole)',
    P2: 'P2 Arredo urbano (punti)',
    L2: 'L2 Arredo urbano (linee)',
    S2: 'S2 Arredo urbano (superfici)',
    P3: 'P3 Fruizione e gestione (punti)',
    L3: 'L3 Fruizione e gestione (linee)',
    S3: 'S3 Fruizione e gestione (superfici e perimetri aree)',
    S4: 'S4 Fattori ambientali (superfici)',
};
const exportLayer = ref('P1');

async function openImporter() {
    importer.open = true;
    importer.report = null;
    importer.error = '';
    if (! importAreas.value.length) {
        const { data } = await axios.get('/api/v1/areas', { params: { per_page: 100 } });
        importAreas.value = data.data;
    }
}

async function runImport(dryRun) {
    if (! importer.file || ! importer.areaId) {
        importer.error = 'Scegli un file e un\'area di destinazione.';
        return;
    }
    importer.busy = true;
    importer.error = '';
    try {
        const form = new FormData();
        form.append('file', importer.file);
        form.append('area_id', importer.areaId);
        form.append('dry_run', dryRun ? '1' : '0');
        const endpoint = importer.format === 'cam' ? '/api/v1/imports/cam' : '/api/v1/imports/geojson';
        const { data } = await axios.post(endpoint, form);
        importer.report = data.data;
        if (! dryRun) {
            await load();
        }
    } catch (err) {
        importer.error = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
            ?? err.response?.data?.message ?? 'Errore durante l\'import';
    } finally {
        importer.busy = false;
    }
}

let debounce = null;

async function load() {
    loading.value = true;
    try {
        const { data } = await axios.get('/api/v1/assets', {
            params: {
                q: filters.q || undefined,
                status: filters.status || undefined,
                page: filters.page,
                per_page: 25,
            },
        });
        rows.value = data.data;
        meta.total = data.total;
        meta.current_page = data.current_page;
        meta.last_page = data.last_page;
    } finally {
        loading.value = false;
    }
}

watch(() => [filters.q, filters.status], () => {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
        filters.page = 1;
        load();
    }, 300);
});

watch(() => filters.page, load);

onMounted(load);

const measure = (row) => {
    if (row.computed_area_sqm) return `${Number(row.computed_area_sqm).toLocaleString('it-IT')} m²`;
    if (row.computed_length_m) return `${Number(row.computed_length_m).toLocaleString('it-IT')} m`;
    return '—';
};
</script>

<template>
    <Head title="Censimento" />

    <AppLayout>
        <div class="p-6">
            <div class="mb-4 flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-semibold">Censimento</h1>
                    <p class="text-sm text-gray-500">{{ meta.total.toLocaleString('it-IT') }} elementi censiti</p>
                </div>
                <div class="flex items-center gap-2">
                    <select v-model="exportLayer" data-test="cam-export-layer" class="rounded-lg border border-gray-300 px-2 py-2 text-sm">
                        <option v-for="(label, value) in CAM_LAYERS" :key="value" :value="value">{{ label }}</option>
                    </select>
                    <a
                        :href="`/api/v1/exports/cam?layer=${exportLayer}&format=geojson`"
                        class="rounded-lg border border-green-700 px-3 py-2 text-sm font-medium text-green-700 hover:bg-green-50"
                        data-test="cam-export-geojson"
                    >Esporta GeoJSON</a>
                    <a
                        :href="`/api/v1/exports/cam?layer=${exportLayer}&format=shapefile`"
                        class="rounded-lg border border-green-700 px-3 py-2 text-sm font-medium text-green-700 hover:bg-green-50"
                        data-test="cam-export-shapefile"
                    >Esporta Shapefile</a>
                    <button
                        v-if="canCreate"
                        class="rounded-lg bg-green-700 px-3 py-2 text-sm font-medium text-white hover:bg-green-800"
                        @click="openImporter"
                    >Importa GeoJSON</button>
                </div>
            </div>

            <div class="mb-4 flex gap-3">
                <input
                    v-model="filters.q"
                    type="search"
                    placeholder="Cerca per codice o note…"
                    class="w-72 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-green-600 focus:outline-none"
                >
                <select v-model="filters.status" class="rounded-lg border border-gray-300 px-3 py-2 text-sm">
                    <option value="">Tutti gli stati</option>
                    <option value="active">Attivo</option>
                    <option value="dismissed">Dismesso</option>
                </select>
            </div>

            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-4 py-3">Codice</th>
                            <th class="px-4 py-3">Tipo</th>
                            <th class="px-4 py-3">Stato</th>
                            <th class="px-4 py-3">Misura</th>
                            <th class="px-4 py-3">Aggiornato</th>
                            <th class="px-4 py-3" />
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-if="loading">
                            <td colspan="6" class="px-4 py-8 text-center text-gray-400">Caricamento…</td>
                        </tr>
                        <tr v-else-if="rows.length === 0">
                            <td colspan="6" class="px-4 py-8 text-center text-gray-400">Nessun elemento trovato.</td>
                        </tr>
                        <tr v-for="row in rows" v-else :key="row.id" class="hover:bg-green-50/40">
                            <td class="px-4 py-3 font-medium">{{ row.census_code || '—' }}</td>
                            <td class="px-4 py-3">
                                <span class="text-xs text-gray-400">{{ row.object_type?.code }}</span>
                                {{ row.object_type?.name }}
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="row.status === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'"
                                >
                                    {{ row.status }}
                                </span>
                            </td>
                            <td class="px-4 py-3">{{ measure(row) }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ new Date(row.updated_at).toLocaleDateString('it-IT') }}</td>
                            <td class="px-4 py-3 text-right">
                                <Link :href="`/censimento/${row.id}`" class="font-medium text-green-700 hover:underline">
                                    Apri
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="meta.last_page > 1" class="mt-4 flex items-center justify-between text-sm">
                <button
                    class="rounded-lg border border-gray-300 px-3 py-1.5 disabled:opacity-40"
                    :disabled="filters.page <= 1"
                    @click="filters.page--"
                >
                    ← Precedente
                </button>
                <span class="text-gray-500">Pagina {{ meta.current_page }} di {{ meta.last_page }}</span>
                <button
                    class="rounded-lg border border-gray-300 px-3 py-1.5 disabled:opacity-40"
                    :disabled="filters.page >= meta.last_page"
                    @click="filters.page++"
                >
                    Successiva →
                </button>
            </div>

            <!-- Modal import GeoJSON -->
            <Teleport to="body">
                <div v-if="importer.open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4" @click.self="importer.open = false">
                    <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl">
                        <div class="flex items-start justify-between">
                            <h2 class="font-semibold">Importa censimento</h2>
                            <button class="text-gray-400 hover:text-gray-600" @click="importer.open = false">✕</button>
                        </div>
                        <p class="mt-1 text-xs text-gray-500">
                            {{ importer.format === 'cam'
                                ? 'Shapefile zippato o GeoJSON nel formato ministeriale (Modello Dati v2.1), tutte le macro-categorie: campi CODICE, OBJ_ID, GENERE/SPECIE, H_m, LARG_m, DATA_RIL…'
                                : 'FeatureCollection con proprietà codice (codice catalogo, es. P103108) per ogni feature.' }}
                            Prima l'analisi, poi l'import: nulla viene importato silenziosamente.
                        </p>

                        <div class="mt-4 space-y-3">
                            <div class="grid grid-cols-2 gap-2">
                                <label
                                    v-for="opt in [
                                        { value: 'geojson', label: 'GeoJSON generico' },
                                        { value: 'cam', label: 'Formato CAM (shapefile/GeoJSON)' },
                                    ]"
                                    :key="opt.value"
                                    class="flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm"
                                    :class="importer.format === opt.value ? 'border-green-700 bg-green-50 font-medium' : 'border-gray-300'"
                                >
                                    <input v-model="importer.format" type="radio" :value="opt.value" class="hidden" @change="importer.report = null">
                                    {{ opt.label }}
                                </label>
                            </div>
                            <input
                                type="file"
                                :accept="importer.format === 'cam' ? '.zip,.json,.geojson' : '.json,.geojson,application/json,application/geo+json'"
                                class="w-full text-sm"
                                @change="(e) => { importer.file = e.target.files?.[0] ?? null; importer.report = null; }"
                            >
                            <select v-model="importer.areaId" class="w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm">
                                <option value="" disabled>Area di destinazione…</option>
                                <option v-for="a in importAreas" :key="a.id" :value="a.id">{{ a.name }}</option>
                            </select>

                            <p v-if="importer.error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ importer.error }}</p>

                            <div v-if="importer.report" class="rounded-xl border border-gray-200 p-3 text-sm">
                                <div class="grid grid-cols-3 gap-2 text-center">
                                    <div><div class="text-lg font-semibold">{{ importer.report.total }}</div><div class="text-xs text-gray-500">nel file</div></div>
                                    <div><div class="text-lg font-semibold text-green-700">{{ importer.report.dry_run ? importer.report.importable : importer.report.imported }}</div><div class="text-xs text-gray-500">{{ importer.report.dry_run ? 'importabili' : 'importati' }}</div></div>
                                    <div><div class="text-lg font-semibold text-red-600">{{ importer.report.errors_total }}</div><div class="text-xs text-gray-500">scartati</div></div>
                                </div>
                                <ul v-if="importer.report.errors.length" class="mt-2 max-h-32 space-y-0.5 overflow-y-auto text-xs text-red-700">
                                    <li v-for="(e, i) in importer.report.errors" :key="i">• {{ e.error }}</li>
                                </ul>
                                <ul v-if="importer.report.warnings?.length" class="mt-2 max-h-24 space-y-0.5 overflow-y-auto text-xs text-amber-700">
                                    <li v-for="(w, i) in importer.report.warnings" :key="i">• {{ w }}</li>
                                </ul>
                                <p v-if="importer.report.dropped_properties?.length" class="mt-2 text-xs text-gray-500">
                                    Proprietà ignorate (non definite come campi del tipo): {{ importer.report.dropped_properties.join(', ') }}
                                </p>
                                <p v-if="! importer.report.dry_run" class="mt-2 font-medium text-green-700">Import completato</p>
                            </div>

                            <div class="flex gap-2">
                                <button
                                    class="flex-1 rounded-lg border border-green-700 px-3 py-2 text-sm font-medium text-green-700 hover:bg-green-50 disabled:opacity-50"
                                    :disabled="importer.busy"
                                    @click="runImport(true)"
                                >{{ importer.busy ? 'Analisi…' : '1 · Analizza (dry-run)' }}</button>
                                <button
                                    class="flex-1 rounded-lg bg-green-700 px-3 py-2 text-sm font-medium text-white hover:bg-green-800 disabled:opacity-50"
                                    :disabled="importer.busy || ! importer.report || ! importer.report.dry_run || importer.report.importable === 0"
                                    @click="runImport(false)"
                                >2 · Importa {{ importer.report?.dry_run ? importer.report.importable : '' }}</button>
                            </div>
                        </div>
                    </div>
                </div>
            </Teleport>
        </div>
    </AppLayout>
</template>
