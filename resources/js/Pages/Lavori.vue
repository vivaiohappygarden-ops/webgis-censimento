<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import WorkAgenda from '@/Components/WorkAgenda.vue';
import WorkReport from '@/Components/WorkReport.vue';

const page = usePage();
const canManage = computed(() => (page.props.auth?.user?.permissions ?? []).includes('works.manage'));

const STATUS_LABELS = {
    draft: 'Bozza',
    planned: 'Pianificato',
    assigned: 'Assegnato',
    in_progress: 'In corso',
    suspended: 'Sospeso',
    completed: 'Completato',
    cancelled: 'Annullato',
};
const STATUS_COLORS = {
    draft: 'bg-gray-100 text-gray-700',
    planned: 'bg-blue-100 text-blue-800',
    assigned: 'bg-indigo-100 text-indigo-800',
    in_progress: 'bg-amber-100 text-amber-800',
    suspended: 'bg-orange-100 text-orange-800',
    completed: 'bg-green-100 text-green-800',
    cancelled: 'bg-gray-200 text-gray-500',
};
const PRIORITY_LABELS = { low: 'Bassa', normal: 'Normale', high: 'Alta', urgent: 'Urgente' };

const rows = ref([]);
const meta = reactive({ total: 0, current_page: 1, last_page: 1 });
const filters = reactive({ status: '', q: '', page: 1 });
const loading = ref(false);
const view = ref('elenco');
const agendaRef = ref(null);
const reportRef = ref(null);

const teams = ref([]);
const personnel = ref([]);
const workTypes = ref([]);
const clients = ref([]);
const areas = ref([]);
const priceLists = ref([]);

const creator = reactive({ open: false, busy: false, error: '', form: {} });
const detail = ref(null);
const detailBusy = ref(false);
const detailError = ref('');
const assetSearch = reactive({ q: '', results: [], adding: false });

function resetForm() {
    creator.form = {
        title: '', description: '', client_id: '', area_id: '', work_type_id: '',
        priority: 'normal', planned_start: '', planned_end: '', team_id: '', assigned_to: '',
        price_list_id: '',
    };
}
resetForm();

async function load() {
    loading.value = true;
    try {
        const { data } = await axios.get('/api/v1/work-orders', {
            params: {
                page: filters.page,
                status: filters.status || undefined,
                q: filters.q || undefined,
            },
        });
        rows.value = data.data;
        Object.assign(meta, { total: data.total, current_page: data.current_page, last_page: data.last_page });
    } finally {
        loading.value = false;
    }
}

async function loadLookups() {
    const requests = [
        axios.get('/api/v1/teams'),
        axios.get('/api/v1/work-types'),
        axios.get('/api/v1/price-lists'),
    ];
    if (canManage.value) {
        requests.push(axios.get('/api/v1/personnel'));
        requests.push(axios.get('/api/v1/clients', { params: { per_page: 100 } }));
        requests.push(axios.get('/api/v1/areas', { params: { per_page: 100 } }));
    }
    const [t, wt, pl, p, c, a] = await Promise.all(requests);
    teams.value = t.data.data;
    workTypes.value = wt.data.data;
    priceLists.value = pl.data.data;
    if (p) personnel.value = p.data.data;
    if (c) clients.value = c.data.data;
    if (a) areas.value = a.data.data;
}

async function createOrder() {
    creator.busy = true;
    creator.error = '';
    try {
        const payload = Object.fromEntries(
            Object.entries(creator.form).filter(([, v]) => v !== '' && v !== null),
        );
        const { data } = await axios.post('/api/v1/work-orders', payload);
        creator.open = false;
        resetForm();
        await load();
        await agendaRef.value?.reload();
        await openDetail(data.data.id);
    } catch (err) {
        creator.error = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
            ?? err.response?.data?.message ?? 'Errore nella creazione';
    } finally {
        creator.busy = false;
    }
}

async function openDetail(id) {
    try {
        const { data } = await axios.get(`/api/v1/work-orders/${id}`);
        detail.value = data.data;
        detailError.value = '';
        assetSearch.q = '';
        assetSearch.results = [];
    } catch {
        detailError.value = 'Ordine non trovato o non accessibile.';
    }
}

async function doTransition(status) {
    detailBusy.value = true;
    detailError.value = '';
    try {
        const { data } = await axios.post(`/api/v1/work-orders/${detail.value.id}/transition`, {
            status,
            version: detail.value.version,
        });
        detail.value = data.data;
        await load();
        // Agenda e rendiconto, se aperti, devono riflettere subito il nuovo stato
        await agendaRef.value?.reload();
        await reportRef.value?.reload();
    } catch (err) {
        detailError.value = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
            ?? err.response?.data?.message ?? 'Errore nel cambio di stato';
    } finally {
        detailBusy.value = false;
    }
}

let searchDebounce = null;
function searchAssets() {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(async () => {
        if (assetSearch.q.trim().length < 2) {
            assetSearch.results = [];
            return;
        }
        const { data } = await axios.get('/api/v1/assets', { params: { q: assetSearch.q, per_page: 8 } });
        assetSearch.results = data.data;
    }, 250);
}

async function attachAsset(asset) {
    assetSearch.adding = true;
    detailError.value = '';
    try {
        const { data } = await axios.post(`/api/v1/work-orders/${detail.value.id}/assets`, {
            asset_id: asset.id,
        });
        detail.value = data.data;
        assetSearch.q = '';
        assetSearch.results = [];
        await load();
    } catch (err) {
        detailError.value = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
            ?? err.response?.data?.message ?? 'Errore nell\'aggiunta';
    } finally {
        assetSearch.adding = false;
    }
}

async function detachAsset(rowId) {
    detailError.value = '';
    try {
        const { data } = await axios.delete(`/api/v1/work-orders/${detail.value.id}/assets/${rowId}`);
        detail.value = data.data;
        await load();
    } catch (err) {
        detailError.value = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
            ?? err.response?.data?.message ?? 'Errore nella rimozione';
    }
}

const fmt = (d) => (d ? new Date(d).toLocaleDateString('it-IT') : '—');
const fmtEuro = (v) => (v == null ? '—' : Number(v).toLocaleString('it-IT', { style: 'currency', currency: 'EUR' }));
const fmtDateTime = (iso) => (iso ? new Date(iso).toLocaleString('it-IT', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '—');
const fmtQty = (v) => Number(v).toLocaleString('it-IT');

async function setPriceList(priceListId) {
    detailBusy.value = true;
    detailError.value = '';
    try {
        const { data } = await axios.patch(`/api/v1/work-orders/${detail.value.id}`, {
            price_list_id: priceListId || null,
            version: detail.value.version,
        });
        detail.value = data.data;
    } catch (err) {
        const message = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
            ?? err.response?.data?.message ?? 'Errore nel cambio di listino';
        // Su conflitto di versione si ricarica l'ordine (il prossimo tentativo
        // vale), ma il messaggio va rimesso DOPO: openDetail lo azzererebbe
        if (err.response?.status === 409) await openDetail(detail.value.id);
        detailError.value = message;
    } finally {
        detailBusy.value = false;
    }
}

onMounted(async () => {
    await Promise.all([load(), loadLookups()]);
});
</script>

<template>
    <Head title="Lavori" />

    <AppLayout>
        <div class="p-6">
            <div class="mb-4 flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-semibold">Ordini di lavoro</h1>
                    <!-- Il conteggio segue i filtri dell'elenco: in agenda sarebbe fuorviante -->
                    <p v-if="view === 'elenco'" class="text-sm text-gray-500">{{ meta.total }} {{ meta.total === 1 ? 'ordine' : 'ordini' }} · flusso: bozza, pianificato, assegnato, in corso, completato</p>
                    <p v-else-if="view === 'agenda'" class="text-sm text-gray-500">Programmazione settimanale per squadra</p>
                    <p v-else class="text-sm text-gray-500">Lavori completati per cliente e periodo, con importi da listino</p>
                </div>
                <button
                    v-if="canManage"
                    class="rounded-lg bg-green-700 px-4 py-2 text-sm font-medium text-white hover:bg-green-800"
                    data-test="new-wo"
                    @click="creator.open = true"
                >Nuovo ordine</button>
            </div>

            <!-- Selettore vista: elenco tabellare o agenda settimanale -->
            <div class="mb-3 inline-flex overflow-hidden rounded-lg border border-gray-300 text-sm">
                <button
                    class="px-4 py-1.5 font-medium"
                    :class="view === 'elenco' ? 'bg-green-700 text-white' : 'bg-white text-gray-600 hover:bg-gray-50'"
                    data-test="view-elenco"
                    @click="view = 'elenco'"
                >Elenco</button>
                <button
                    class="border-l border-gray-300 px-4 py-1.5 font-medium"
                    :class="view === 'agenda' ? 'bg-green-700 text-white' : 'bg-white text-gray-600 hover:bg-gray-50'"
                    data-test="view-agenda"
                    @click="view = 'agenda'"
                >Agenda</button>
                <button
                    class="border-l border-gray-300 px-4 py-1.5 font-medium"
                    :class="view === 'rendiconto' ? 'bg-green-700 text-white' : 'bg-white text-gray-600 hover:bg-gray-50'"
                    data-test="view-rendiconto"
                    @click="view = 'rendiconto'"
                >Rendiconto</button>
            </div>

            <WorkAgenda
                v-if="view === 'agenda'"
                ref="agendaRef"
                :teams="teams"
                :personnel="personnel"
                :can-manage="canManage"
                @open="openDetail"
            />

            <WorkReport
                v-if="view === 'rendiconto'"
                ref="reportRef"
                :clients="clients"
                @open="openDetail"
            />

            <div v-if="view === 'elenco'" class="mb-3 flex flex-wrap gap-2">
                <select v-model="filters.status" class="rounded-lg border border-gray-300 px-2.5 py-2 text-sm" @change="filters.page = 1; load()">
                    <option value="">Tutti gli stati</option>
                    <option v-for="(label, value) in STATUS_LABELS" :key="value" :value="value">{{ label }}</option>
                </select>
                <input
                    v-model="filters.q"
                    placeholder="Cerca per codice o titolo…"
                    class="w-64 rounded-lg border border-gray-300 px-2.5 py-2 text-sm"
                    @input="filters.page = 1; load()"
                >
            </div>

            <div v-if="view === 'elenco'" class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wide text-gray-400">
                            <th class="px-4 py-2.5 font-medium">Codice</th>
                            <th class="px-4 py-2.5 font-medium">Titolo</th>
                            <th class="px-4 py-2.5 font-medium">Stato</th>
                            <th class="px-4 py-2.5 font-medium">Priorità</th>
                            <th class="px-4 py-2.5 font-medium">Squadra / responsabile</th>
                            <th class="px-4 py-2.5 font-medium">Periodo</th>
                            <th class="px-4 py-2.5 text-right font-medium">Elementi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        <tr
                            v-for="row in rows"
                            :key="row.id"
                            class="cursor-pointer hover:bg-green-50/40"
                            @click="openDetail(row.id)"
                        >
                            <td class="px-4 py-2 font-medium">{{ row.code }}</td>
                            <td class="max-w-xs truncate px-4 py-2">{{ row.title }}</td>
                            <td class="px-4 py-2">
                                <span class="rounded-full px-2.5 py-0.5 text-xs font-medium" :class="STATUS_COLORS[row.status]">
                                    {{ STATUS_LABELS[row.status] ?? row.status }}
                                </span>
                            </td>
                            <td class="px-4 py-2 text-gray-600">{{ PRIORITY_LABELS[row.priority] }}</td>
                            <td class="px-4 py-2 text-gray-600">{{ row.team?.name || row.assignee?.name || '—' }}</td>
                            <td class="px-4 py-2 text-gray-500">{{ fmt(row.planned_start) }} – {{ fmt(row.planned_end) }}</td>
                            <td class="px-4 py-2 text-right">{{ row.assets_count }}</td>
                        </tr>
                        <tr v-if="! rows.length && ! loading">
                            <td colspan="7" class="px-4 py-8 text-center text-gray-400">Nessun ordine di lavoro.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="view === 'elenco' && meta.last_page > 1" class="mt-3 flex items-center justify-between text-sm">
                <button class="rounded-lg border border-gray-300 px-3 py-1.5 disabled:opacity-40" :disabled="meta.current_page <= 1" @click="filters.page -= 1; load()">← Precedente</button>
                <span class="text-gray-500">Pagina {{ meta.current_page }} di {{ meta.last_page }}</span>
                <button class="rounded-lg border border-gray-300 px-3 py-1.5 disabled:opacity-40" :disabled="meta.current_page >= meta.last_page" @click="filters.page += 1; load()">Successiva →</button>
            </div>

            <!-- Creazione -->
            <Teleport to="body">
                <div v-if="creator.open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4" @click.self="creator.open = false">
                    <div class="w-full max-w-2xl rounded-2xl bg-white p-6 shadow-2xl">
                        <div class="flex items-start justify-between">
                            <h2 class="font-semibold">Nuovo ordine di lavoro</h2>
                            <button class="text-gray-400 hover:text-gray-600" @click="creator.open = false">✕</button>
                        </div>

                        <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2">
                            <label class="col-span-full block text-xs">
                                <span class="text-gray-500">Titolo *</span>
                                <input v-model="creator.form.title" data-test="wo-title" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm" placeholder="es. Potatura filare via Roma">
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Lavorazione</span>
                                <select v-model="creator.form.work_type_id" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-2 text-sm">
                                    <option value="">—</option>
                                    <option v-for="w in workTypes" :key="w.id" :value="w.id">{{ w.name }} ({{ w.unit }})</option>
                                </select>
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Priorità</span>
                                <select v-model="creator.form.priority" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-2 text-sm">
                                    <option v-for="(label, value) in PRIORITY_LABELS" :key="value" :value="value">{{ label }}</option>
                                </select>
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Cliente</span>
                                <select v-model="creator.form.client_id" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-2 text-sm">
                                    <option value="">—</option>
                                    <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option>
                                </select>
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Area</span>
                                <select v-model="creator.form.area_id" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-2 text-sm">
                                    <option value="">—</option>
                                    <option v-for="a in areas" :key="a.id" :value="a.id">{{ a.name }}</option>
                                </select>
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Inizio previsto</span>
                                <input v-model="creator.form.planned_start" type="date" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm">
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Fine prevista</span>
                                <input v-model="creator.form.planned_end" type="date" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm">
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Squadra</span>
                                <select v-model="creator.form.team_id" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-2 text-sm">
                                    <option value="">—</option>
                                    <option v-for="t in teams" :key="t.id" :value="t.id">{{ t.name }}</option>
                                </select>
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Responsabile</span>
                                <select v-model="creator.form.assigned_to" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-2 text-sm">
                                    <option value="">—</option>
                                    <option v-for="p in personnel" :key="p.id" :value="p.id">{{ p.name }}</option>
                                </select>
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Listino prezzi</span>
                                <select v-model="creator.form.price_list_id" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-2 text-sm">
                                    <option value="">—</option>
                                    <option v-for="l in priceLists" :key="l.id" :value="l.id">{{ l.code }} — {{ l.name }}</option>
                                </select>
                            </label>
                            <label class="col-span-full block text-xs">
                                <span class="text-gray-500">Descrizione</span>
                                <textarea v-model="creator.form.description" rows="2" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm" />
                            </label>
                        </div>

                        <p v-if="creator.error" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ creator.error }}</p>

                        <button
                            class="mt-4 w-full rounded-lg bg-green-700 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50"
                            :disabled="creator.busy || ! creator.form.title.trim()"
                            data-test="wo-save"
                            @click="createOrder"
                        >{{ creator.busy ? 'Creazione…' : 'Crea ordine (in bozza)' }}</button>
                    </div>
                </div>
            </Teleport>

            <!-- Dettaglio -->
            <Teleport to="body">
                <div v-if="detail" class="fixed inset-0 z-50 flex justify-end bg-black/30" @click.self="detail = null">
                    <div class="h-full w-full max-w-xl overflow-y-auto bg-white p-6 shadow-2xl" data-test="wo-detail">
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="text-xs uppercase tracking-wide text-gray-400">{{ detail.code }}</div>
                                <h2 class="text-lg font-semibold">{{ detail.title }}</h2>
                            </div>
                            <button class="text-gray-400 hover:text-gray-600" @click="detail = null">✕</button>
                        </div>

                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <span class="rounded-full px-3 py-1 text-xs font-medium" :class="STATUS_COLORS[detail.status]" data-test="wo-status">
                                {{ STATUS_LABELS[detail.status] ?? detail.status }}
                            </span>
                            <span class="text-xs text-gray-500">Priorità: {{ PRIORITY_LABELS[detail.priority] }}</span>
                            <span class="text-xs text-gray-400">v{{ detail.version }}</span>
                        </div>

                        <div v-if="canManage && detail.allowed_transitions.length" class="mt-3 flex flex-wrap gap-2">
                            <button
                                v-for="s in detail.allowed_transitions"
                                :key="s"
                                class="rounded-lg border border-green-700 px-3 py-1.5 text-xs font-medium text-green-700 hover:bg-green-50 disabled:opacity-50"
                                :disabled="detailBusy"
                                :data-test="`wo-to-${s}`"
                                @click="doTransition(s)"
                            >{{ STATUS_LABELS[s] }}</button>
                        </div>
                        <p v-if="detailError" class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" data-test="wo-error">{{ detailError }}</p>

                        <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-1.5 text-sm">
                            <div class="flex justify-between gap-3 border-b border-gray-50 py-1"><dt class="text-gray-500">Lavorazione</dt><dd>{{ detail.work_type?.name || '—' }}</dd></div>
                            <div class="flex justify-between gap-3 border-b border-gray-50 py-1"><dt class="text-gray-500">Cliente</dt><dd>{{ detail.client?.name || '—' }}</dd></div>
                            <div class="flex justify-between gap-3 border-b border-gray-50 py-1"><dt class="text-gray-500">Area</dt><dd>{{ detail.area?.name || '—' }}</dd></div>
                            <div class="flex justify-between gap-3 border-b border-gray-50 py-1"><dt class="text-gray-500">Squadra</dt><dd>{{ detail.team?.name || '—' }}</dd></div>
                            <div class="flex justify-between gap-3 border-b border-gray-50 py-1"><dt class="text-gray-500">Responsabile</dt><dd>{{ detail.assignee?.name || '—' }}</dd></div>
                            <div class="flex justify-between gap-3 border-b border-gray-50 py-1"><dt class="text-gray-500">Periodo</dt><dd>{{ fmt(detail.planned_start) }} – {{ fmt(detail.planned_end) }}</dd></div>
                            <div class="flex justify-between gap-3 border-b border-gray-50 py-1"><dt class="text-gray-500">Listino</dt><dd>{{ detail.price_list?.name || '—' }}</dd></div>
                        </dl>

                        <p v-if="detail.description" class="mt-3 whitespace-pre-line rounded-lg bg-gray-50 p-3 text-sm text-gray-700">{{ detail.description }}</p>

                        <h3 class="mt-5 text-sm font-semibold">Elementi ({{ detail.assets.length }})</h3>
                        <ul class="mt-2 divide-y divide-gray-50 rounded-lg border border-gray-100">
                            <li v-for="row in detail.assets" :key="row.id" class="flex items-center gap-3 px-3 py-2 text-sm">
                                <div class="min-w-0 flex-1">
                                    <span class="font-medium">{{ row.asset?.census_code || row.asset_id.slice(0, 8) }}</span>
                                    <span class="ml-2 truncate text-xs text-gray-500">{{ row.asset?.object_type?.name }}</span>
                                </div>
                                <span v-if="row.work_type" class="text-xs text-gray-500">{{ row.work_type.name }}</span>
                                <button
                                    v-if="canManage"
                                    class="text-xs font-medium text-red-600 hover:underline"
                                    @click="detachAsset(row.id)"
                                >Rimuovi</button>
                            </li>
                            <li v-if="! detail.assets.length" class="px-3 py-3 text-sm text-gray-400">Nessun elemento collegato.</li>
                        </ul>

                        <!-- Consuntivi ed economia -->
                        <h3 class="mt-5 text-sm font-semibold">Consuntivi ({{ (detail.logs ?? []).length }})</h3>
                        <div v-if="canManage" class="mt-2 flex items-center gap-2 text-xs">
                            <span class="text-gray-500">Listino applicato</span>
                            <select
                                :value="detail.price_list_id ?? ''"
                                data-test="wo-price-list"
                                class="rounded-lg border border-gray-300 px-2 py-1.5 text-sm disabled:bg-gray-50"
                                :disabled="detailBusy || detail.status === 'completed' || detail.status === 'cancelled'"
                                @change="setPriceList($event.target.value)"
                            >
                                <option value="">Nessuno</option>
                                <option v-for="l in priceLists" :key="l.id" :value="l.id">{{ l.code }} — {{ l.name }}</option>
                            </select>
                            <span v-if="detail.status === 'completed' || detail.status === 'cancelled'" class="text-gray-400">
                                (ordine chiuso: il listino non si cambia più)
                            </span>
                        </div>
                        <table v-if="(detail.logs ?? []).length" class="mt-2 w-full text-sm" data-test="wo-logs">
                            <thead>
                                <tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wide text-gray-400">
                                    <th class="py-1.5 pr-2 font-medium">Inizio</th>
                                    <th class="py-1.5 pr-2 font-medium">Operatore</th>
                                    <th class="py-1.5 pr-2 text-right font-medium">Ore</th>
                                    <th class="py-1.5 pr-2 text-right font-medium">Quantità</th>
                                    <th class="py-1.5 font-medium">Note</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                <tr v-for="log in detail.logs" :key="log.id">
                                    <td class="py-1.5 pr-2 text-gray-600">{{ fmtDateTime(log.started_at) }}</td>
                                    <td class="py-1.5 pr-2">{{ log.operator?.name ?? '—' }}</td>
                                    <td class="py-1.5 pr-2 text-right">{{ log.man_hours != null ? fmtQty(log.man_hours) : '—' }}</td>
                                    <td class="py-1.5 pr-2 text-right">
                                        {{ log.quantity != null ? `${fmtQty(log.quantity)} ${log.unit ?? ''}` : '—' }}
                                    </td>
                                    <td class="max-w-40 truncate py-1.5 text-xs text-gray-500" :title="log.notes ?? ''">{{ log.notes ?? '' }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <p v-else class="mt-2 text-sm text-gray-400">Nessun consuntivo registrato dal campo.</p>

                        <div v-if="detail.consuntivo" class="mt-3 rounded-lg bg-gray-50 p-3 text-sm" data-test="wo-economia">
                            <div class="flex justify-between">
                                <span class="text-gray-500">Ore uomo totali</span>
                                <span class="font-medium">{{ fmtQty(detail.consuntivo.total_man_hours) }}</span>
                            </div>
                            <div v-for="(qty, unit) in detail.consuntivo.quantities" :key="unit" class="mt-1 flex justify-between">
                                <span class="text-gray-500">Quantità eseguita ({{ unit || 'senza unità' }})</span>
                                <span class="font-medium">{{ fmtQty(qty) }}</span>
                            </div>
                            <div v-if="detail.consuntivo.valued && ! detail.consuntivo.valued.ambiguous" class="mt-2 flex justify-between gap-3 border-t border-gray-200 pt-2">
                                <span class="text-gray-500">
                                    Valore da listino
                                    ({{ fmtQty(detail.consuntivo.valued.quantity ?? 0) }} {{ detail.consuntivo.valued.unit }}
                                    × {{ fmtEuro(detail.consuntivo.valued.unit_price) }}<template v-if="detail.consuntivo.valued.overhead_pct"> + spese generali {{ fmtQty(detail.consuntivo.valued.overhead_pct) }}%</template><template v-if="detail.consuntivo.valued.safety_cost"> + sicurezza {{ fmtEuro(detail.consuntivo.valued.safety_cost) }}</template>)
                                </span>
                                <span class="font-semibold" data-test="wo-valore">{{ fmtEuro(detail.consuntivo.valued.amount) }}</span>
                            </div>
                            <p v-else-if="detail.consuntivo.valued?.ambiguous" class="mt-2 border-t border-gray-200 pt-2 text-xs text-amber-700" data-test="wo-valore-ambiguo">
                                {{ detail.consuntivo.valued.reason }}
                            </p>
                            <p v-else-if="! detail.price_list_id" class="mt-2 border-t border-gray-200 pt-2 text-xs text-gray-400">
                                Nessun listino applicato: il valore economico non viene calcolato.
                            </p>
                            <p v-else class="mt-2 border-t border-gray-200 pt-2 text-xs text-gray-400">
                                Il listino non ha una voce per la lavorazione di questo ordine.
                            </p>
                        </div>

                        <div v-if="canManage" class="mt-3">
                            <input
                                v-model="assetSearch.q"
                                placeholder="Aggiungi elemento: cerca per codice censimento…"
                                class="w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm"
                                data-test="wo-asset-search"
                                @input="searchAssets"
                            >
                            <ul v-if="assetSearch.results.length" class="mt-1 divide-y divide-gray-50 rounded-lg border border-gray-200 bg-white shadow">
                                <li
                                    v-for="a in assetSearch.results"
                                    :key="a.id"
                                    class="flex cursor-pointer items-center gap-2 px-3 py-2 text-sm hover:bg-green-50"
                                    @click="attachAsset(a)"
                                >
                                    <span class="font-medium">{{ a.census_code || a.id.slice(0, 8) }}</span>
                                    <span class="truncate text-xs text-gray-500">{{ a.object_type?.name }}</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </Teleport>
        </div>
    </AppLayout>
</template>
