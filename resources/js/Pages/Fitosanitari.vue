<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import ScegliVoce from '@/Components/ScegliVoce.vue';
import { avvisoCaricamento } from '@/avvisi';
import { fetchPdf } from '@/pdf';

const page = usePage();
const permissions = computed(() => page.props.auth?.user?.permissions ?? []);
const canManage = computed(() => permissions.value.includes('works.manage'));

const METHOD_LABELS = {
    irrorazione: 'Irrorazione',
    endoterapia: 'Endoterapia',
    iniezione_suolo: 'Iniezione al suolo',
    esca: 'Esca',
    altro: 'Altro',
};
const UNITS = ['l', 'ml', 'kg', 'g'];

const treatments = ref([]);
const total = ref(0);
const areas = ref([]);
const personnel = ref([]);
const loading = ref(false);
const pageError = ref('');

// Data locale dell'utente: toISOString darebbe la data UTC, che intorno
// alla mezzanotte italiana è ancora quella del giorno prima
function todayLocal() {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

const currentYear = Number(todayLocal().slice(0, 4));
const years = Array.from({ length: 6 }, (_, i) => currentYear - i);
const filters = reactive({ year: currentYear, area_id: '' });

// Un solo pannello per creare e modificare: mode distingue i due casi
const drawer = reactive({ open: false, mode: 'new', id: null, version: null, busy: false, error: '' });
const form = reactive({});
const formAssets = ref([]);
// Fotografia del modulo all'apertura: chiudendo con modifiche si chiede conferma
let formSnapshot = '';

function closeDrawer() {
    if (canManage.value && JSON.stringify(form) !== formSnapshot
        && ! window.confirm('Chiudere senza salvare le modifiche? Andranno perse.')) {
        return;
    }
    drawer.open = false;
}

function blankForm() {
    return {
        area_id: '', asset_id: '', treated_on: todayLocal(), product_name: '',
        registration_number: '', active_substance: '', vegetation: '', adversity: '',
        method: 'irrorazione', quantity: null, unit: 'l', water_volume_l: null,
        surface_sqm: null, reentry_hours: null, operator_id: '', notes: '',
    };
}

// Riferimenti del trattamento aperto: servono per mostrare l'albero anche
// se non è più in censimento e il nome dell'operatore in sola lettura
const loaded = reactive({ areaId: '', asset: null, operatorName: '' });

function firstError(err, fallback) {
    return Object.values(err.response?.data?.errors ?? {})[0]?.[0]
        ?? err.response?.data?.message ?? fallback;
}

function dateOnly(value) {
    return value ? String(value).slice(0, 10) : '';
}

function formatDate(value) {
    if (! value) return '—';
    const [y, m, d] = dateOnly(value).split('-');
    return `${d}/${m}/${y}`;
}

const fmtQty = (t) => {
    const n = Number(t.quantity) || 0;
    return `${n.toLocaleString('it-IT', { maximumFractionDigits: 3 })} ${t.unit}`;
};

// Con i filtri cambiati in rapida successione le risposte possono arrivare
// fuori ordine: si tiene solo quella dell'ultima richiesta partita
let loadSeq = 0;

async function load() {
    const seq = ++loadSeq;
    loading.value = true;
    pageError.value = '';
    try {
        const { data } = await axios.get('/api/v1/phyto-treatments', {
            params: { year: filters.year, area_id: filters.area_id || undefined },
        });
        if (seq !== loadSeq) return;
        treatments.value = data.data;
        total.value = data.total ?? data.data.length;
    } catch (err) {
        if (seq !== loadSeq) return;
        pageError.value = avvisoCaricamento(err);
    } finally {
        if (seq === loadSeq) loading.value = false;
    }
}

// Le API di aree ed elementi sono paginate (massimo 100 per pagina): si
// scorrono tutte le pagine, o le voci oltre la centesima mancherebbero
async function fetchAllPages(url, params = {}) {
    const all = [];
    for (let p = 1; p <= 20; p++) {
        const { data } = await axios.get(url, { params: { ...params, per_page: 100, page: p } });
        all.push(...(data.data ?? []));
        if (! data.next_page_url) break;
    }
    return all;
}

async function loadLookups() {
    try {
        areas.value = await fetchAllPages('/api/v1/areas');
        if (canManage.value) {
            const { data } = await axios.get('/api/v1/personnel');
            personnel.value = data.data;
        }
    } catch (err) {
        pageError.value = avvisoCaricamento(err);
    }
}

// Gli alberi selezionabili seguono l'area scelta nel modulo. Il contatore
// scarta le risposte in ritardo di un'area cambiata nel frattempo; l'albero
// del trattamento aperto non si azzera mai da solo (potrebbe non comparire
// nella lista solo perché nel frattempo è uscito dal censimento)
let assetSeq = 0;

watch(() => form.area_id, async (areaId) => {
    const seq = ++assetSeq;
    formAssets.value = [];
    if (! areaId) {
        form.asset_id = '';
        return;
    }
    try {
        const list = await fetchAllPages('/api/v1/assets', { area_id: areaId });
        if (seq !== assetSeq) return;
        formAssets.value = list;
        const isLoadedAsset = form.asset_id === loaded.asset?.id && areaId === loaded.areaId;
        if (form.asset_id && ! isLoadedAsset && ! list.some((a) => a.id === form.asset_id)) {
            form.asset_id = '';
        }
    } catch {
        if (seq === assetSeq) formAssets.value = [];
    }
});

// Opzione di riserva: l'albero registrato ma assente dall'elenco corrente
const missingLoadedAsset = computed(() => loaded.asset
    && form.area_id === loaded.areaId
    && ! formAssets.value.some((a) => a.id === loaded.asset.id)
    ? loaded.asset : null);

// Le voci dell'elemento censito: l'eventuale voce "storica" resta in cima
const alberiVoci = computed(() => [
    ...(missingLoadedAsset.value
        ? [{ id: missingLoadedAsset.value.id, census_code: `${missingLoadedAsset.value.census_code} (non più in censimento)` }]
        : []),
    ...formAssets.value,
]);

function openCreator() {
    Object.assign(form, blankForm());
    Object.assign(loaded, { areaId: '', asset: null, operatorName: '' });
    drawer.mode = 'new';
    drawer.id = null;
    drawer.version = null;
    drawer.error = '';
    formSnapshot = JSON.stringify(form);
    drawer.open = true;
}

function openDetail(treatment) {
    Object.assign(loaded, {
        areaId: treatment.area_id,
        asset: treatment.asset ?? null,
        operatorName: treatment.operator?.name ?? '',
    });
    Object.assign(form, blankForm(), {
        area_id: treatment.area_id,
        asset_id: treatment.asset_id ?? '',
        treated_on: dateOnly(treatment.treated_on),
        product_name: treatment.product_name,
        registration_number: treatment.registration_number ?? '',
        active_substance: treatment.active_substance ?? '',
        vegetation: treatment.vegetation ?? '',
        adversity: treatment.adversity,
        method: treatment.method,
        quantity: Number(treatment.quantity),
        unit: treatment.unit,
        water_volume_l: treatment.water_volume_l !== null ? Number(treatment.water_volume_l) : null,
        surface_sqm: treatment.surface_sqm !== null ? Number(treatment.surface_sqm) : null,
        reentry_hours: treatment.reentry_hours,
        operator_id: treatment.operator_id ?? '',
        notes: treatment.notes ?? '',
    });
    drawer.mode = 'edit';
    drawer.id = treatment.id;
    drawer.version = treatment.version;
    drawer.error = '';
    formSnapshot = JSON.stringify(form);
    drawer.open = true;
}

function payload() {
    return {
        area_id: form.area_id,
        asset_id: form.asset_id || null,
        treated_on: form.treated_on,
        product_name: form.product_name.trim(),
        registration_number: form.registration_number.trim() || null,
        active_substance: form.active_substance.trim() || null,
        vegetation: form.vegetation.trim(),
        adversity: form.adversity.trim(),
        method: form.method,
        quantity: form.quantity,
        unit: form.unit,
        water_volume_l: form.water_volume_l === '' ? null : form.water_volume_l,
        surface_sqm: form.surface_sqm === '' ? null : form.surface_sqm,
        reentry_hours: form.reentry_hours === '' ? null : form.reentry_hours,
        operator_id: form.operator_id || null,
        notes: form.notes.trim() || null,
    };
}

async function save() {
    drawer.busy = true;
    drawer.error = '';
    try {
        if (drawer.mode === 'new') {
            await axios.post('/api/v1/phyto-treatments', payload());
        } else {
            await axios.patch(`/api/v1/phyto-treatments/${drawer.id}`, {
                ...payload(), version: drawer.version,
            });
        }
        drawer.open = false;
        await load();
    } catch (err) {
        drawer.error = err.response?.status === 409
            ? 'Un altro utente ha modificato questo trattamento: chiudi e riapri il dettaglio.'
            : firstError(err, 'Salvataggio non riuscito');
    } finally {
        drawer.busy = false;
    }
}

async function destroyTreatment() {
    if (! window.confirm('Eliminare questo trattamento dal registro?')) return;
    drawer.busy = true;
    drawer.error = '';
    try {
        await axios.delete(`/api/v1/phyto-treatments/${drawer.id}`);
        drawer.open = false;
        await load();
    } catch (err) {
        drawer.error = firstError(err, 'Eliminazione non riuscita');
    } finally {
        drawer.busy = false;
    }
}

async function openRegisterPdf() {
    pageError.value = '';
    const params = new URLSearchParams({ year: String(filters.year) });
    if (filters.area_id) params.set('area_id', filters.area_id);
    const res = await fetchPdf(`/api/v1/phyto-treatments/register-pdf?${params}`);
    if (res?.error) pageError.value = res.error;
}

const formValid = computed(() => form.area_id && form.treated_on
    && form.product_name?.trim() && form.vegetation?.trim()
    && form.adversity?.trim() && form.quantity > 0);

watch(() => [filters.year, filters.area_id], load);

onMounted(() => {
    load();
    loadLookups();
});
</script>

<template>
    <Head title="Fitosanitari" />
    <AppLayout>
        <div class="mx-auto max-w-7xl px-4 py-6">
            <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold">Registro dei trattamenti fitosanitari</h1>
                    <p class="text-sm text-gray-500">Quaderno di campagna: prodotti, dosi, avversità e tempi di rientro per area</p>
                </div>
                <div class="flex items-center gap-2">
                    <button
                        class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                        data-test="fito-pdf"
                        @click="openRegisterPdf"
                    >Registro PDF {{ filters.year }}</button>
                    <button
                        v-if="canManage"
                        class="rounded-lg bg-green-700 px-4 py-2 text-sm font-medium text-white hover:bg-green-800"
                        data-test="fito-new"
                        @click="openCreator"
                    >Nuovo trattamento</button>
                </div>
            </div>

            <div class="mb-3 flex flex-wrap gap-2">
                <select v-model="filters.year" data-test="fito-year" class="rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
                    <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
                </select>
                <ScegliVoce v-model="filters.area_id" data-test="fito-filter-area" class="w-full sm:w-56" campo-classe="px-2.5 py-1.5 text-sm" :voci="areas" :campi-ricerca="['name', 'code']" tutti="Tutte le aree" vuoto="Nessuna area trovata." />
            </div>

            <p v-if="pageError" class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ pageError }}</p>
            <p v-if="total > treatments.length" class="mb-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800" data-test="fito-parziale">
                Elenco parziale: a video i {{ treatments.length }} trattamenti più recenti su {{ total }}.
                Restringi i filtri; il Registro PDF contiene comunque tutto.
            </p>

            <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wide text-gray-400">
                            <th class="px-4 py-2.5 font-medium">Data</th>
                            <th class="px-4 py-2.5 font-medium">Area / elemento</th>
                            <th class="px-4 py-2.5 font-medium">Prodotto</th>
                            <th class="px-4 py-2.5 font-medium">Avversità</th>
                            <th class="px-4 py-2.5 font-medium">Metodo</th>
                            <th class="px-4 py-2.5 text-right font-medium">Quantità</th>
                            <th class="px-4 py-2.5 text-right font-medium">Rientro (h)</th>
                            <th class="px-4 py-2.5 font-medium">Operatore</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        <tr
                            v-for="t in treatments"
                            :key="t.id"
                            class="cursor-pointer hover:bg-green-50/40"
                            data-test="fito-row"
                            @click="openDetail(t)"
                        >
                            <td class="px-4 py-2">{{ formatDate(t.treated_on) }}</td>
                            <td class="px-4 py-2">
                                {{ t.area?.name }}<template v-if="t.asset"> - {{ t.asset.census_code }}</template>
                                <span v-if="t.vegetation" class="block text-xs text-gray-500">{{ t.vegetation }}</span>
                            </td>
                            <td class="px-4 py-2">
                                {{ t.product_name }}
                                <span v-if="t.active_substance" class="text-xs text-gray-500">({{ t.active_substance }})</span>
                            </td>
                            <td class="px-4 py-2">{{ t.adversity }}</td>
                            <td class="px-4 py-2 text-gray-600">{{ METHOD_LABELS[t.method] ?? t.method }}</td>
                            <td class="px-4 py-2 text-right">{{ fmtQty(t) }}</td>
                            <td class="px-4 py-2 text-right">{{ t.reentry_hours ?? '—' }}</td>
                            <td class="px-4 py-2 text-gray-600">{{ t.operator?.name ?? '—' }}</td>
                        </tr>
                        <tr v-if="! treatments.length && ! loading && ! pageError">
                            <td colspan="8" class="px-4 py-8 text-center text-gray-400">
                                Nessun trattamento registrato nel {{ filters.year }}.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Teleport to="body">
                <div v-if="drawer.open" class="fixed inset-0 z-50 flex justify-end bg-black/30" @click.self="closeDrawer">
                    <div class="h-full w-full max-w-xl overflow-y-auto bg-white p-6 shadow-2xl" data-test="fito-drawer">
                        <div class="flex items-start justify-between">
                            <h2 class="font-semibold">{{ drawer.mode === 'new' ? 'Nuovo trattamento' : 'Trattamento del ' + formatDate(form.treated_on) }}</h2>
                            <button class="text-gray-400 hover:text-gray-600" @click="closeDrawer">✕</button>
                        </div>

                        <p v-if="drawer.error" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" data-test="fito-error">{{ drawer.error }}</p>

                        <div class="mt-4 grid grid-cols-2 gap-3">
                            <label class="col-span-2 block text-xs">
                                <span class="text-gray-500">Area *</span>
                                <ScegliVoce v-model="form.area_id" data-test="fito-area" class="mt-1 w-full" campo-classe="px-2.5 py-2 text-sm" :voci="areas" :campi-ricerca="['name', 'code']" :disabilitato="! canManage" vuoto="Nessuna area trovata." />
                            </label>
                            <label class="col-span-2 block text-xs">
                                <span class="text-gray-500">Elemento censito (facoltativo, es. albero in endoterapia)</span>
                                <ScegliVoce v-model="form.asset_id" data-test="fito-asset" class="mt-1 w-full" campo-classe="px-2.5 py-2 text-sm" campo-nome="census_code" :voci="alberiVoci" :campi-ricerca="['census_code']" :disabilitato="! canManage || ! form.area_id" tutti="Tutta l'area" vuoto="Nessun elemento trovato." />
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Data del trattamento *</span>
                                <input v-model="form.treated_on" type="date" :max="todayLocal()" data-test="fito-data" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm" :disabled="! canManage">
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Metodo *</span>
                                <select v-model="form.method" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm" :disabled="! canManage">
                                    <option v-for="(label, value) in METHOD_LABELS" :key="value" :value="value">{{ label }}</option>
                                </select>
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Prodotto (nome commerciale) *</span>
                                <input v-model="form.product_name" maxlength="200" data-test="fito-prodotto" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm" :disabled="! canManage">
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">N. registrazione</span>
                                <input v-model="form.registration_number" maxlength="50" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm" :disabled="! canManage">
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Principio attivo</span>
                                <input v-model="form.active_substance" maxlength="200" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm" :disabled="! canManage">
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Coltura / vegetazione trattata *</span>
                                <input v-model="form.vegetation" maxlength="200" data-test="fito-vegetazione" placeholder="es. tappeto erboso, tigli in filare" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm" :disabled="! canManage">
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Avversità *</span>
                                <input v-model="form.adversity" maxlength="200" data-test="fito-avversita" placeholder="es. processionaria del pino" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm" :disabled="! canManage">
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Quantità di prodotto *</span>
                                <div class="mt-1 flex gap-1">
                                    <input v-model.number="form.quantity" type="number" step="0.001" min="0.001" data-test="fito-quantita" class="w-full rounded-lg border border-gray-300 px-2.5 py-2 text-right text-sm" :disabled="! canManage">
                                    <select v-model="form.unit" class="rounded-lg border border-gray-300 px-2 py-2 text-sm" :disabled="! canManage">
                                        <option v-for="u in UNITS" :key="u" :value="u">{{ u }}</option>
                                    </select>
                                </div>
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Volume di acqua (litri)</span>
                                <input v-model.number="form.water_volume_l" type="number" step="0.1" min="0" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-right text-sm" :disabled="! canManage">
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Superficie trattata (mq)</span>
                                <input v-model.number="form.surface_sqm" type="number" step="0.01" min="0" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-right text-sm" :disabled="! canManage">
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Tempo di rientro (ore)</span>
                                <input v-model.number="form.reentry_hours" type="number" step="1" min="0" max="720" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-right text-sm" :disabled="! canManage">
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Eseguito da</span>
                                <!-- L'elenco del personale richiede works.manage: chi consulta
                                     in sola lettura vede il nome, non una tendina vuota -->
                                <select v-if="canManage" v-model="form.operator_id" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm">
                                    <option value="">—</option>
                                    <option v-for="p in personnel" :key="p.id" :value="p.id">{{ p.name }}</option>
                                </select>
                                <input v-else :value="loaded.operatorName || '—'" disabled class="mt-1 w-full rounded-lg border border-gray-300 bg-gray-50 px-2.5 py-2 text-sm">
                            </label>
                            <label class="col-span-2 block text-xs">
                                <span class="text-gray-500">Note (condizioni meteo, attrezzatura, prescrizioni)</span>
                                <textarea v-model="form.notes" rows="3" maxlength="2000" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm" :disabled="! canManage" />
                            </label>
                        </div>

                        <div v-if="canManage" class="mt-5 flex items-center justify-between">
                            <button
                                v-if="drawer.mode === 'edit'"
                                class="text-sm font-medium text-red-600 hover:underline disabled:opacity-50"
                                :disabled="drawer.busy"
                                data-test="fito-delete"
                                @click="destroyTreatment"
                            >Elimina dal registro</button>
                            <span v-else />
                            <button
                                class="rounded-lg bg-green-700 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50"
                                :disabled="drawer.busy || ! formValid"
                                data-test="fito-save"
                                @click="save"
                            >{{ drawer.busy ? 'Salvataggio…' : (drawer.mode === 'new' ? 'Registra trattamento' : 'Salva le modifiche') }}</button>
                        </div>
                    </div>
                </div>
            </Teleport>
        </div>
    </AppLayout>
</template>
