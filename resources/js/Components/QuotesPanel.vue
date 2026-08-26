<script setup>
import { onMounted, reactive, ref } from 'vue';
import axios from 'axios';
import ScegliCommittente from '@/Components/ScegliCommittente.vue';
import ScegliVoce from '@/Components/ScegliVoce.vue';
import { avvisoCaricamento } from '@/avvisi';
import { fetchPdf } from '@/pdf';

const props = defineProps({
    clients: { type: Array, default: () => [] },
    workTypes: { type: Array, default: () => [] },
    canManage: { type: Boolean, default: false },
});

const emit = defineEmits(['created-order']);

const STATUS = {
    draft: { label: 'Bozza', cls: 'bg-gray-200 text-gray-600' },
    sent: { label: 'Inviato', cls: 'bg-amber-100 text-amber-800' },
    accepted: { label: 'Accettato', cls: 'bg-green-100 text-green-800' },
    rejected: { label: 'Rifiutato', cls: 'bg-red-100 text-red-800' },
};
const TRANSITION_LABELS = {
    sent: 'Segna come inviato',
    accepted: 'Segna accettato',
    rejected: 'Segna rifiutato',
    draft: 'Riporta in bozza',
};

const estimates = ref([]);
const loading = ref(false);
const pageError = ref('');

const creator = reactive({
    open: false, busy: false, error: '',
    form: { client_id: '', title: '', valid_until: '' },
});

const detail = ref(null);
const items = ref([]);
const detailBusy = ref(false);
const detailError = ref('');
const detailSaved = ref(false);
const workOrderMessage = ref('');
const editorDirty = ref(false);

const fmtEur = (n) => (Number(n) || 0).toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const fmtDate = (v) => {
    if (! v) return '—';
    const [y, m, d] = String(v).slice(0, 10).split('-');
    return `${d}/${m}/${y}`;
};

function firstError(err, fallback) {
    return Object.values(err.response?.data?.errors ?? {})[0]?.[0]
        ?? err.response?.data?.message ?? fallback;
}

function markDirty() {
    editorDirty.value = true;
    detailSaved.value = false;
}

// Con voci modificate e non salvate, stati e PDF lavorerebbero sui dati
// vecchi del server: meglio fermarsi e chiedere di salvare prima.
function blockIfDirty() {
    if (editorDirty.value && detail.value?.status === 'draft') {
        detailError.value = 'Ci sono voci modificate e non salvate: salva prima le voci.';
        return true;
    }
    return false;
}

function closeDetail() {
    if (editorDirty.value && props.canManage && detail.value?.status === 'draft'
        && ! window.confirm('Chiudere senza salvare le modifiche? Andranno perse.')) {
        return;
    }
    detail.value = null;
}

async function load() {
    loading.value = true;
    pageError.value = '';
    try {
        const { data } = await axios.get('/api/v1/estimates');
        estimates.value = data.data;
    } catch (err) {
        pageError.value = avvisoCaricamento(err);
    } finally {
        loading.value = false;
    }
}

function openCreator() {
    creator.error = '';
    creator.form = { client_id: '', title: '', valid_until: '' };
    creator.open = true;
}

async function createEstimate() {
    creator.busy = true;
    creator.error = '';
    try {
        const { data } = await axios.post('/api/v1/estimates', {
            client_id: creator.form.client_id,
            title: creator.form.title.trim(),
            valid_until: creator.form.valid_until || null,
        });
        creator.open = false;
        await load();
        openDetailData(data.data);
    } catch (err) {
        creator.error = firstError(err, 'Creazione non riuscita');
    } finally {
        creator.busy = false;
    }
}

function openDetailData(estimate) {
    detail.value = estimate;
    items.value = (estimate.items ?? []).map((i) => ({
        work_type_id: i.work_type_id ?? '',
        description: i.description,
        unit: i.unit,
        quantity: Number(i.quantity),
        unit_price: Number(i.unit_price),
    }));
    detailError.value = '';
    detailSaved.value = false;
    workOrderMessage.value = '';
    editorDirty.value = false;
}

async function openDetail(id) {
    pageError.value = '';
    try {
        const { data } = await axios.get(`/api/v1/estimates/${id}`);
        openDetailData(data.data);
    } catch {
        pageError.value = 'Apertura non riuscita: il preventivo potrebbe essere stato eliminato.';
        await load();
    }
}

function addItem() {
    markDirty();
    items.value.push({ work_type_id: '', description: '', unit: '', quantity: 1, unit_price: null });
}

function removeItem(index) {
    markDirty();
    items.value.splice(index, 1);
}

function onWorkTypeChange(item) {
    markDirty();
    const workType = props.workTypes.find((w) => w.id === item.work_type_id);
    if (workType) {
        if (! item.unit) item.unit = workType.unit ?? '';
        if (! item.description) item.description = workType.name;
    }
}

async function saveItems() {
    detailBusy.value = true;
    detailError.value = '';
    detailSaved.value = false;
    try {
        const { data } = await axios.put(`/api/v1/estimates/${detail.value.id}/items`, {
            version: detail.value.version,
            items: items.value.map((i) => ({
                work_type_id: i.work_type_id || null,
                description: i.description.trim(),
                unit: i.unit.trim(),
                quantity: i.quantity,
                unit_price: i.unit_price,
            })),
        });
        openDetailData(data.data);
        detailSaved.value = true;
        await load();
    } catch (err) {
        detailError.value = err.response?.status === 409
            ? 'Un altro utente ha modificato questo preventivo: chiudi e riapri il dettaglio.'
            : firstError(err, 'Salvataggio non riuscito');
    } finally {
        detailBusy.value = false;
    }
}

async function doTransition(status) {
    detailError.value = '';
    if (blockIfDirty()) return;
    workOrderMessage.value = '';
    detailBusy.value = true;
    try {
        const { data } = await axios.post(`/api/v1/estimates/${detail.value.id}/transition`, {
            status,
            version: detail.value.version,
        });
        openDetailData(data.data);
        await load();
    } catch (err) {
        detailError.value = err.response?.status === 409
            ? 'Un altro utente ha modificato questo preventivo: chiudi e riapri il dettaglio.'
            : firstError(err, 'Cambio di stato non riuscito');
    } finally {
        detailBusy.value = false;
    }
}

async function generateWorkOrder() {
    if (! window.confirm(`Creare l'ordine di lavoro dal preventivo ${detail.value.code}?`)) return;
    detailError.value = '';
    detailBusy.value = true;
    try {
        const { data } = await axios.post(`/api/v1/estimates/${detail.value.id}/work-order`);
        workOrderMessage.value = `Ordine ${data.data.code} creato in bozza: lo trovi nella vista Elenco.`;
        emit('created-order');
    } catch (err) {
        detailError.value = firstError(err, 'Creazione non riuscita');
    } finally {
        detailBusy.value = false;
    }
}

async function deleteEstimate() {
    if (! window.confirm(`Eliminare la bozza ${detail.value.code}?`)) return;
    detailError.value = '';
    try {
        await axios.delete(`/api/v1/estimates/${detail.value.id}`);
        detail.value = null;
        await load();
    } catch (err) {
        detailError.value = firstError(err, 'Eliminazione non riuscita');
    }
}

async function openPdf() {
    detailError.value = '';
    if (blockIfDirty()) return;
    const res = await fetchPdf(`/api/v1/estimates/${detail.value.id}/pdf`);
    if (res?.error) detailError.value = res.error;
}

onMounted(load);

defineExpose({ load });
</script>

<template>
    <div>
        <div class="mb-3 flex items-center justify-between">
            <p class="text-sm text-gray-500">Offerte al cliente con lavorazioni dal catalogo o voci libere; l'accettazione diventa un ordine di lavoro</p>
            <button
                v-if="canManage"
                class="rounded-lg bg-green-700 px-4 py-2 text-sm font-medium text-white hover:bg-green-800"
                data-test="est-new"
                @click="openCreator"
            >Nuovo preventivo</button>
        </div>

        <p v-if="pageError" class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ pageError }}</p>

        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wide text-gray-400">
                        <th class="px-4 py-2.5 font-medium">Codice</th>
                        <th class="px-4 py-2.5 font-medium">Cliente</th>
                        <th class="px-4 py-2.5 font-medium">Oggetto</th>
                        <th class="px-4 py-2.5 text-right font-medium">Voci</th>
                        <th class="px-4 py-2.5 text-right font-medium">Imponibile (EUR)</th>
                        <th class="px-4 py-2.5 font-medium">Validità</th>
                        <th class="px-4 py-2.5 font-medium">Stato</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    <tr
                        v-for="estimate in estimates"
                        :key="estimate.id"
                        class="cursor-pointer hover:bg-green-50/40"
                        data-test="est-row"
                        @click="openDetail(estimate.id)"
                    >
                        <td class="px-4 py-2 font-medium">{{ estimate.code }}</td>
                        <td class="px-4 py-2">{{ estimate.client?.name }}</td>
                        <td class="max-w-56 truncate px-4 py-2" :title="estimate.title">{{ estimate.title }}</td>
                        <td class="px-4 py-2 text-right">{{ estimate.items_count }}</td>
                        <td class="px-4 py-2 text-right">{{ fmtEur(estimate.subtotal) }}</td>
                        <td class="px-4 py-2 text-gray-600">{{ fmtDate(estimate.valid_until) }}</td>
                        <td class="px-4 py-2">
                            <span class="rounded-full px-2.5 py-0.5 text-xs font-medium" :class="STATUS[estimate.status]?.cls">
                                {{ STATUS[estimate.status]?.label ?? estimate.status }}
                            </span>
                        </td>
                    </tr>
                    <tr v-if="! estimates.length && ! loading && ! pageError">
                        <td colspan="7" class="px-4 py-8 text-center text-gray-400">Nessun preventivo. Crea il primo per un tuo cliente.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Creazione -->
        <Teleport to="body">
            <div v-if="creator.open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4" @click.self="creator.open = false">
                <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
                    <div class="flex items-start justify-between">
                        <h2 class="font-semibold">Nuovo preventivo</h2>
                        <button class="text-gray-400 hover:text-gray-600" @click="creator.open = false">✕</button>
                    </div>
                    <div class="mt-4 space-y-3">
                        <label class="block text-xs">
                            <span class="text-gray-500">Cliente *</span>
                            <ScegliCommittente v-model="creator.form.client_id" data-test="est-client" class="mt-1 w-full" campo-classe="px-2.5 py-2 text-sm" :committenti="clients" />
                        </label>
                        <label class="block text-xs">
                            <span class="text-gray-500">Oggetto *</span>
                            <input v-model="creator.form.title" data-test="est-title" maxlength="200" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm" placeholder="es. Potatura siepi perimetrali 2026">
                        </label>
                        <label class="block text-xs">
                            <span class="text-gray-500">Validità dell'offerta (facoltativa)</span>
                            <input v-model="creator.form.valid_until" type="date" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm">
                        </label>
                    </div>
                    <p v-if="creator.error" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ creator.error }}</p>
                    <button
                        class="mt-4 w-full rounded-lg bg-green-700 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50"
                        :disabled="creator.busy || ! creator.form.client_id || ! creator.form.title.trim()"
                        data-test="est-save"
                        @click="createEstimate"
                    >{{ creator.busy ? 'Creazione…' : 'Crea preventivo' }}</button>
                </div>
            </div>
        </Teleport>

        <!-- Dettaglio -->
        <Teleport to="body">
            <div v-if="detail" class="fixed inset-0 z-50 flex justify-end bg-black/30" @click.self="closeDetail">
                <div class="h-full w-full max-w-3xl overflow-y-auto bg-white p-6 shadow-2xl" data-test="est-detail">
                    <div class="flex items-start justify-between">
                        <div>
                            <div class="text-xs uppercase tracking-wide text-gray-400">
                                {{ detail.code }} ·
                                <span class="rounded-full px-2 py-0.5 text-[10px] font-medium" :class="STATUS[detail.status]?.cls">{{ STATUS[detail.status]?.label }}</span>
                            </div>
                            <h2 class="text-lg font-semibold">{{ detail.title }}</h2>
                            <p class="text-xs text-gray-500">{{ detail.client?.name }}<template v-if="detail.valid_until"> · valido fino al {{ fmtDate(detail.valid_until) }}</template></p>
                        </div>
                        <button class="text-gray-400 hover:text-gray-600" @click="closeDetail">✕</button>
                    </div>

                    <p v-if="detailError" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" data-test="est-error">{{ detailError }}</p>
                    <p v-if="detailSaved" class="mt-3 rounded-lg bg-green-100 px-3 py-2 text-sm text-green-900" data-test="est-saved">Voci salvate.</p>
                    <p v-if="workOrderMessage" class="mt-3 rounded-lg bg-green-100 px-3 py-2 text-sm text-green-900" data-test="est-wo-ok">{{ workOrderMessage }}</p>

                    <h3 class="mt-5 text-sm font-semibold">Voci ({{ items.length }})</h3>
                    <table class="mt-2 w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wide text-gray-400">
                                <th class="w-40 py-2 pr-2 font-medium">Lavorazione</th>
                                <th class="py-2 pr-2 font-medium">Descrizione</th>
                                <th class="w-16 py-2 pr-2 font-medium">Unità</th>
                                <th class="w-20 py-2 pr-2 font-medium">Quantità</th>
                                <th class="w-24 py-2 pr-2 font-medium">Prezzo unit.</th>
                                <th v-if="canManage && detail.status === 'draft'" class="w-8 py-2" />
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <tr v-for="(item, index) in items" :key="index" data-test="est-item">
                                <td class="py-1.5 pr-2">
                                    <ScegliVoce v-model="item.work_type_id" class="w-full" campo-classe="px-2 py-1.5 text-sm" :voci="workTypes" :campi-ricerca="['name', 'code']" :disabilitato="! canManage || detail.status !== 'draft'" tutti="Voce libera" vuoto="Nessuna lavorazione trovata." @cambia="onWorkTypeChange(item)" />
                                </td>
                                <td class="py-1.5 pr-2">
                                    <input v-model="item.description" maxlength="300" data-test="est-item-desc" class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm disabled:bg-gray-50" :disabled="! canManage || detail.status !== 'draft'" @input="markDirty">
                                </td>
                                <td class="py-1.5 pr-2">
                                    <input v-model="item.unit" maxlength="20" class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm disabled:bg-gray-50" :disabled="! canManage || detail.status !== 'draft'" placeholder="mq" @input="markDirty">
                                </td>
                                <td class="py-1.5 pr-2">
                                    <input v-model.number="item.quantity" type="number" step="0.01" min="0.01" class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-right text-sm disabled:bg-gray-50" :disabled="! canManage || detail.status !== 'draft'" @input="markDirty">
                                </td>
                                <td class="py-1.5 pr-2">
                                    <input v-model.number="item.unit_price" type="number" step="0.01" min="0" data-test="est-item-price" class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-right text-sm disabled:bg-gray-50" :disabled="! canManage || detail.status !== 'draft'" @input="markDirty">
                                </td>
                                <td v-if="canManage && detail.status === 'draft'" class="py-1.5 text-right">
                                    <button class="text-xs font-medium text-red-600 hover:underline" @click="removeItem(index)">✕</button>
                                </td>
                            </tr>
                            <tr v-if="! items.length">
                                <td :colspan="canManage ? 6 : 5" class="py-4 text-center text-sm text-gray-400">Nessuna voce: aggiungi le lavorazioni da offrire.</td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="mt-3 flex items-center justify-between">
                        <button v-if="canManage && detail.status === 'draft'" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm" data-test="est-add-item" @click="addItem">Aggiungi voce</button>
                        <span v-else />
                        <div class="text-right text-sm">
                            <div class="text-gray-600">Imponibile: {{ fmtEur(detail.subtotal) }} EUR · IVA {{ Number(detail.vat_percent) }}%: {{ fmtEur(detail.vat) }} EUR</div>
                            <div class="font-semibold" data-test="est-total">Totale: {{ fmtEur(detail.total) }} EUR</div>
                        </div>
                    </div>

                    <div v-if="canManage && detail.status === 'draft'" class="mt-3 text-right">
                        <button
                            class="rounded-lg bg-green-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                            :disabled="detailBusy || items.some((i) => ! i.description.trim() || ! i.unit.trim() || ! (i.quantity > 0) || i.unit_price == null || i.unit_price === '')"
                            data-test="est-save-items"
                            @click="saveItems"
                        >{{ detailBusy ? 'Salvataggio…' : 'Salva le voci' }}</button>
                    </div>

                    <div class="mt-8 flex flex-wrap items-center gap-4 border-t border-gray-100 pt-4">
                        <button class="text-sm font-medium text-gray-700 hover:underline" data-test="est-pdf" @click="openPdf">Preventivo PDF</button>
                        <template v-if="canManage">
                            <button
                                v-for="target in detail.allowed_transitions"
                                :key="target"
                                class="text-sm font-medium hover:underline disabled:opacity-50"
                                :class="target === 'rejected' ? 'text-red-600' : 'text-green-800'"
                                :disabled="detailBusy"
                                :data-test="`est-to-${target}`"
                                @click="doTransition(target)"
                            >{{ TRANSITION_LABELS[target] }}</button>
                            <button
                                v-if="detail.status === 'accepted'"
                                class="text-sm font-medium text-green-800 hover:underline disabled:opacity-50"
                                :disabled="detailBusy"
                                data-test="est-workorder"
                                @click="generateWorkOrder"
                            >Genera ordine di lavoro</button>
                            <button
                                v-if="detail.status === 'draft'"
                                class="ml-auto text-sm font-medium text-red-600 hover:underline"
                                data-test="est-delete"
                                @click="deleteEstimate"
                            >Elimina bozza</button>
                        </template>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>
