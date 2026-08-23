<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import { avvisoCaricamento } from '@/avvisi';

const page = usePage();
const permissions = computed(() => page.props.auth?.user?.permissions ?? []);
const canManage = computed(() => permissions.value.includes('areas.update'));
const canWorks = computed(() => permissions.value.includes('works.manage'));

const TYPE_LABELS = {
    aspersione: 'Aspersione',
    goccia: 'Ala gocciolante',
    subirrigazione: 'Subirrigazione',
    idranti: 'Idranti',
    misto: 'Misto',
};
const SOURCE_LABELS = {
    acquedotto: 'Acquedotto',
    pozzo: 'Pozzo',
    cisterna: 'Cisterna',
    corso_acqua: "Corso d'acqua",
    altro: 'Altro',
};
const STATUS_LABELS = {
    active: 'In esercizio',
    winterized: 'Invernato',
    out_of_service: 'Fuori servizio',
};
const STATUS_BADGE = {
    active: 'bg-green-100 text-green-800',
    winterized: 'bg-blue-100 text-blue-800',
    out_of_service: 'bg-gray-200 text-gray-500',
};

const systems = ref([]);
const areas = ref([]);
const loading = ref(false);

const creator = reactive({
    open: false,
    busy: false,
    error: '',
    form: { area_id: '', name: '', system_type: 'aspersione' },
});

// Dettaglio: si modifica una copia locale dell'impianto e dei settori;
// il salvataggio manda i dati dell'impianto e l'elenco intero dei settori
const detail = ref(null);
const form = reactive({});
const sectors = ref([]);
const detailBusy = ref(false);
const detailError = ref('');
const detailSaved = ref(false);
const workOrderMessage = ref('');
const editorDirty = ref(false);
const pageError = ref('');

// Letture del contatore idrico con consumo tra letture e confronto
// con la stima settimanale del programma dei settori
const readings = ref([]);
const readingsSummary = ref(null);
const readingError = ref('');
const readingBusy = ref(false);

// Data locale dell'utente: toISOString darebbe la data UTC, che intorno
// alla mezzanotte italiana è ancora quella del giorno prima
function todayLocal() {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
const newReading = reactive({ read_on: todayLocal(), value_m3: null, note: '' });

const deviationPct = computed(() => {
    const s = readingsSummary.value;
    if (! s || s.actual_weekly_m3 == null || ! s.weekly_estimate_m3) return null;
    return Math.round((s.actual_weekly_m3 - s.weekly_estimate_m3) / s.weekly_estimate_m3 * 100);
});

function markDirty() {
    editorDirty.value = true;
    detailSaved.value = false;
}

function closeDetail() {
    if (editorDirty.value && canManage.value
        && ! window.confirm('Chiudere senza salvare le modifiche? Andranno perse.')) {
        return;
    }
    detail.value = null;
}

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

function seasonLabel(s) {
    if (! s.season_opens_on && ! s.season_closes_on) return '—';
    return `${formatDate(s.season_opens_on)} → ${formatDate(s.season_closes_on)}`;
}

// L'API delle aree è paginata (massimo 100 per pagina): si scorrono
// tutte le pagine, o le aree oltre la centesima non sarebbero selezionabili
async function fetchAllAreas() {
    const all = [];
    for (let p = 1; p <= 20; p++) {
        const { data } = await axios.get('/api/v1/areas', { params: { per_page: 100, page: p } });
        all.push(...data.data);
        if (! data.next_page_url) break;
    }
    return all;
}

async function load() {
    loading.value = true;
    pageError.value = '';
    try {
        const [sy, ar] = await Promise.all([
            axios.get('/api/v1/irrigation-systems'),
            fetchAllAreas(),
        ]);
        systems.value = sy.data.data;
        areas.value = ar;
    } catch (err) {
        pageError.value = avvisoCaricamento(err);
    } finally {
        loading.value = false;
    }
}

async function createSystem() {
    creator.busy = true;
    creator.error = '';
    try {
        const { data } = await axios.post('/api/v1/irrigation-systems', creator.form);
        creator.open = false;
        creator.form = { area_id: '', name: '', system_type: 'aspersione' };
        // load() gestisce da sé i propri errori: un fallimento qui non deve
        // comparire come errore di creazione (l'impianto è già stato creato)
        await load();
        await openDetail(data.data.id);
    } catch (err) {
        creator.error = firstError(err, 'Errore nella creazione');
    } finally {
        creator.busy = false;
    }
}

async function openDetail(id) {
    detailError.value = '';
    detailSaved.value = false;
    workOrderMessage.value = '';
    pageError.value = '';
    try {
        const { data } = await axios.get(`/api/v1/irrigation-systems/${id}`);
        setDetail(data.data);
    } catch {
        pageError.value = "Apertura dell'impianto non riuscita: potrebbe essere stato eliminato. Ricarica la pagina.";
        await load();
    }
}

function setDetail(system) {
    // Cambiando impianto niente deve restare del precedente: né le letture
    // in tabella né i valori digitati e non registrati nel modulo
    if (detail.value?.id !== system.id) {
        readings.value = [];
        readingsSummary.value = null;
        readingError.value = '';
        newReading.read_on = todayLocal();
        newReading.value_m3 = null;
        newReading.note = '';
    }
    detail.value = system;
    Object.assign(form, {
        area_id: system.area_id,
        name: system.name,
        system_type: system.system_type,
        water_source: system.water_source ?? '',
        controller_model: system.controller_model ?? '',
        status: system.status,
        season_opens_on: dateOnly(system.season_opens_on),
        season_closes_on: dateOnly(system.season_closes_on),
        notes: system.notes ?? '',
    });
    sectors.value = (system.sectors ?? []).map((s) => ({
        name: s.name,
        description: s.description ?? '',
        flow_lpm: s.flow_lpm == null ? null : Number(s.flow_lpm),
        run_minutes: s.run_minutes,
        runs_per_week: s.runs_per_week,
    }));
    editorDirty.value = false;
    loadReadings(system.id);
}

// Ogni risposta viene applicata solo se l'impianto aperto è ancora quello
// della richiesta: risposte in ritardo di un altro impianto vanno ignorate
function applyReadings(id, data) {
    if (detail.value?.id !== id) return false;
    readings.value = data.data;
    readingsSummary.value = data.summary;
    return true;
}

async function loadReadings(id) {
    readingError.value = '';
    try {
        const { data } = await axios.get(`/api/v1/irrigation-systems/${id}/readings`);
        applyReadings(id, data);
    } catch {
        if (detail.value?.id !== id) return;
        readings.value = [];
        readingsSummary.value = null;
        readingError.value = 'Letture del contatore non caricate: riapri il dettaglio per riprovare.';
    }
}

async function addReading() {
    const id = detail.value.id;
    readingBusy.value = true;
    readingError.value = '';
    try {
        const { data } = await axios.post(`/api/v1/irrigation-systems/${id}/readings`, {
            read_on: newReading.read_on,
            value_m3: newReading.value_m3,
            note: newReading.note.trim() || null,
        });
        if (applyReadings(id, data)) {
            newReading.value_m3 = null;
            newReading.note = '';
        }
    } catch (err) {
        if (detail.value?.id === id) {
            readingError.value = firstError(err, 'Registrazione della lettura non riuscita');
        }
    } finally {
        readingBusy.value = false;
    }
}

async function deleteReading(reading) {
    if (! window.confirm(`Eliminare la lettura del ${formatDate(reading.read_on)}?`)) return;
    const id = detail.value.id;
    readingError.value = '';
    try {
        const { data } = await axios.delete(`/api/v1/irrigation-systems/${id}/readings/${reading.id}`);
        applyReadings(id, data);
    } catch (err) {
        if (detail.value?.id === id) {
            readingError.value = firstError(err, 'Eliminazione della lettura non riuscita');
        }
    }
}

function addSector() {
    markDirty();
    sectors.value.push({ name: '', description: '', flow_lpm: null, run_minutes: null, runs_per_week: null });
}

function removeSector(index) {
    markDirty();
    sectors.value.splice(index, 1);
}

// Controlli locali prima dell'invio: intercettano gli errori più comuni
// senza passare dal server (e senza salvataggi a metà)
function sectorsClientError() {
    const names = sectors.value.map((s) => s.name.trim().toLowerCase());
    if (new Set(names).size !== names.length) {
        return 'Due settori hanno lo stesso nome: rinominane uno.';
    }
    for (const s of sectors.value) {
        const label = s.name.trim() || 'senza nome';
        if (s.flow_lpm !== null && s.flow_lpm !== '' && Number(s.flow_lpm) < 0) {
            return `La portata del settore "${label}" non può essere negativa.`;
        }
        if (s.run_minutes !== null && s.run_minutes !== '' && (s.run_minutes < 1 || s.run_minutes > 1440)) {
            return `I minuti per ciclo del settore "${label}" devono essere tra 1 e 1440.`;
        }
        if (s.runs_per_week !== null && s.runs_per_week !== '' && (s.runs_per_week < 1 || s.runs_per_week > 28)) {
            return `I cicli a settimana del settore "${label}" devono essere tra 1 e 28.`;
        }
    }
    if (form.season_opens_on && form.season_closes_on && form.season_closes_on < form.season_opens_on) {
        return 'La chiusura della stagione precede l\'apertura.';
    }
    return '';
}

async function saveDetail() {
    const clientError = sectorsClientError();
    if (clientError) {
        detailError.value = clientError;
        return;
    }
    detailBusy.value = true;
    detailError.value = '';
    detailSaved.value = false;
    // Il salvataggio avviene in due chiamate (impianto, poi settori): se la
    // seconda fallisce bisogna dirlo chiaramente, perché la prima è già salvata
    let systemSaved = false;
    try {
        const patched = await axios.patch(`/api/v1/irrigation-systems/${detail.value.id}`, {
            version: detail.value.version,
            area_id: form.area_id,
            name: form.name.trim(),
            system_type: form.system_type,
            water_source: form.water_source || null,
            controller_model: form.controller_model.trim() || null,
            status: form.status,
            season_opens_on: form.season_opens_on || null,
            season_closes_on: form.season_closes_on || null,
            notes: form.notes.trim() || null,
        });
        detail.value.version = patched.data.data.version;
        systemSaved = true;
        const { data } = await axios.put(`/api/v1/irrigation-systems/${detail.value.id}/sectors`, {
            version: detail.value.version,
            sectors: sectors.value.map((s) => ({
                name: s.name.trim(),
                description: s.description.trim() || null,
                flow_lpm: s.flow_lpm === '' ? null : s.flow_lpm,
                run_minutes: s.run_minutes === '' ? null : s.run_minutes,
                runs_per_week: s.runs_per_week === '' ? null : s.runs_per_week,
            })),
        });
        setDetail(data.data);
        detailSaved.value = true;
        await load();
    } catch (err) {
        if (err.response?.status === 409) {
            detailError.value = 'Un altro utente ha modificato questo impianto nel frattempo: chiudi e riapri il dettaglio per ripartire dai dati aggiornati.';
        } else if (systemSaved) {
            detailError.value = 'I dati dell\'impianto sono stati salvati, ma i settori no: '
                + firstError(err, 'errore nel salvataggio dei settori')
                + ' Correggi e premi di nuovo Salva.';
        } else {
            detailError.value = firstError(err, 'Errore nel salvataggio');
        }
    } finally {
        detailBusy.value = false;
    }
}

async function createWorkOrder() {
    if (! window.confirm(`Creare un ordine di lavoro in bozza per la manutenzione dell'impianto ${detail.value.name}?`)) return;
    detailError.value = '';
    workOrderMessage.value = '';
    try {
        const { data } = await axios.post(`/api/v1/irrigation-systems/${detail.value.id}/work-order`);
        workOrderMessage.value = `Ordine ${data.data.code} creato in bozza: lo trovi nella pagina Lavori, pronto da programmare.`;
    } catch (err) {
        detailError.value = firstError(err, "Creazione dell'ordine non riuscita");
    }
}

async function deleteSystem() {
    if (! window.confirm(`Eliminare l'impianto ${detail.value.name} e i suoi settori?`)) return;
    detailError.value = '';
    try {
        await axios.delete(`/api/v1/irrigation-systems/${detail.value.id}`);
        detail.value = null;
        await load();
    } catch (err) {
        detailError.value = firstError(err, 'Eliminazione non riuscita');
    }
}

onMounted(load);
</script>

<template>
    <Head title="Irrigazione" />

    <AppLayout>
        <div class="p-6">
            <div class="mb-4 flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-semibold">Impianti di irrigazione</h1>
                    <p class="text-sm text-gray-500">Impianti per area con settori, portate e stagione di esercizio; le manutenzioni diventano ordini di lavoro</p>
                </div>
                <button
                    v-if="canManage"
                    class="rounded-lg bg-green-700 px-4 py-2 text-sm font-medium text-white hover:bg-green-800"
                    data-test="irr-new"
                    @click="creator.open = true"
                >Nuovo impianto</button>
            </div>

            <p v-if="pageError" class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ pageError }}</p>

            <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wide text-gray-400">
                            <th class="px-4 py-2.5 font-medium">Impianto</th>
                            <th class="px-4 py-2.5 font-medium">Area</th>
                            <th class="px-4 py-2.5 font-medium">Tipo</th>
                            <th class="px-4 py-2.5 font-medium">Fonte idrica</th>
                            <th class="px-4 py-2.5 text-right font-medium">Settori</th>
                            <th class="px-4 py-2.5 font-medium">Stagione</th>
                            <th class="px-4 py-2.5 font-medium">Stato</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        <tr
                            v-for="s in systems"
                            :key="s.id"
                            class="cursor-pointer hover:bg-green-50/40"
                            data-test="irr-row"
                            @click="openDetail(s.id)"
                        >
                            <td class="px-4 py-2 font-medium">{{ s.name }}</td>
                            <td class="px-4 py-2 text-gray-600">{{ s.area?.name ?? '—' }}</td>
                            <td class="px-4 py-2 text-gray-600">{{ TYPE_LABELS[s.system_type] ?? s.system_type }}</td>
                            <td class="px-4 py-2 text-gray-600">{{ s.water_source ? (SOURCE_LABELS[s.water_source] ?? s.water_source) : '—' }}</td>
                            <td class="px-4 py-2 text-right">{{ s.sectors_count }}</td>
                            <td class="px-4 py-2 text-gray-600">{{ seasonLabel(s) }}</td>
                            <td class="px-4 py-2">
                                <span class="rounded-full px-2.5 py-0.5 text-xs font-medium" :class="STATUS_BADGE[s.status] ?? 'bg-gray-200 text-gray-500'">
                                    {{ STATUS_LABELS[s.status] ?? s.status }}
                                </span>
                            </td>
                        </tr>
                        <tr v-if="! systems.length && ! loading && ! pageError">
                            <td colspan="7" class="px-4 py-8 text-center text-gray-400">Nessun impianto registrato. Aggiungi il primo per tenerne il programma stagionale.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Creazione -->
            <Teleport to="body">
                <div v-if="creator.open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4" @click.self="creator.open = false">
                    <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
                        <div class="flex items-start justify-between">
                            <h2 class="font-semibold">Nuovo impianto</h2>
                            <button class="text-gray-400 hover:text-gray-600" @click="creator.open = false">✕</button>
                        </div>
                        <div class="mt-4 space-y-3">
                            <label class="block text-xs">
                                <span class="text-gray-500">Area *</span>
                                <select v-model="creator.form.area_id" data-test="irr-area" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm">
                                    <option value="" disabled>Seleziona…</option>
                                    <option v-for="a in areas" :key="a.id" :value="a.id">{{ a.name }} ({{ a.code }})</option>
                                </select>
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Nome impianto *</span>
                                <input v-model="creator.form.name" data-test="irr-name" maxlength="150" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm" placeholder="es. Impianto parco nord">
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Tipo</span>
                                <select v-model="creator.form.system_type" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm">
                                    <option v-for="(label, value) in TYPE_LABELS" :key="value" :value="value">{{ label }}</option>
                                </select>
                            </label>
                        </div>
                        <p v-if="creator.error" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ creator.error }}</p>
                        <button
                            class="mt-4 w-full rounded-lg bg-green-700 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50"
                            :disabled="creator.busy || ! creator.form.area_id || ! creator.form.name.trim()"
                            data-test="irr-save"
                            @click="createSystem"
                        >{{ creator.busy ? 'Creazione…' : 'Crea impianto' }}</button>
                    </div>
                </div>
            </Teleport>

            <!-- Dettaglio -->
            <Teleport to="body">
                <div v-if="detail" class="fixed inset-0 z-50 flex justify-end bg-black/30" @click.self="closeDetail">
                    <div class="h-full w-full max-w-3xl overflow-y-auto bg-white p-6 shadow-2xl" data-test="irr-detail">
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="text-xs uppercase tracking-wide text-gray-400">{{ detail.area?.name }}</div>
                                <h2 class="text-lg font-semibold">{{ detail.name }}</h2>
                            </div>
                            <button class="text-gray-400 hover:text-gray-600" @click="closeDetail">✕</button>
                        </div>

                        <p v-if="detailError" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" data-test="irr-error">{{ detailError }}</p>
                        <p v-if="detailSaved" class="mt-3 rounded-lg bg-green-100 px-3 py-2 text-sm text-green-900" data-test="irr-saved">Impianto e settori salvati.</p>
                        <p v-if="workOrderMessage" class="mt-3 rounded-lg bg-green-100 px-3 py-2 text-sm text-green-900" data-test="irr-wo-ok">{{ workOrderMessage }}</p>

                        <div class="mt-4 grid grid-cols-2 gap-3">
                            <label class="block text-xs">
                                <span class="text-gray-500">Area</span>
                                <select v-model="form.area_id" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm disabled:bg-gray-50" :disabled="! canManage" @change="markDirty">
                                    <option v-for="a in areas" :key="a.id" :value="a.id">{{ a.name }} ({{ a.code }})</option>
                                </select>
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Nome impianto</span>
                                <input v-model="form.name" maxlength="150" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm disabled:bg-gray-50" :disabled="! canManage" @input="markDirty">
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Tipo</span>
                                <select v-model="form.system_type" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm disabled:bg-gray-50" :disabled="! canManage" @change="markDirty">
                                    <option v-for="(label, value) in TYPE_LABELS" :key="value" :value="value">{{ label }}</option>
                                </select>
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Fonte idrica</span>
                                <select v-model="form.water_source" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm disabled:bg-gray-50" :disabled="! canManage" @change="markDirty">
                                    <option value="">—</option>
                                    <option v-for="(label, value) in SOURCE_LABELS" :key="value" :value="value">{{ label }}</option>
                                </select>
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Centralina (marca e modello)</span>
                                <input v-model="form.controller_model" maxlength="150" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm disabled:bg-gray-50" :disabled="! canManage" @input="markDirty">
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Stato</span>
                                <select v-model="form.status" data-test="irr-status" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm disabled:bg-gray-50" :disabled="! canManage" @change="markDirty">
                                    <option v-for="(label, value) in STATUS_LABELS" :key="value" :value="value">{{ label }}</option>
                                </select>
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Apertura stagione</span>
                                <input v-model="form.season_opens_on" type="date" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm disabled:bg-gray-50" :disabled="! canManage" @input="markDirty">
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Chiusura stagione</span>
                                <input v-model="form.season_closes_on" type="date" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm disabled:bg-gray-50" :disabled="! canManage" @input="markDirty">
                            </label>
                            <label class="col-span-2 block text-xs">
                                <span class="text-gray-500">Note (contatore, pressioni, avvertenze)</span>
                                <textarea v-model="form.notes" rows="2" maxlength="2000" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm disabled:bg-gray-50" :disabled="! canManage" @input="markDirty" />
                            </label>
                        </div>

                        <h3 class="mt-5 text-sm font-semibold">Settori ({{ sectors.length }})</h3>
                        <table class="mt-2 w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wide text-gray-400">
                                    <th class="py-2 pr-2 font-medium">Nome</th>
                                    <th class="py-2 pr-2 font-medium">Descrizione</th>
                                    <th class="w-24 py-2 pr-2 font-medium" title="Portata in litri al minuto">Portata l/min</th>
                                    <th class="w-20 py-2 pr-2 font-medium" title="Durata di un ciclo in minuti">Minuti</th>
                                    <th class="w-24 py-2 pr-2 font-medium" title="Cicli alla settimana">Cicli/sett.</th>
                                    <th v-if="canManage" class="w-8 py-2" />
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                <tr v-for="(sector, index) in sectors" :key="index" data-test="irr-sector-row">
                                    <td class="py-1.5 pr-2">
                                        <input v-model="sector.name" maxlength="120" class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm disabled:bg-gray-50" :disabled="! canManage" placeholder="es. Prato est" data-test="irr-sector-name" @input="markDirty">
                                    </td>
                                    <td class="py-1.5 pr-2">
                                        <input v-model="sector.description" maxlength="500" class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm disabled:bg-gray-50" :disabled="! canManage" @input="markDirty">
                                    </td>
                                    <td class="py-1.5 pr-2">
                                        <input v-model.number="sector.flow_lpm" type="number" step="0.1" min="0" class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-right text-sm disabled:bg-gray-50" :disabled="! canManage" @input="markDirty">
                                    </td>
                                    <td class="py-1.5 pr-2">
                                        <input v-model.number="sector.run_minutes" type="number" min="1" max="1440" class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-right text-sm disabled:bg-gray-50" :disabled="! canManage" @input="markDirty">
                                    </td>
                                    <td class="py-1.5 pr-2">
                                        <input v-model.number="sector.runs_per_week" type="number" min="1" max="28" class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-right text-sm disabled:bg-gray-50" :disabled="! canManage" @input="markDirty">
                                    </td>
                                    <td v-if="canManage" class="py-1.5 text-right">
                                        <button class="text-xs font-medium text-red-600 hover:underline" @click="removeSector(index)">✕</button>
                                    </td>
                                </tr>
                                <tr v-if="! sectors.length">
                                    <td :colspan="canManage ? 6 : 5" class="py-4 text-center text-sm text-gray-400">Nessun settore: aggiungi le zone servite dall'impianto.</td>
                                </tr>
                            </tbody>
                        </table>

                        <div v-if="canManage" class="mt-3 flex items-center justify-between">
                            <button class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm" data-test="irr-add-sector" @click="addSector">Aggiungi settore</button>
                            <button
                                class="rounded-lg bg-green-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                                :disabled="detailBusy || ! form.name.trim() || sectors.some((s) => ! s.name.trim())"
                                data-test="irr-save-detail"
                                @click="saveDetail"
                            >{{ detailBusy ? 'Salvataggio…' : 'Salva impianto e settori' }}</button>
                        </div>

                        <h3 class="mt-6 text-sm font-semibold">Contatore e consumi</h3>
                        <p class="mt-1 text-xs text-gray-500" data-test="irr-readings-summary">
                            Stima dal programma dei settori:
                            <!-- Con 0 la stima non è "zero acqua": è non calcolabile (programma assente) -->
                            <span class="font-medium text-gray-700">{{ readingsSummary?.weekly_estimate_m3 > 0 ? `${readingsSummary.weekly_estimate_m3} m³/settimana` : '—' }}</span>
                            <template v-if="readingsSummary?.actual_weekly_m3 != null">
                                · Consumo reale (ultime due letture):
                                <span class="font-medium text-gray-700">{{ readingsSummary.actual_weekly_m3 }} m³/settimana</span>
                            </template>
                            <template v-if="deviationPct != null">
                                · Scostamento:
                                <span class="font-medium" :class="deviationPct > 25 ? 'text-red-700' : 'text-gray-700'">{{ deviationPct > 0 ? '+' : '' }}{{ deviationPct }}%</span>
                            </template>
                        </p>

                        <p v-if="readingError" class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" data-test="irr-reading-error">{{ readingError }}</p>

                        <table class="mt-2 w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wide text-gray-400">
                                    <th class="py-2 pr-2 font-medium">Data</th>
                                    <th class="w-28 py-2 pr-2 text-right font-medium">Lettura m³</th>
                                    <th class="w-40 py-2 pr-2 text-right font-medium">Consumo dal precedente</th>
                                    <th class="py-2 pr-2 font-medium">Note</th>
                                    <th v-if="canManage" class="w-8 py-2" />
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                <tr v-for="r in readings" :key="r.id" data-test="irr-reading-row">
                                    <td class="py-1.5 pr-2">{{ formatDate(r.read_on) }}</td>
                                    <td class="py-1.5 pr-2 text-right">{{ r.value_m3 }}</td>
                                    <td class="py-1.5 pr-2 text-right text-gray-600">
                                        <template v-if="r.consumption_m3 != null">{{ r.consumption_m3 }} m³ in {{ r.days_since_previous }} g</template>
                                        <template v-else-if="r.days_since_previous != null">non calcolabile (contatore azzerato?)</template>
                                        <template v-else>—</template>
                                    </td>
                                    <td class="py-1.5 pr-2 text-gray-600">{{ r.note ?? '—' }}</td>
                                    <td v-if="canManage" class="py-1.5 text-right">
                                        <button class="text-xs font-medium text-red-600 hover:underline" @click="deleteReading(r)">✕</button>
                                    </td>
                                </tr>
                                <tr v-if="! readings.length">
                                    <td :colspan="canManage ? 5 : 4" class="py-4 text-center text-sm text-gray-400">Nessuna lettura registrata.</td>
                                </tr>
                            </tbody>
                        </table>

                        <div v-if="canManage" class="mt-2 flex flex-wrap items-end gap-2">
                            <label class="block text-xs">
                                <span class="text-gray-500">Data lettura</span>
                                <input v-model="newReading.read_on" type="date" data-test="irr-reading-date" class="mt-1 rounded-lg border border-gray-300 px-2.5 py-2 text-sm">
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Valore contatore (m³)</span>
                                <input v-model.number="newReading.value_m3" type="number" step="0.01" min="0" data-test="irr-reading-value" class="mt-1 w-36 rounded-lg border border-gray-300 px-2.5 py-2 text-right text-sm">
                            </label>
                            <label class="block flex-1 text-xs">
                                <span class="text-gray-500">Nota (facoltativa)</span>
                                <input v-model="newReading.note" maxlength="500" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm">
                            </label>
                            <button
                                class="rounded-lg border border-gray-300 px-3 py-2 text-sm disabled:opacity-50"
                                :disabled="readingBusy || ! newReading.read_on || newReading.value_m3 == null || newReading.value_m3 === ''"
                                data-test="irr-reading-add"
                                @click="addReading"
                            >{{ readingBusy ? 'Registrazione…' : 'Registra lettura' }}</button>
                        </div>

                        <div v-if="canWorks || canManage" class="mt-8 flex items-center gap-6 border-t border-gray-100 pt-4">
                            <button
                                v-if="canWorks"
                                class="text-sm font-medium text-gray-700 hover:underline"
                                data-test="irr-workorder"
                                @click="createWorkOrder"
                            >Genera ordine di manutenzione</button>
                            <button v-if="canManage" class="text-sm font-medium text-red-600 hover:underline" data-test="irr-delete" @click="deleteSystem">
                                Elimina impianto
                            </button>
                        </div>
                    </div>
                </div>
            </Teleport>
        </div>
    </AppLayout>
</template>
