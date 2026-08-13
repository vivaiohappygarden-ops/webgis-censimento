<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';

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

async function load() {
    loading.value = true;
    try {
        const [sy, ar] = await Promise.all([
            axios.get('/api/v1/irrigation-systems'),
            axios.get('/api/v1/areas', { params: { per_page: 100 } }),
        ]);
        systems.value = sy.data.data;
        areas.value = ar.data.data;
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
}

function addSector() {
    markDirty();
    sectors.value.push({ name: '', description: '', flow_lpm: null, run_minutes: null, runs_per_week: null });
}

function removeSector(index) {
    markDirty();
    sectors.value.splice(index, 1);
}

async function saveDetail() {
    detailBusy.value = true;
    detailError.value = '';
    detailSaved.value = false;
    try {
        await axios.patch(`/api/v1/irrigation-systems/${detail.value.id}`, {
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
        const { data } = await axios.put(`/api/v1/irrigation-systems/${detail.value.id}/sectors`, {
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
        detailError.value = firstError(err, 'Errore nel salvataggio');
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

            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
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
                        <tr v-if="! systems.length && ! loading">
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
