<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';

const page = usePage();
const canManage = computed(() => (page.props.auth?.user?.permissions ?? []).includes('works.manage'));

const OUTCOMES = {
    passed: { label: 'Superata', cls: 'bg-green-100 text-green-800' },
    passed_with_remarks: { label: 'Superata con riserve', cls: 'bg-amber-100 text-amber-800' },
    failed: { label: 'Non superata', cls: 'bg-red-100 text-red-800' },
    not_completed: { label: 'Non completata', cls: 'bg-gray-200 text-gray-600' },
};
const ANSWER_TYPES = { ok_ko: 'OK / KO', ok_ko_na: 'OK / KO / N.A.', text: 'Testo', number: 'Numero' };

const view = ref('esecuzioni');
const templates = ref([]);
const inspections = ref([]);
const deadlines = ref([]);
const meta = reactive({ total: 0, current_page: 1, last_page: 1 });
const filters = reactive({ outcome: '', template_id: '', page: 1 });
const areas = ref([]);
const loading = ref(false);
const loadError = ref(false);

// Dettaglio ispezione (sola lettura)
const detail = ref(null);

// Editor modello
const templateEditor = reactive({ open: false, busy: false, error: '', saved: false, isNew: true, dirty: false });
const templateForm = reactive({ id: '', name: '', code: '', target: 'area', standard_ref: '', frequency_days: null, is_active: true, inspections_count: 0, version: 1 });
const templateItems = ref([]);

// Esecuzione nuova ispezione
const runner = reactive({ open: false, busy: false, error: '', template: null, areaId: '', assetId: '' });
const runnerAnswers = ref([]);
const assetSearch = reactive({ q: '', results: [], selectedLabel: '' });
let assetSeq = 0;

let seq = 0;
async function load() {
    const mySeq = ++seq;
    loading.value = true;
    try {
        const [t, i] = await Promise.all([
            axios.get('/api/v1/inspection-templates'),
            axios.get('/api/v1/inspections', { params: {
                page: filters.page,
                outcome: filters.outcome || undefined,
                template_id: filters.template_id || undefined,
            } }),
        ]);
        if (mySeq !== seq) return;
        templates.value = t.data.data;
        inspections.value = i.data.data;
        Object.assign(meta, { total: i.data.total, current_page: i.data.current_page, last_page: i.data.last_page });
        loadError.value = false;
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

// Fuori da load(): la query aggregata non deve pesare su ogni cambio di
// pagina dell'elenco, e un suo errore non deve azzerare le altre viste
const deadlinesError = ref(false);
async function loadDeadlines() {
    try {
        const { data } = await axios.get('/api/v1/inspections/deadlines');
        deadlines.value = data.data;
        deadlinesError.value = false;
    } catch {
        deadlinesError.value = true;
    }
}

async function loadAreas() {
    // Tutte le pagine (tetto 500): oltre le prime 100 aree la tendina
    // non deve tacere le successive
    try {
        const out = [];
        let pageN = 1;
        let lastPage = 1;
        do {
            const { data } = await axios.get('/api/v1/areas', { params: { per_page: 100, page: pageN } });
            out.push(...data.data);
            lastPage = data.last_page;
            pageN += 1;
        } while (pageN <= lastPage && pageN <= 5);
        areas.value = out;
    } catch {
        areas.value = [];
    }
}

// ---- Modelli ----
function markTplDirty() {
    templateEditor.dirty = true;
    templateEditor.saved = false;
}

let editorSeq = 0;
function openTemplateEditor(template = null) {
    const mySeq = ++editorSeq;
    Object.assign(templateEditor, { open: true, busy: false, error: '', saved: false, isNew: ! template, dirty: false });
    if (template) {
        Object.assign(templateForm, {
            id: template.id, name: template.name, code: template.code ?? '',
            target: template.target, standard_ref: template.standard_ref ?? '',
            frequency_days: template.frequency_days, is_active: template.is_active,
            inspections_count: template.inspections_count ?? 0, version: template.version,
        });
        // Mai domande stantie di un altro modello: si parte vuoti e vale
        // solo la risposta dell'ultimo modello aperto
        templateItems.value = [];
        axios.get(`/api/v1/inspection-templates/${template.id}`).then(({ data }) => {
            if (mySeq !== editorSeq || ! templateEditor.open) return;
            templateItems.value = data.data.items.map((i) => ({
                question: i.question, answer_type: i.answer_type, ko_creates_nc: i.ko_creates_nc,
            }));
        }).catch(() => {
            if (mySeq === editorSeq) {
                templateEditor.error = 'Caricamento delle domande non riuscito: chiudi e riapri il modello.';
            }
        });
    } else {
        Object.assign(templateForm, {
            id: '', name: '', code: '', target: 'area', standard_ref: '',
            frequency_days: null, is_active: true, inspections_count: 0, version: 1,
        });
        templateItems.value = [{ question: '', answer_type: 'ok_ko', ko_creates_nc: true }];
    }
}

function closeTemplateEditor() {
    if (templateEditor.dirty && canManage.value
        && ! window.confirm('Chiudere senza salvare le modifiche al modello? Andranno perse.')) {
        return;
    }
    templateEditor.open = false;
}

async function saveTemplate() {
    templateEditor.busy = true;
    templateEditor.error = '';
    templateEditor.saved = false;
    try {
        const payload = {
            name: templateForm.name.trim(),
            code: templateForm.code.trim() || null,
            target: templateForm.target,
            standard_ref: templateForm.standard_ref.trim() || null,
            frequency_days: templateForm.frequency_days || null,
            is_active: templateForm.is_active,
        };
        let id = templateForm.id;
        if (templateEditor.isNew) {
            const { data } = await axios.post('/api/v1/inspection-templates', payload);
            id = data.data.id;
            templateForm.id = id;
            templateEditor.isNew = false;
        } else {
            await axios.patch(`/api/v1/inspection-templates/${id}`, payload);
        }
        const { data } = await axios.put(`/api/v1/inspection-templates/${id}/items`, {
            items: templateItems.value
                .filter((i) => i.question.trim() !== '')
                .map((i) => ({ question: i.question.trim(), answer_type: i.answer_type, ko_creates_nc: i.ko_creates_nc })),
        });
        templateForm.version = data.data.version;
        templateEditor.saved = true;
        templateEditor.dirty = false;
        await load();
    } catch (err) {
        templateEditor.error = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
            ?? err.response?.data?.message ?? 'Salvataggio non riuscito';
        // La testata potrebbe essere già stata creata: l'elenco deve saperlo
        await load();
    } finally {
        templateEditor.busy = false;
    }
}

// Il percorso suggerito quando un modello usato non si può eliminare
async function toggleTemplateActive() {
    templateEditor.error = '';
    templateEditor.busy = true;
    try {
        await axios.patch(`/api/v1/inspection-templates/${templateForm.id}`, {
            is_active: ! templateForm.is_active,
        });
        templateForm.is_active = ! templateForm.is_active;
        await load();
    } catch (err) {
        templateEditor.error = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
            ?? err.response?.data?.message ?? 'Aggiornamento non riuscito';
    } finally {
        templateEditor.busy = false;
    }
}

async function deleteTemplate() {
    if (! window.confirm(`Eliminare il modello ${templateForm.name}? Non è consentito se già usato da ispezioni.`)) return;
    templateEditor.error = '';
    try {
        await axios.delete(`/api/v1/inspection-templates/${templateForm.id}`);
        templateEditor.open = false;
        await load();
    } catch (err) {
        templateEditor.error = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
            ?? err.response?.data?.message ?? 'Eliminazione non riuscita';
    }
}

// ---- Esecuzione ----
let runnerOpening = false;
async function openRunner(template) {
    if (runnerOpening) return;
    runnerOpening = true;
    try {
        const { data } = await axios.get(`/api/v1/inspection-templates/${template.id}`);
        Object.assign(runner, { open: true, busy: false, error: '', template: data.data, areaId: '', assetId: '' });
        Object.assign(assetSearch, { q: '', results: [], selectedLabel: '' });
        runnerAnswers.value = data.data.items.map((item) => ({
            item_id: item.id, question: item.question, answer_type: item.answer_type,
            value: '', note: '',
        }));
    } catch {
        loadError.value = true;
    } finally {
        runnerOpening = false;
    }
}

// Un tocco fuori dal pannello non deve buttare via una checklist compilata
function closeRunner() {
    if (runner.busy) return;
    const hasWork = runnerAnswers.value.some((a) => String(a.value) !== '' || a.note.trim() !== '');
    if (hasWork && ! window.confirm('Chiudere senza registrare l\'ispezione? Le risposte andranno perse.')) {
        return;
    }
    runner.open = false;
}

function searchAssets() {
    const mySeq = ++assetSeq;
    setTimeout(async () => {
        if (mySeq !== assetSeq) return;
        if (assetSearch.q.trim().length < 2) {
            assetSearch.results = [];
            return;
        }
        try {
            const { data } = await axios.get('/api/v1/assets', { params: { q: assetSearch.q, per_page: 8 } });
            if (mySeq !== assetSeq || runner.assetId) return;
            assetSearch.results = data.data;
        } catch {
            if (mySeq === assetSeq) assetSearch.results = [];
        }
    }, 250);
}

function pickAsset(asset) {
    assetSeq += 1;
    runner.assetId = asset.id;
    assetSearch.selectedLabel = asset.census_code || asset.id.slice(0, 8);
    assetSearch.q = '';
    assetSearch.results = [];
}

// String(): un input numerico diventa Number col v-model e .trim()
// su un numero manderebbe in errore il calcolo
const runnerComplete = computed(() => runnerAnswers.value.every((a) => {
    if (['ok_ko', 'ok_ko_na'].includes(a.answer_type)) return a.value !== '';
    return String(a.value).trim() !== '';
}));

async function submitInspection() {
    runner.busy = true;
    runner.error = '';
    try {
        const answers = Object.fromEntries(runnerAnswers.value.map((a) => [
            a.item_id, { value: String(a.value), note: a.note.trim() || null },
        ]));
        const { data } = await axios.post('/api/v1/inspections', {
            template_id: runner.template.id,
            ...(runner.template.target === 'asset' ? { asset_id: runner.assetId } : { area_id: runner.areaId }),
            answers,
        });
        runner.open = false;
        filters.page = 1;
        await load();
        loadDeadlines();
        detail.value = data.data;
        view.value = 'esecuzioni';
    } catch (err) {
        runner.error = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
            ?? err.response?.data?.message ?? 'Registrazione non riuscita';
    } finally {
        runner.busy = false;
    }
}

const fmtDateTime = (iso) => (iso ? new Date(iso).toLocaleString('it-IT', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—');
const fmtDate = (ymd) => (ymd ? new Date(`${ymd}T00:00:00`).toLocaleDateString('it-IT') : '—');
const answerLabel = (a) => (a.value === 'ok' ? 'OK' : a.value === 'ko' ? 'KO' : a.value === 'na' ? 'N.A.' : a.value);

// ---- Scadenzario ----
const overdueCount = computed(() => deadlines.value.filter((d) => d.state === 'overdue').length);
function deadlineBadge(d) {
    if (d.state === 'overdue') {
        return { label: `In ritardo di ${-d.days_left} ${-d.days_left === 1 ? 'giorno' : 'giorni'}`, cls: 'bg-red-100 text-red-800' };
    }
    if (d.days_left === 0) return { label: 'Scade oggi', cls: 'bg-amber-100 text-amber-800' };
    if (d.state === 'due_soon') {
        return { label: `Entro ${d.days_left} ${d.days_left === 1 ? 'giorno' : 'giorni'}`, cls: 'bg-amber-100 text-amber-800' };
    }
    return { label: 'Programmata', cls: 'bg-green-100 text-green-800' };
}

onMounted(async () => {
    await Promise.all([load(), loadAreas(), loadDeadlines()]);
});
</script>

<template>
    <Head title="Ispezioni" />

    <AppLayout>
        <div class="p-6">
            <div class="mb-4 flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-semibold">Ispezioni</h1>
                    <p class="text-sm text-gray-500">Controlli su checklist: modelli configurabili, esiti per voce e non conformità automatiche</p>
                </div>
                <button
                    v-if="canManage"
                    class="rounded-lg bg-green-700 px-4 py-2 text-sm font-medium text-white hover:bg-green-800"
                    data-test="new-template"
                    @click="openTemplateEditor()"
                >Nuovo modello</button>
            </div>

            <div class="mb-3 inline-flex overflow-hidden rounded-lg border border-gray-300 text-sm">
                <button
                    class="px-4 py-1.5 font-medium"
                    :class="view === 'esecuzioni' ? 'bg-green-700 text-white' : 'bg-white text-gray-600 hover:bg-gray-50'"
                    data-test="view-esecuzioni"
                    @click="view = 'esecuzioni'"
                >Esecuzioni</button>
                <button
                    class="border-l border-gray-300 px-4 py-1.5 font-medium"
                    :class="view === 'modelli' ? 'bg-green-700 text-white' : 'bg-white text-gray-600 hover:bg-gray-50'"
                    data-test="view-modelli"
                    @click="view = 'modelli'"
                >Modelli</button>
                <button
                    class="border-l border-gray-300 px-4 py-1.5 font-medium"
                    :class="view === 'scadenzario' ? 'bg-green-700 text-white' : 'bg-white text-gray-600 hover:bg-gray-50'"
                    data-test="view-scadenzario"
                    @click="view = 'scadenzario'; loadDeadlines()"
                >Scadenzario<template v-if="overdueCount"> ({{ overdueCount }})</template></button>
            </div>

            <p v-if="loadError" class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
                Caricamento non riuscito.
                <button class="ml-1 font-medium underline" @click="load">Riprova</button>
            </p>

            <!-- MODELLI -->
            <div v-if="view === 'modelli'" class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <table class="w-full text-sm" data-test="template-list">
                    <thead>
                        <tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wide text-gray-400">
                            <th class="px-4 py-2.5 font-medium">Codice</th>
                            <th class="px-4 py-2.5 font-medium">Nome</th>
                            <th class="px-4 py-2.5 font-medium">Si applica a</th>
                            <th class="px-4 py-2.5 font-medium">Norma</th>
                            <th class="px-4 py-2.5 text-right font-medium">Domande</th>
                            <th class="px-4 py-2.5 text-right font-medium">Ispezioni</th>
                            <th class="px-4 py-2.5 font-medium">Stato</th>
                            <th class="px-4 py-2.5 font-medium" />
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        <tr v-for="t in templates" :key="t.id" class="hover:bg-green-50/40" data-test="template-row">
                            <td class="cursor-pointer px-4 py-2 font-medium" @click="openTemplateEditor(t)">{{ t.code ?? '—' }}</td>
                            <td class="cursor-pointer px-4 py-2" @click="openTemplateEditor(t)">{{ t.name }}</td>
                            <td class="px-4 py-2 text-gray-600">{{ t.target === 'asset' ? 'Elemento' : 'Area' }}</td>
                            <td class="px-4 py-2 text-gray-600">{{ t.standard_ref ?? '—' }}</td>
                            <td class="px-4 py-2 text-right">{{ t.items_count }}</td>
                            <td class="px-4 py-2 text-right">{{ t.inspections_count }}</td>
                            <td class="px-4 py-2">
                                <span class="rounded-full px-2.5 py-0.5 text-xs font-medium" :class="t.is_active ? 'bg-green-100 text-green-800' : 'bg-gray-200 text-gray-500'">
                                    {{ t.is_active ? 'Attivo' : 'Disattivato' }}
                                </span>
                            </td>
                            <td class="px-4 py-2 text-right">
                                <button
                                    v-if="canManage && t.is_active && t.items_count > 0"
                                    class="rounded-lg border border-green-700 px-2.5 py-1 text-xs font-medium text-green-700 hover:bg-green-50"
                                    data-test="template-run"
                                    @click="openRunner(t)"
                                >Esegui</button>
                            </td>
                        </tr>
                        <tr v-if="! templates.length && ! loading">
                            <td colspan="8" class="px-4 py-8 text-center text-gray-400">Nessun modello di ispezione: crea il primo.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- SCADENZARIO -->
            <div v-if="view === 'scadenzario'">
                <p class="mb-3 text-sm text-gray-500">
                    Controlli ricorrenti da ripetere: la scadenza parte dall'ultima ispezione registrata,
                    secondo la periodicità impostata sul modello.
                </p>
                <p v-if="deadlinesError" class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
                    Caricamento delle scadenze non riuscito.
                    <button class="ml-1 font-medium underline" @click="loadDeadlines">Riprova</button>
                </p>
                <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                    <table class="w-full text-sm" data-test="deadline-list">
                        <thead>
                            <tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wide text-gray-400">
                                <th class="px-4 py-2.5 font-medium">Modello</th>
                                <th class="px-4 py-2.5 font-medium">Su</th>
                                <th class="px-4 py-2.5 font-medium">Ultima ispezione</th>
                                <th class="px-4 py-2.5 font-medium">Da ripetere entro</th>
                                <th class="px-4 py-2.5 font-medium">Stato</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <tr v-for="d in deadlines" :key="`${d.template_id}-${d.target_id}`" data-test="deadline-row">
                                <td class="px-4 py-2 font-medium">{{ d.template_name }}<span v-if="d.template_code" class="ml-1 text-xs font-normal text-gray-400">{{ d.template_code }}</span></td>
                                <td class="px-4 py-2 text-gray-600">{{ d.target_label }}</td>
                                <td class="px-4 py-2 text-gray-600">{{ fmtDateTime(d.last_completed_at) }}</td>
                                <td class="px-4 py-2">{{ fmtDate(d.due_date) }}</td>
                                <td class="px-4 py-2">
                                    <span class="rounded-full px-2.5 py-0.5 text-xs font-medium" :class="deadlineBadge(d).cls" data-test="deadline-state">
                                        {{ deadlineBadge(d).label }}
                                    </span>
                                </td>
                            </tr>
                            <tr v-if="! deadlines.length && ! loading">
                                <td colspan="5" class="px-4 py-8 text-center text-gray-400">
                                    Nessuna scadenza: compaiono qui i modelli con periodicità impostata,
                                    dopo la prima ispezione registrata su un'area o un elemento.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ESECUZIONI -->
            <template v-if="view === 'esecuzioni'">
                <div class="mb-3 flex flex-wrap gap-2">
                    <select v-model="filters.outcome" class="rounded-lg border border-gray-300 px-2.5 py-2 text-sm" @change="filters.page = 1; load()">
                        <option value="">Tutti gli esiti</option>
                        <option v-for="(o, value) in OUTCOMES" :key="value" :value="value">{{ o.label }}</option>
                    </select>
                    <select v-model="filters.template_id" class="rounded-lg border border-gray-300 px-2.5 py-2 text-sm" @change="filters.page = 1; load()">
                        <option value="">Tutti i modelli</option>
                        <option v-for="t in templates" :key="t.id" :value="t.id">{{ t.name }}</option>
                    </select>
                </div>

                <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                    <table class="w-full text-sm" data-test="inspection-list">
                        <thead>
                            <tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wide text-gray-400">
                                <th class="px-4 py-2.5 font-medium">Data</th>
                                <th class="px-4 py-2.5 font-medium">Modello</th>
                                <th class="px-4 py-2.5 font-medium">Su</th>
                                <th class="px-4 py-2.5 font-medium">Ispettore</th>
                                <th class="px-4 py-2.5 font-medium">Esito</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <tr
                                v-for="i in inspections"
                                :key="i.id"
                                class="cursor-pointer hover:bg-green-50/40"
                                data-test="inspection-row"
                                @click="detail = i"
                            >
                                <td class="px-4 py-2 text-gray-600">{{ fmtDateTime(i.completed_at) }}</td>
                                <td class="px-4 py-2 font-medium">{{ i.template?.name }}</td>
                                <td class="px-4 py-2 text-gray-600">{{ i.asset?.census_code ?? i.area?.name ?? '—' }}</td>
                                <td class="px-4 py-2 text-gray-600">{{ i.inspector?.name ?? '—' }}</td>
                                <td class="px-4 py-2">
                                    <span class="rounded-full px-2.5 py-0.5 text-xs font-medium" :class="OUTCOMES[i.outcome]?.cls">
                                        {{ OUTCOMES[i.outcome]?.label ?? i.outcome }}
                                    </span>
                                </td>
                            </tr>
                            <tr v-if="! inspections.length && ! loading">
                                <td colspan="5" class="px-4 py-8 text-center text-gray-400">
                                    Nessuna ispezione registrata. Esegui un modello dalla scheda Modelli.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="meta.last_page > 1" class="mt-3 flex items-center justify-between text-sm">
                    <button class="rounded-lg border border-gray-300 px-3 py-1.5 disabled:opacity-40" :disabled="meta.current_page <= 1" @click="filters.page -= 1; load()">← Precedente</button>
                    <span class="text-gray-500">Pagina {{ meta.current_page }} di {{ meta.last_page }}</span>
                    <button class="rounded-lg border border-gray-300 px-3 py-1.5 disabled:opacity-40" :disabled="meta.current_page >= meta.last_page" @click="filters.page += 1; load()">Successiva →</button>
                </div>
            </template>

            <!-- Editor modello -->
            <Teleport to="body">
                <div v-if="templateEditor.open" class="fixed inset-0 z-50 flex justify-end bg-black/30" @click.self="closeTemplateEditor">
                    <div class="h-full w-full max-w-2xl overflow-y-auto bg-white p-6 shadow-2xl" data-test="template-editor">
                        <div class="flex items-start justify-between">
                            <div>
                                <h2 class="text-lg font-semibold">{{ templateEditor.isNew ? 'Nuovo modello di ispezione' : 'Modello di ispezione' }}</h2>
                                <p v-if="! templateEditor.isNew" class="text-xs text-gray-500">
                                    Versione {{ templateForm.version }} · {{ templateForm.inspections_count }} ispezioni registrate
                                </p>
                            </div>
                            <button class="text-gray-400 hover:text-gray-600" @click="closeTemplateEditor">✕</button>
                        </div>

                        <div class="mt-4 grid grid-cols-2 gap-3">
                            <label class="col-span-2 block text-xs">
                                <span class="text-gray-500">Nome *</span>
                                <input v-model="templateForm.name" data-test="template-name" :disabled="! canManage" class="mt-1 w-full disabled:bg-gray-50 rounded-lg border border-gray-300 px-2.5 py-2 text-sm" placeholder="es. Controllo funzionale area giochi" @input="markTplDirty()">
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Codice</span>
                                <input v-model="templateForm.code" :disabled="! canManage" class="mt-1 w-full disabled:bg-gray-50 rounded-lg border border-gray-300 px-2.5 py-2 text-sm" placeholder="es. EN1176-7" @input="markTplDirty()">
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Si applica a</span>
                                <select v-model="templateForm.target" data-test="template-target" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-2 text-sm" :disabled="! canManage || templateForm.inspections_count > 0" @change="markTplDirty()">
                                    <option value="area">Area</option>
                                    <option value="asset">Elemento censito</option>
                                </select>
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Norma di riferimento</span>
                                <input v-model="templateForm.standard_ref" :disabled="! canManage" class="mt-1 w-full disabled:bg-gray-50 rounded-lg border border-gray-300 px-2.5 py-2 text-sm" placeholder="es. UNI EN 1176-7:2020" @input="markTplDirty()">
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Periodicità suggerita (giorni)</span>
                                <input v-model.number="templateForm.frequency_days" type="number" min="1" max="3650" :disabled="! canManage" class="mt-1 w-full disabled:bg-gray-50 rounded-lg border border-gray-300 px-2.5 py-2 text-sm" @input="markTplDirty()">
                            </label>
                        </div>

                        <h3 class="mt-5 text-sm font-semibold">Domande ({{ templateItems.length }})</h3>
                        <p v-if="templateForm.inspections_count > 0" class="mt-1 text-xs text-amber-700">
                            Modello già usato: salvando le domande la versione avanza; le ispezioni passate restano congelate sulla loro.
                        </p>
                        <div class="mt-2 space-y-2">
                            <div v-for="(item, index) in templateItems" :key="index" class="flex items-start gap-2" data-test="template-item">
                                <span class="mt-2 w-6 text-right text-xs text-gray-400">{{ index + 1 }}.</span>
                                <input v-model="item.question" :disabled="! canManage" class="flex-1 rounded-lg border border-gray-300 px-2.5 py-2 text-sm disabled:bg-gray-50" placeholder="Domanda del controllo" @input="markTplDirty()">
                                <select v-model="item.answer_type" :disabled="! canManage" class="w-40 rounded-lg border border-gray-300 px-2 py-2 text-sm disabled:bg-gray-50" @change="markTplDirty()">
                                    <option v-for="(label, value) in ANSWER_TYPES" :key="value" :value="value">{{ label }}</option>
                                </select>
                                <label class="mt-2 flex items-center gap-1 text-xs text-gray-500" title="Una risposta KO apre una non conformità">
                                    <input v-model="item.ko_creates_nc" type="checkbox" :disabled="! canManage" class="rounded border-gray-300" @change="markTplDirty()"> NC
                                </label>
                                <button v-if="canManage" class="mt-1.5 text-xs font-medium text-red-600 hover:underline" @click="templateItems.splice(index, 1); markTplDirty()">✕</button>
                            </div>
                        </div>
                        <button
                            v-if="canManage"
                            class="mt-2 rounded-lg border border-gray-300 px-3 py-1.5 text-sm"
                            data-test="template-add-item"
                            @click="templateItems.push({ question: '', answer_type: 'ok_ko', ko_creates_nc: true }); markTplDirty()"
                        >Aggiungi domanda</button>

                        <p v-if="templateEditor.error" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" data-test="template-error">{{ templateEditor.error }}</p>
                        <p v-if="templateEditor.saved" class="mt-3 rounded-lg bg-green-100 px-3 py-2 text-sm text-green-900" data-test="template-saved">Modello salvato.</p>

                        <div v-if="canManage" class="mt-4 flex items-center justify-between">
                            <span class="flex items-center gap-5">
                                <button
                                    v-if="! templateEditor.isNew"
                                    class="text-sm font-medium text-gray-700 hover:underline disabled:opacity-50"
                                    :disabled="templateEditor.busy"
                                    data-test="template-toggle-active"
                                    @click="toggleTemplateActive"
                                >{{ templateForm.is_active ? 'Disattiva modello' : 'Riattiva modello' }}</button>
                                <button
                                    v-if="! templateEditor.isNew"
                                    class="text-sm font-medium text-red-600 hover:underline"
                                    @click="deleteTemplate"
                                >Elimina modello</button>
                            </span>
                            <button
                                class="rounded-lg bg-green-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                                :disabled="templateEditor.busy || ! templateForm.name.trim() || ! templateItems.some((i) => i.question.trim())"
                                data-test="template-save"
                                @click="saveTemplate"
                            >{{ templateEditor.busy ? 'Salvataggio…' : 'Salva il modello' }}</button>
                        </div>
                    </div>
                </div>
            </Teleport>

            <!-- Esecuzione ispezione -->
            <Teleport to="body">
                <div v-if="runner.open" class="fixed inset-0 z-50 flex justify-end bg-black/30" @click.self="closeRunner">
                    <div class="h-full w-full max-w-2xl overflow-y-auto bg-white p-6 shadow-2xl" data-test="runner">
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="text-xs uppercase tracking-wide text-gray-400">{{ runner.template.code ?? '' }} <template v-if="runner.template.standard_ref">· {{ runner.template.standard_ref }}</template></div>
                                <h2 class="text-lg font-semibold">{{ runner.template.name }}</h2>
                            </div>
                            <button class="text-gray-400 hover:text-gray-600 disabled:opacity-40" :disabled="runner.busy" @click="closeRunner">✕</button>
                        </div>

                        <div class="mt-4">
                            <label v-if="runner.template.target === 'area'" class="block text-xs">
                                <span class="text-gray-500">Area ispezionata *</span>
                                <select v-model="runner.areaId" data-test="runner-area" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-2.5 text-sm">
                                    <option value="" disabled>Seleziona…</option>
                                    <option v-for="a in areas" :key="a.id" :value="a.id">{{ a.name }}</option>
                                </select>
                            </label>
                            <label v-else class="block text-xs">
                                <span class="text-gray-500">Elemento ispezionato * (codice censimento)</span>
                                <input
                                    v-if="! runner.assetId"
                                    v-model="assetSearch.q"
                                    data-test="runner-asset-search"
                                    class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm"
                                    placeholder="cerca…"
                                    @input="searchAssets"
                                >
                                <div v-else class="mt-1 flex items-center justify-between rounded-lg border border-gray-300 px-2.5 py-2 text-sm">
                                    <span class="font-medium">{{ assetSearch.selectedLabel }}</span>
                                    <button class="text-xs text-red-600 hover:underline" @click="runner.assetId = ''; assetSearch.selectedLabel = ''">togli</button>
                                </div>
                                <ul v-if="! runner.assetId && assetSearch.results.length" class="mt-1 divide-y divide-gray-50 rounded-lg border border-gray-200 bg-white shadow">
                                    <li
                                        v-for="a in assetSearch.results"
                                        :key="a.id"
                                        class="cursor-pointer px-3 py-1.5 text-sm hover:bg-green-50"
                                        @click="pickAsset(a)"
                                    >{{ a.census_code || a.id.slice(0, 8) }} <span class="text-xs text-gray-500">{{ a.object_type?.name }}</span></li>
                                </ul>
                            </label>
                        </div>

                        <h3 class="mt-5 text-sm font-semibold">Checklist ({{ runnerAnswers.length }} voci)</h3>
                        <div class="mt-2 space-y-3">
                            <div v-for="(answer, index) in runnerAnswers" :key="answer.item_id" class="rounded-xl border border-gray-200 p-3" data-test="runner-item">
                                <p class="text-sm font-medium">{{ index + 1 }}. {{ answer.question }}</p>
                                <div v-if="['ok_ko', 'ok_ko_na'].includes(answer.answer_type)" class="mt-2 flex gap-2">
                                    <button
                                        v-for="option in (answer.answer_type === 'ok_ko' ? ['ok', 'ko'] : ['ok', 'ko', 'na'])"
                                        :key="option"
                                        class="rounded-lg border px-4 py-1.5 text-sm font-medium"
                                        :class="answer.value === option
                                            ? (option === 'ko' ? 'border-red-700 bg-red-700 text-white' : option === 'na' ? 'border-gray-500 bg-gray-500 text-white' : 'border-green-700 bg-green-700 text-white')
                                            : 'border-gray-300 text-gray-600'"
                                        :data-test="`runner-${option}-${index}`"
                                        @click="answer.value = option"
                                    >{{ option === 'ok' ? 'OK' : option === 'ko' ? 'KO' : 'N.A.' }}</button>
                                </div>
                                <input
                                    v-else
                                    v-model="answer.value"
                                    :type="answer.answer_type === 'number' ? 'number' : 'text'"
                                    class="mt-2 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm"
                                    placeholder="Risposta"
                                >
                                <input
                                    v-model="answer.note"
                                    class="mt-2 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-xs"
                                    placeholder="Nota (obbligatoria solo se serve spiegare)"
                                    :data-test="`runner-note-${index}`"
                                >
                            </div>
                        </div>

                        <p v-if="runner.error" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" data-test="runner-error">{{ runner.error }}</p>

                        <button
                            class="mt-4 w-full rounded-lg bg-green-700 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50"
                            :disabled="runner.busy || ! runnerComplete || (runner.template.target === 'area' ? ! runner.areaId : ! runner.assetId)"
                            data-test="runner-save"
                            @click="submitInspection"
                        >{{ runner.busy ? 'Registrazione…' : 'Registra l\'ispezione' }}</button>
                    </div>
                </div>
            </Teleport>

            <!-- Dettaglio ispezione -->
            <Teleport to="body">
                <div v-if="detail" class="fixed inset-0 z-50 flex justify-end bg-black/30" @click.self="detail = null">
                    <div class="h-full w-full max-w-xl overflow-y-auto bg-white p-6 shadow-2xl" data-test="inspection-detail">
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="text-xs uppercase tracking-wide text-gray-400">
                                    {{ detail.template?.name }} · versione modello {{ detail.template_version }}
                                </div>
                                <h2 class="text-lg font-semibold">Ispezione del {{ fmtDateTime(detail.completed_at) }}</h2>
                            </div>
                            <span class="flex items-center gap-3">
                                <a
                                    :href="`/api/v1/inspections/${detail.id}/pdf`"
                                    target="_blank"
                                    class="rounded-lg border border-green-700 px-2.5 py-1 text-xs font-medium text-green-700 hover:bg-green-50"
                                    data-test="inspection-pdf"
                                >Verbale PDF</a>
                                <button class="text-gray-400 hover:text-gray-600" @click="detail = null">✕</button>
                            </span>
                        </div>

                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <span class="rounded-full px-3 py-1 text-xs font-medium" :class="OUTCOMES[detail.outcome]?.cls" data-test="inspection-outcome">
                                {{ OUTCOMES[detail.outcome]?.label ?? detail.outcome }}
                            </span>
                            <span class="text-xs text-gray-500">{{ detail.asset?.census_code ?? detail.area?.name }}</span>
                            <span class="text-xs text-gray-500">Ispettore: {{ detail.inspector?.name ?? '—' }}</span>
                        </div>

                        <ul class="mt-4 space-y-2" data-test="inspection-answers">
                            <li v-for="(answer, itemId) in detail.answers" :key="itemId" class="rounded-lg border border-gray-100 px-3 py-2 text-sm">
                                <div class="flex items-start justify-between gap-3">
                                    <span>{{ answer.question }}</span>
                                    <span
                                        class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                        :class="answer.value === 'ko' ? 'bg-red-100 text-red-800' : answer.value === 'ok' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-700'"
                                    >{{ answerLabel(answer) }}</span>
                                </div>
                                <p v-if="answer.note" class="mt-1 text-xs text-gray-500">{{ answer.note }}</p>
                            </li>
                        </ul>

                        <p v-if="detail.outcome === 'failed'" class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900">
                            Le voci KO hanno aperto le non conformità: le trovi nella vista Qualità della pagina Lavori.
                        </p>
                    </div>
                </div>
            </Teleport>
        </div>
    </AppLayout>
</template>
