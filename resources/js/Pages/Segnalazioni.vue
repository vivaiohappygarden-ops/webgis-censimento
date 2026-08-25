<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import AvvisoErrore from '@/Components/AvvisoErrore.vue';
import VisteSalvate from '@/Components/VisteSalvate.vue';
import { usaCaricamento } from '@/caricamento';

const page = usePage();
// Un errore di caricamento va detto, non lasciato indovinare da un elenco vuoto
const { avviso, riprovaInCorso, carica, riprova } = usaCaricamento();
const canManage = computed(() => (page.props.auth?.user?.permissions ?? []).includes('works.manage'));

const STATUS = {
    open: { label: 'Aperta', cls: 'bg-red-100 text-red-800' },
    in_charge: { label: 'In carico', cls: 'bg-amber-100 text-amber-800' },
    resolved: { label: 'Risolta', cls: 'bg-green-100 text-green-800' },
    dismissed: { label: 'Archiviata', cls: 'bg-gray-200 text-gray-600' },
};
const SEVERITY = { low: 'Bassa', medium: 'Media', high: 'Alta', critical: 'Critica' };

const rows = ref([]);
const meta = reactive({ total: 0, current_page: 1, last_page: 1 });
const filters = reactive({ status: '', severity: '', sla: '', q: '', page: 1 });

// --- Viste salvate ----------------------------------------------------------
const filtriCorrenti = computed(() => ({
    status: filters.status, severity: filters.severity, sla: filters.sla, q: filters.q,
}));

function applicaVista(filtri) {
    filters.status = filtri.status ?? '';
    filters.severity = filtri.severity ?? '';
    filters.sla = filtri.sla ?? '';
    filters.q = filtri.q ?? '';
    filters.page = 1;
    carica(load);
}
const loading = ref(false);
const loadError = ref(false);
const areas = ref([]);

const creator = reactive({
    open: false, busy: false, error: '',
    form: {
        description: '', severity: 'medium', reporter_type: 'internal',
        reporter_name: '', reporter_contact: '', category: '', area_id: '', asset_id: '',
    },
});
const assetSearch = reactive({ q: '', results: [], selectedLabel: '' });

const detail = ref(null);
const detailBusy = ref(false);
const detailError = ref('');
const resolveNotes = ref('');

let seq = 0;
async function load() {
    const mySeq = ++seq;
    loading.value = true;
    try {
        const { data } = await axios.get('/api/v1/issues', { params: {
            page: filters.page,
            status: filters.status || undefined,
            severity: filters.severity || undefined,
            sla: filters.sla || undefined,
            q: filters.q || undefined,
        } });
        if (mySeq !== seq) return;
        rows.value = data.data;
        Object.assign(meta, { total: data.total, current_page: data.current_page, last_page: data.last_page });
        loadError.value = false;
        // Dopo un'azione l'ultima pagina può essersi accorciata: mai restare
        // su una pagina oltre la fine con l'elenco vuoto
        if (meta.last_page >= 1 && filters.page > meta.last_page) {
            filters.page = meta.last_page;
            return load();
        }
    } catch {
        if (mySeq === seq) loadError.value = true;
    } finally {
        if (mySeq === seq) loading.value = false;
    }
}

async function loadAreas() {
    try {
        const { data } = await axios.get('/api/v1/areas', { params: { per_page: 100 } });
        areas.value = data.data;
    } catch {
        areas.value = [];
    }
}

let searchDebounce = null;
let assetSeq = 0;
function searchAssets() {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(async () => {
        if (assetSearch.q.trim().length < 2) {
            assetSearch.results = [];
            return;
        }
        // Stesso schema anti-corsa di load(): vale solo l'ultima richiesta,
        // e mai risultati sopra un elemento già scelto
        const mySeq = ++assetSeq;
        try {
            const { data } = await axios.get('/api/v1/assets', { params: { q: assetSearch.q, per_page: 8 } });
            if (mySeq !== assetSeq || creator.form.asset_id) return;
            assetSearch.results = data.data;
        } catch {
            if (mySeq === assetSeq) assetSearch.results = [];
        }
    }, 250);
}

function pickAsset(asset) {
    clearTimeout(searchDebounce);
    assetSeq += 1;
    creator.form.asset_id = asset.id;
    assetSearch.selectedLabel = asset.census_code || asset.id.slice(0, 8);
    assetSearch.q = '';
    assetSearch.results = [];
}

// L'elenco non deve fare una richiesta per ogni tasto digitato
let listDebounce = null;
function searchList() {
    clearTimeout(listDebounce);
    listDebounce = setTimeout(() => {
        filters.page = 1;
        load();
    }, 250);
}

function openCreator() {
    Object.assign(creator, { open: true, busy: false, error: '' });
    creator.form = {
        description: '', severity: 'medium', reporter_type: 'internal',
        reporter_name: '', reporter_contact: '', category: '', area_id: '', asset_id: '',
    };
    Object.assign(assetSearch, { q: '', results: [], selectedLabel: '' });
}

async function createIssue() {
    creator.busy = true;
    creator.error = '';
    try {
        const payload = Object.fromEntries(
            Object.entries(creator.form).filter(([, v]) => v !== '' && v !== null),
        );
        const { data } = await axios.post('/api/v1/issues', payload);
        creator.open = false;
        filters.page = 1;
        await load();
        detail.value = data.data;
        resolveNotes.value = '';
    } catch (err) {
        creator.error = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
            ?? err.response?.data?.message ?? 'Errore nella registrazione';
    } finally {
        creator.busy = false;
    }
}

function openDetail(issue) {
    detail.value = issue;
    detailError.value = '';
    resolveNotes.value = issue.resolution_notes ?? '';
}

async function act(payload, url = null) {
    detailBusy.value = true;
    detailError.value = '';
    try {
        const { data } = url
            ? await axios.post(url)
            : await axios.patch(`/api/v1/issues/${detail.value.id}`, payload);
        detail.value = data.data;
        await load();
    } catch (err) {
        detailError.value = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
            ?? err.response?.data?.message ?? 'Operazione non riuscita';
    } finally {
        detailBusy.value = false;
    }
}

const resolveIssue = () => act({ status: 'resolved', resolution_notes: resolveNotes.value.trim() || null });
const dismissIssue = () => {
    if (window.confirm('Archiviare la segnalazione senza intervento? Non sarà più modificabile.')) {
        act({ status: 'dismissed' });
    }
};

const fmtDateTime = (iso) => (iso ? new Date(iso).toLocaleString('it-IT', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—');

// ---- SLA: tempi attesi di presa in carico e risoluzione ----
function slaBadge(issue) {
    const s = issue.sla;
    if (! s) return { label: '—', cls: 'text-gray-400' }; // archiviata: nessun intervento richiesto
    if (s.take_charge.state === 'overdue' || s.resolve.state === 'overdue') {
        const late = Math.max(
            s.take_charge.state === 'overdue' ? s.take_charge.days_late : 0,
            s.resolve.state === 'overdue' ? s.resolve.days_late : 0,
        );
        return { label: late > 0 ? `In ritardo di ${late} ${late === 1 ? 'giorno' : 'giorni'}` : 'In ritardo', cls: 'bg-red-100 text-red-800' };
    }
    if (s.take_charge.state === 'late' || s.resolve.state === 'late') {
        return { label: 'Oltre i tempi', cls: 'bg-amber-100 text-amber-800' };
    }
    return { label: 'Nei tempi', cls: 'bg-green-100 text-green-800' };
}

function slaPhaseText(phase) {
    if (phase.state === 'met') return 'rispettata';
    if (phase.state === 'late') return phase.days_late > 0 ? `superata di ${phase.days_late} ${phase.days_late === 1 ? 'giorno' : 'giorni'}` : 'superata';
    if (phase.state === 'overdue') return phase.days_late > 0 ? `in ritardo di ${phase.days_late} ${phase.days_late === 1 ? 'giorno' : 'giorni'}` : 'in ritardo';
    return 'nei tempi';
}

onMounted(async () => {
    await carica(() => Promise.all([load(), loadAreas()]));
});
</script>

<template>
    <Head title="Segnalazioni" />

    <AppLayout>
        <div class="p-6">
            <AvvisoErrore :messaggio="avviso" :in-corso="riprovaInCorso" @riprova="riprova" />

            <div class="mb-4 flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-semibold">Segnalazioni</h1>
                    <p class="text-sm text-gray-500">{{ meta.total }} {{ meta.total === 1 ? 'segnalazione' : 'segnalazioni' }} · da colleghi e clienti, con presa in carico e ordine di lavoro</p>
                </div>
                <button
                    class="rounded-lg bg-green-700 px-4 py-2 text-sm font-medium text-white hover:bg-green-800"
                    data-test="new-issue"
                    @click="openCreator"
                >Nuova segnalazione</button>
            </div>

            <div class="mb-3 flex flex-wrap gap-2">
                <select v-model="filters.status" class="rounded-lg border border-gray-300 px-2.5 py-2 text-sm" @change="filters.page = 1; load()">
                    <option value="">Tutti gli stati</option>
                    <option v-for="(s, value) in STATUS" :key="value" :value="value">{{ s.label }}</option>
                </select>
                <select v-model="filters.severity" class="rounded-lg border border-gray-300 px-2.5 py-2 text-sm" @change="filters.page = 1; load()">
                    <option value="">Tutte le gravità</option>
                    <option v-for="(label, value) in SEVERITY" :key="value" :value="value">{{ label }}</option>
                </select>
                <select v-model="filters.sla" data-test="filter-sla" class="rounded-lg border border-gray-300 px-2.5 py-2 text-sm" @change="filters.page = 1; load()">
                    <option value="">Tempi: tutte</option>
                    <option value="overdue">Fuori tempo massimo</option>
                    <option value="late">Gestite oltre i tempi</option>
                </select>
                <input
                    v-model="filters.q"
                    placeholder="Cerca per codice, testo o segnalante…"
                    class="w-72 rounded-lg border border-gray-300 px-2.5 py-2 text-sm"
                    @input="searchList"
                >

                <VisteSalvate pagina="segnalazioni" :filtri="filtriCorrenti" @applica="applicaVista" />
            </div>

            <p v-if="loadError" class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
                Caricamento non riuscito.
                <button class="ml-1 font-medium underline" @click="load">Riprova</button>
            </p>

            <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
                <table class="w-full text-sm" data-test="issue-list">
                    <thead>
                        <tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wide text-gray-400">
                            <th class="px-4 py-2.5 font-medium">Codice</th>
                            <th class="px-4 py-2.5 font-medium">Gravità</th>
                            <th class="px-4 py-2.5 font-medium">Stato</th>
                            <th class="px-4 py-2.5 font-medium">Tempi</th>
                            <th class="px-4 py-2.5 font-medium">Descrizione</th>
                            <th class="px-4 py-2.5 font-medium">Dove</th>
                            <th class="px-4 py-2.5 font-medium">Segnalante</th>
                            <th class="px-4 py-2.5 font-medium">Ordine</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        <tr
                            v-for="issue in rows"
                            :key="issue.id"
                            class="cursor-pointer hover:bg-green-50/40"
                            data-test="issue-row"
                            @click="openDetail(issue)"
                        >
                            <td class="px-4 py-2 font-medium">{{ issue.code }}</td>
                            <td class="px-4 py-2 text-gray-600">{{ SEVERITY[issue.severity] }}</td>
                            <td class="px-4 py-2">
                                <span class="rounded-full px-2.5 py-0.5 text-xs font-medium" :class="STATUS[issue.status]?.cls">
                                    {{ STATUS[issue.status]?.label ?? issue.status }}
                                </span>
                            </td>
                            <td class="px-4 py-2" data-test="issue-sla">
                                <span class="rounded-full px-2.5 py-0.5 text-xs font-medium" :class="slaBadge(issue).cls">
                                    {{ slaBadge(issue).label }}
                                </span>
                            </td>
                            <td class="max-w-64 truncate px-4 py-2" :title="issue.description">{{ issue.description }}</td>
                            <td class="px-4 py-2 text-gray-600">{{ issue.asset?.census_code ?? issue.area?.name ?? '—' }}</td>
                            <td class="px-4 py-2 text-gray-600">{{ issue.reporter_name || issue.reporter?.name || '—' }}</td>
                            <td class="px-4 py-2 text-gray-600">{{ issue.work_order?.code ?? '—' }}</td>
                        </tr>
                        <tr v-if="! rows.length && ! loading">
                            <td colspan="8" class="px-4 py-8 text-center text-gray-400">Nessuna segnalazione.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="meta.last_page > 1" class="mt-3 flex items-center justify-between text-sm">
                <button class="rounded-lg border border-gray-300 px-3 py-1.5 disabled:opacity-40" :disabled="meta.current_page <= 1" @click="filters.page -= 1; load()">← Precedente</button>
                <span class="text-gray-500">Pagina {{ meta.current_page }} di {{ meta.last_page }}</span>
                <button class="rounded-lg border border-gray-300 px-3 py-1.5 disabled:opacity-40" :disabled="meta.current_page >= meta.last_page" @click="filters.page += 1; load()">Successiva →</button>
            </div>

            <!-- Nuova segnalazione -->
            <Teleport to="body">
                <div v-if="creator.open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4" @click.self="! creator.busy && (creator.open = false)">
                    <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl">
                        <div class="flex items-start justify-between">
                            <h2 class="font-semibold">Nuova segnalazione</h2>
                            <button class="text-gray-400 hover:text-gray-600 disabled:opacity-40" :disabled="creator.busy" @click="creator.open = false">✕</button>
                        </div>

                        <div class="mt-4 space-y-3">
                            <label class="block text-xs">
                                <span class="text-gray-500">Cosa è stato segnalato *</span>
                                <textarea v-model="creator.form.description" rows="3" data-test="issue-description" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm" placeholder="es. Ramo spezzato pericolante sul vialetto di ingresso" />
                            </label>
                            <div class="grid grid-cols-2 gap-3">
                                <label class="block text-xs">
                                    <span class="text-gray-500">Gravità</span>
                                    <select v-model="creator.form.severity" data-test="issue-severity" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-2 text-sm">
                                        <option v-for="(label, value) in SEVERITY" :key="value" :value="value">{{ label }}</option>
                                    </select>
                                </label>
                                <label class="block text-xs">
                                    <span class="text-gray-500">Chi segnala</span>
                                    <select v-model="creator.form.reporter_type" data-test="issue-reporter-type" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-2 text-sm">
                                        <option value="internal">Interno (colleghi)</option>
                                        <option value="client">Cliente</option>
                                    </select>
                                </label>
                            </div>
                            <div v-if="creator.form.reporter_type === 'client'" class="grid grid-cols-2 gap-3">
                                <label class="block text-xs">
                                    <span class="text-gray-500">Nome del segnalante *</span>
                                    <input v-model="creator.form.reporter_name" data-test="issue-reporter-name" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm" placeholder="es. Condominio Girasoli">
                                </label>
                                <label class="block text-xs">
                                    <span class="text-gray-500">Contatto</span>
                                    <input v-model="creator.form.reporter_contact" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm" placeholder="telefono o email">
                                </label>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <label class="block text-xs">
                                    <span class="text-gray-500">Area</span>
                                    <select v-model="creator.form.area_id" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-2 text-sm">
                                        <option value="">—</option>
                                        <option v-for="a in areas" :key="a.id" :value="a.id">{{ a.name }}</option>
                                    </select>
                                </label>
                                <label class="block text-xs">
                                    <span class="text-gray-500">Elemento (codice censimento)</span>
                                    <input
                                        v-if="! creator.form.asset_id"
                                        v-model="assetSearch.q"
                                        data-test="issue-asset-search"
                                        class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm"
                                        placeholder="cerca…"
                                        @input="searchAssets"
                                    >
                                    <div v-else class="mt-1 flex items-center justify-between rounded-lg border border-gray-300 px-2.5 py-2 text-sm">
                                        <span class="font-medium">{{ assetSearch.selectedLabel }}</span>
                                        <button class="text-xs text-red-600 hover:underline" @click="creator.form.asset_id = ''; assetSearch.selectedLabel = ''">togli</button>
                                    </div>
                                    <ul v-if="! creator.form.asset_id && assetSearch.results.length" class="mt-1 divide-y divide-gray-50 rounded-lg border border-gray-200 bg-white shadow">
                                        <li
                                            v-for="a in assetSearch.results"
                                            :key="a.id"
                                            class="cursor-pointer px-3 py-1.5 text-sm hover:bg-green-50"
                                            @click="pickAsset(a)"
                                        >{{ a.census_code || a.id.slice(0, 8) }} <span class="text-xs text-gray-500">{{ a.object_type?.name }}</span></li>
                                    </ul>
                                </label>
                            </div>
                        </div>

                        <p v-if="creator.error" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ creator.error }}</p>

                        <button
                            class="mt-4 w-full rounded-lg bg-green-700 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50"
                            :disabled="creator.busy || ! creator.form.description.trim()"
                            data-test="issue-save"
                            @click="createIssue"
                        >{{ creator.busy ? 'Registrazione…' : 'Registra la segnalazione' }}</button>
                    </div>
                </div>
            </Teleport>

            <!-- Dettaglio -->
            <Teleport to="body">
                <!-- Con un'azione in corso il drawer resta aperto: l'esito
                     (anche un errore) deve restare visibile -->
                <div v-if="detail" class="fixed inset-0 z-50 flex justify-end bg-black/30" @click.self="! detailBusy && (detail = null)">
                    <div class="h-full w-full max-w-xl overflow-y-auto bg-white p-6 shadow-2xl" data-test="issue-detail">
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="text-xs uppercase tracking-wide text-gray-400">{{ detail.code }}</div>
                                <h2 class="text-lg font-semibold">Segnalazione</h2>
                            </div>
                            <button class="text-gray-400 hover:text-gray-600 disabled:opacity-40" :disabled="detailBusy" @click="detail = null">✕</button>
                        </div>

                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <span class="rounded-full px-3 py-1 text-xs font-medium" :class="STATUS[detail.status]?.cls" data-test="issue-status">
                                {{ STATUS[detail.status]?.label ?? detail.status }}
                            </span>
                            <span class="text-xs text-gray-500">Gravità: {{ SEVERITY[detail.severity] }}</span>
                        </div>

                        <p v-if="detailError" class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" data-test="issue-error">{{ detailError }}</p>

                        <p class="mt-3 whitespace-pre-line rounded-lg bg-gray-50 p-3 text-sm text-gray-700">{{ detail.description }}</p>

                        <div v-if="detail.photos?.length" class="mt-2 text-sm" data-test="issue-photos">
                            <span class="text-gray-500">Foto allegate:</span>
                            <a
                                v-for="(photo, index) in detail.photos"
                                :key="photo.id"
                                :href="photo.url"
                                target="_blank"
                                rel="noopener"
                                class="ml-2 font-medium text-green-800 hover:underline"
                            >foto {{ index + 1 }}</a>
                        </div>

                        <dl class="mt-4 space-y-1.5 text-sm">
                            <div class="flex justify-between gap-3 border-b border-gray-50 py-1"><dt class="text-gray-500">Segnalante</dt><dd>{{ detail.reporter_name || detail.reporter?.name || '—' }}<template v-if="detail.reporter_contact"> · {{ detail.reporter_contact }}</template></dd></div>
                            <div class="flex justify-between gap-3 border-b border-gray-50 py-1"><dt class="text-gray-500">Elemento</dt><dd>{{ detail.asset?.census_code ?? '—' }}</dd></div>
                            <div class="flex justify-between gap-3 border-b border-gray-50 py-1"><dt class="text-gray-500">Area</dt><dd>{{ detail.area?.name ?? '—' }}</dd></div>
                            <div class="flex justify-between gap-3 border-b border-gray-50 py-1"><dt class="text-gray-500">Aperta il</dt><dd>{{ fmtDateTime(detail.created_at) }}</dd></div>
                            <div v-if="detail.sla" class="flex justify-between gap-3 border-b border-gray-50 py-1" data-test="sla-take-charge">
                                <dt class="text-gray-500">Presa in carico attesa entro</dt>
                                <dd>{{ fmtDateTime(detail.sla.take_charge.due_at) }}
                                    <span class="text-xs" :class="['overdue', 'late'].includes(detail.sla.take_charge.state) ? 'text-red-700' : 'text-green-700'">· {{ slaPhaseText(detail.sla.take_charge) }}</span>
                                </dd>
                            </div>
                            <div v-if="detail.sla" class="flex justify-between gap-3 border-b border-gray-50 py-1" data-test="sla-resolve">
                                <dt class="text-gray-500">Risoluzione attesa entro</dt>
                                <dd>{{ fmtDateTime(detail.sla.resolve.due_at) }}
                                    <span class="text-xs" :class="['overdue', 'late'].includes(detail.sla.resolve.state) ? 'text-red-700' : 'text-green-700'">· {{ slaPhaseText(detail.sla.resolve) }}</span>
                                </dd>
                            </div>
                            <div v-if="detail.taken_charge_at" class="flex justify-between gap-3 border-b border-gray-50 py-1"><dt class="text-gray-500">Presa in carico</dt><dd>{{ fmtDateTime(detail.taken_charge_at) }}</dd></div>
                            <div v-if="detail.resolved_at" class="flex justify-between gap-3 border-b border-gray-50 py-1"><dt class="text-gray-500">Risolta il</dt><dd>{{ fmtDateTime(detail.resolved_at) }}</dd></div>
                            <div class="flex justify-between gap-3 border-b border-gray-50 py-1"><dt class="text-gray-500">Ordine di lavoro</dt><dd>{{ detail.work_order?.code ?? '—' }}</dd></div>
                        </dl>

                        <p v-if="detail.resolution_notes && detail.status === 'resolved'" class="mt-3 rounded-lg bg-green-50 p-3 text-sm text-green-900">
                            Risoluzione: {{ detail.resolution_notes }}
                        </p>

                        <!-- Azioni di gestione -->
                        <div v-if="canManage && detail.status !== 'resolved' && detail.status !== 'dismissed'" class="mt-5 space-y-2">
                            <button
                                v-if="detail.status === 'open'"
                                class="w-full rounded-lg bg-green-700 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50"
                                :disabled="detailBusy"
                                data-test="issue-take"
                                @click="act({ status: 'in_charge' })"
                            >Prendi in carico</button>
                            <button
                                v-if="! detail.work_order"
                                class="w-full rounded-lg border border-green-700 px-4 py-2.5 text-sm font-semibold text-green-700 disabled:opacity-50"
                                :disabled="detailBusy"
                                data-test="issue-make-wo"
                                @click="act(null, `/api/v1/issues/${detail.id}/work-order`)"
                            >Genera l'ordine di lavoro</button>

                            <!-- La risoluzione arriva solo DOPO la presa in carico:
                                 proporre azioni che il flusso rifiuterebbe confonde -->
                            <div v-if="detail.status === 'in_charge'" class="rounded-xl border border-gray-200 p-3">
                                <label class="block text-xs">
                                    <span class="text-gray-500">Come è stata risolta</span>
                                    <textarea v-model="resolveNotes" rows="2" data-test="issue-resolve-notes" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm" placeholder="es. Rimosso il ramo e messa in sicurezza l'area" />
                                </label>
                                <button
                                    class="mt-2 w-full rounded-lg bg-green-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                                    :disabled="detailBusy || ! resolveNotes.trim()"
                                    data-test="issue-resolve"
                                    @click="resolveIssue"
                                >Segna come risolta</button>
                            </div>
                            <button
                                class="w-full rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 disabled:opacity-50"
                                :disabled="detailBusy"
                                data-test="issue-dismiss"
                                @click="dismissIssue"
                            >Archivia senza intervento</button>
                        </div>
                    </div>
                </div>
            </Teleport>
        </div>
    </AppLayout>
</template>
