<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';

const page = usePage();
const canManage = computed(() => (page.props.auth?.user?.permissions ?? []).includes('works.manage'));

const lists = ref([]);
const workTypes = ref([]);
const loading = ref(false);

const creator = reactive({ open: false, busy: false, error: '', form: { code: '', name: '', year: new Date().getFullYear(), source: '' } });

// Dettaglio con editor delle voci: si lavora su una copia locale e si
// salva l'elenco intero (il server sostituisce integralmente)
const detail = ref(null);
const items = ref([]);
const detailBusy = ref(false);
const detailError = ref('');
const detailSaved = ref(false);
const editorDirty = ref(false);
const pageError = ref('');

// Qualsiasi modifica ai campi: il banner "salvate" non deve più mentire
// e la chiusura del pannello chiede conferma
function markDirty() {
    editorDirty.value = true;
    detailSaved.value = false;
}

function closeDetail() {
    if (editorDirty.value && canManage.value
        && ! window.confirm('Chiudere senza salvare le voci modificate? Le modifiche andranno perse.')) {
        return;
    }
    detail.value = null;
}

async function load() {
    loading.value = true;
    try {
        const [l, wt] = await Promise.all([
            axios.get('/api/v1/price-lists'),
            axios.get('/api/v1/work-types'),
        ]);
        lists.value = l.data.data;
        workTypes.value = wt.data.data;
    } finally {
        loading.value = false;
    }
}

async function createList() {
    creator.busy = true;
    creator.error = '';
    try {
        const { data } = await axios.post('/api/v1/price-lists', {
            ...creator.form,
            source: creator.form.source || null,
        });
        creator.open = false;
        creator.form = { code: '', name: '', year: new Date().getFullYear(), source: '' };
        await load();
        await openDetail(data.data.id);
    } catch (err) {
        creator.error = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
            ?? err.response?.data?.message ?? 'Errore nella creazione';
    } finally {
        creator.busy = false;
    }
}

async function openDetail(id) {
    detailError.value = '';
    detailSaved.value = false;
    pageError.value = '';
    try {
        const { data } = await axios.get(`/api/v1/price-lists/${id}`);
        detail.value = data.data;
        items.value = data.data.items.map((i) => ({
            work_type_id: i.work_type_id,
            item_code: i.item_code ?? '',
            description: i.description ?? '',
            unit: i.unit,
            unit_price: Number(i.unit_price),
        }));
        editorDirty.value = false;
    } catch {
        pageError.value = 'Apertura del listino non riuscita: potrebbe essere stato eliminato. Ricarica la pagina.';
        await load();
    }
}

function addItem() {
    markDirty();
    items.value.push({ work_type_id: '', item_code: '', description: '', unit: '', unit_price: null });
}

function removeItem(index) {
    markDirty();
    items.value.splice(index, 1);
}

// L'unità di misura si propone dalla lavorazione scelta (resta modificabile)
function onWorkTypeChange(item) {
    markDirty();
    if (! item.unit) {
        item.unit = workTypes.value.find((w) => w.id === item.work_type_id)?.unit ?? '';
    }
}

async function saveItems() {
    detailBusy.value = true;
    detailError.value = '';
    detailSaved.value = false;
    try {
        const payload = items.value.map((i) => ({
            work_type_id: i.work_type_id,
            item_code: i.item_code.trim() || null,
            description: i.description.trim() || null,
            unit: i.unit.trim(),
            unit_price: i.unit_price,
        }));
        const { data } = await axios.put(`/api/v1/price-lists/${detail.value.id}/items`, { items: payload });
        detail.value = data.data;
        detailSaved.value = true;
        editorDirty.value = false;
        await load();
    } catch (err) {
        detailError.value = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
            ?? err.response?.data?.message ?? 'Errore nel salvataggio delle voci';
    } finally {
        detailBusy.value = false;
    }
}

async function deleteList() {
    if (! window.confirm(`Eliminare il listino ${detail.value.code}? L'operazione non è consentita se è usato da ordini di lavoro.`)) return;
    detailError.value = '';
    try {
        await axios.delete(`/api/v1/price-lists/${detail.value.id}`);
        detail.value = null;
        await load();
    } catch (err) {
        detailError.value = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
            ?? err.response?.data?.message ?? 'Eliminazione non riuscita';
    }
}

// Il percorso suggerito quando un listino non si può eliminare o correggere
async function toggleActive() {
    detailError.value = '';
    detailBusy.value = true;
    try {
        const { data } = await axios.patch(`/api/v1/price-lists/${detail.value.id}`, {
            is_active: ! detail.value.is_active,
        });
        detail.value = data.data;
        await load();
    } catch (err) {
        detailError.value = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
            ?? err.response?.data?.message ?? 'Aggiornamento non riuscito';
    } finally {
        detailBusy.value = false;
    }
}

onMounted(load);
</script>

<template>
    <Head title="Listini" />

    <AppLayout>
        <div class="p-6">
            <div class="mb-4 flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-semibold">Listini prezzi</h1>
                    <p class="text-sm text-gray-500">Prezzi unitari per lavorazione: valorizzano i consuntivi degli ordini di lavoro</p>
                </div>
                <button
                    v-if="canManage"
                    class="rounded-lg bg-green-700 px-4 py-2 text-sm font-medium text-white hover:bg-green-800"
                    data-test="new-pl"
                    @click="creator.open = true"
                >Nuovo listino</button>
            </div>

            <p v-if="pageError" class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ pageError }}</p>

            <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wide text-gray-400">
                            <th class="px-4 py-2.5 font-medium">Codice</th>
                            <th class="px-4 py-2.5 font-medium">Nome</th>
                            <th class="px-4 py-2.5 font-medium">Anno</th>
                            <th class="px-4 py-2.5 font-medium">Fonte</th>
                            <th class="px-4 py-2.5 text-right font-medium">Voci</th>
                            <th class="px-4 py-2.5 font-medium">Stato</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        <tr
                            v-for="l in lists"
                            :key="l.id"
                            class="cursor-pointer hover:bg-green-50/40"
                            data-test="pl-row"
                            @click="openDetail(l.id)"
                        >
                            <td class="px-4 py-2 font-medium">{{ l.code }}</td>
                            <td class="px-4 py-2">{{ l.name }}</td>
                            <td class="px-4 py-2 text-gray-600">{{ l.year ?? '—' }}</td>
                            <td class="px-4 py-2 text-gray-600">{{ l.source ?? '—' }}</td>
                            <td class="px-4 py-2 text-right">{{ l.items_count }}</td>
                            <td class="px-4 py-2">
                                <span class="rounded-full px-2.5 py-0.5 text-xs font-medium" :class="l.is_active ? 'bg-green-100 text-green-800' : 'bg-gray-200 text-gray-500'">
                                    {{ l.is_active ? 'Attivo' : 'Disattivato' }}
                                </span>
                            </td>
                        </tr>
                        <tr v-if="! lists.length && ! loading">
                            <td colspan="6" class="px-4 py-8 text-center text-gray-400">Nessun listino. Crea il primo per valorizzare i lavori.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Creazione -->
            <Teleport to="body">
                <div v-if="creator.open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4" @click.self="creator.open = false">
                    <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
                        <div class="flex items-start justify-between">
                            <h2 class="font-semibold">Nuovo listino</h2>
                            <button class="text-gray-400 hover:text-gray-600" @click="creator.open = false">✕</button>
                        </div>
                        <div class="mt-4 grid grid-cols-2 gap-3">
                            <label class="block text-xs">
                                <span class="text-gray-500">Codice *</span>
                                <input v-model="creator.form.code" data-test="pl-code" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm" placeholder="es. LIS-2026">
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Anno</span>
                                <input v-model.number="creator.form.year" type="number" min="2000" max="2100" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm">
                            </label>
                            <label class="col-span-2 block text-xs">
                                <span class="text-gray-500">Nome *</span>
                                <input v-model="creator.form.name" data-test="pl-name" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm" placeholder="es. Listino interno 2026">
                            </label>
                            <label class="col-span-2 block text-xs">
                                <span class="text-gray-500">Fonte (es. prezzario regionale, Assoverde)</span>
                                <input v-model="creator.form.source" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm">
                            </label>
                        </div>
                        <p v-if="creator.error" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ creator.error }}</p>
                        <button
                            class="mt-4 w-full rounded-lg bg-green-700 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50"
                            :disabled="creator.busy || ! creator.form.code.trim() || ! creator.form.name.trim()"
                            data-test="pl-save"
                            @click="createList"
                        >{{ creator.busy ? 'Creazione…' : 'Crea listino' }}</button>
                    </div>
                </div>
            </Teleport>

            <!-- Dettaglio: editor delle voci -->
            <Teleport to="body">
                <div v-if="detail" class="fixed inset-0 z-50 flex justify-end bg-black/30" @click.self="closeDetail">
                    <div class="h-full w-full max-w-3xl overflow-y-auto bg-white p-6 shadow-2xl" data-test="pl-detail">
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="text-xs uppercase tracking-wide text-gray-400">
                                    {{ detail.code }} <template v-if="detail.year">· {{ detail.year }}</template>
                                    <span v-if="! detail.is_active" class="ml-1 rounded-full bg-gray-200 px-2 py-0.5 text-[10px] font-medium text-gray-600">Disattivato</span>
                                </div>
                                <h2 class="text-lg font-semibold">{{ detail.name }}</h2>
                                <p v-if="detail.source" class="text-xs text-gray-500">Fonte: {{ detail.source }}</p>
                            </div>
                            <button class="text-gray-400 hover:text-gray-600" @click="closeDetail">✕</button>
                        </div>

                        <p v-if="detailError" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" data-test="pl-error">{{ detailError }}</p>
                        <p v-if="detailSaved" class="mt-3 rounded-lg bg-green-100 px-3 py-2 text-sm text-green-900" data-test="pl-saved">Voci del listino salvate.</p>

                        <h3 class="mt-5 text-sm font-semibold">Voci di prezzo ({{ items.length }})</h3>
                        <table class="mt-2 w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wide text-gray-400">
                                    <th class="py-2 pr-2 font-medium">Lavorazione</th>
                                    <th class="w-24 py-2 pr-2 font-medium">Codice art.</th>
                                    <th class="w-20 py-2 pr-2 font-medium">Unità</th>
                                    <th class="w-28 py-2 pr-2 font-medium">Prezzo unit.</th>
                                    <th class="py-2 pr-2 font-medium">Descrizione</th>
                                    <th v-if="canManage" class="w-8 py-2" />
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                <tr v-for="(item, index) in items" :key="index" data-test="pl-item">
                                    <td class="py-1.5 pr-2">
                                        <select
                                            v-model="item.work_type_id"
                                            class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm disabled:bg-gray-50"
                                            :disabled="! canManage"
                                            @change="onWorkTypeChange(item)"
                                        >
                                            <option value="" disabled>Seleziona…</option>
                                            <option v-for="w in workTypes" :key="w.id" :value="w.id">{{ w.name }}</option>
                                        </select>
                                    </td>
                                    <td class="py-1.5 pr-2">
                                        <input v-model="item.item_code" maxlength="50" class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm disabled:bg-gray-50" :disabled="! canManage" placeholder="—" title="Distingue più fasce di prezzo per la stessa lavorazione" @input="markDirty">
                                    </td>
                                    <td class="py-1.5 pr-2">
                                        <input v-model="item.unit" maxlength="20" class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm disabled:bg-gray-50" :disabled="! canManage" placeholder="mq" @input="markDirty">
                                    </td>
                                    <td class="py-1.5 pr-2">
                                        <input v-model.number="item.unit_price" type="number" step="0.01" min="0" class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-right text-sm disabled:bg-gray-50" :disabled="! canManage" data-test="pl-price" @input="markDirty">
                                    </td>
                                    <td class="py-1.5 pr-2">
                                        <input v-model="item.description" class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm disabled:bg-gray-50" :disabled="! canManage" @input="markDirty">
                                    </td>
                                    <td v-if="canManage" class="py-1.5 text-right">
                                        <button class="text-xs font-medium text-red-600 hover:underline" @click="removeItem(index)">✕</button>
                                    </td>
                                </tr>
                                <tr v-if="! items.length">
                                    <td :colspan="canManage ? 6 : 5" class="py-4 text-center text-sm text-gray-400">Nessuna voce: aggiungi i prezzi delle lavorazioni.</td>
                                </tr>
                            </tbody>
                        </table>

                        <div v-if="canManage" class="mt-3 flex items-center justify-between">
                            <button class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm" data-test="pl-add-item" @click="addItem">Aggiungi voce</button>
                            <button
                                class="rounded-lg bg-green-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                                :disabled="detailBusy || items.some((i) => ! i.work_type_id || ! i.unit.trim() || i.unit_price == null || i.unit_price === '')"
                                data-test="pl-save-items"
                                @click="saveItems"
                            >{{ detailBusy ? 'Salvataggio…' : 'Salva le voci' }}</button>
                        </div>

                        <div v-if="canManage" class="mt-8 flex items-center gap-6 border-t border-gray-100 pt-4">
                            <button
                                class="text-sm font-medium text-gray-700 hover:underline disabled:opacity-50"
                                :disabled="detailBusy"
                                data-test="pl-toggle-active"
                                @click="toggleActive"
                            >{{ detail.is_active ? 'Disattiva listino' : 'Riattiva listino' }}</button>
                            <button class="text-sm font-medium text-red-600 hover:underline" data-test="pl-delete" @click="deleteList">
                                Elimina listino
                            </button>
                        </div>
                    </div>
                </div>
            </Teleport>
        </div>
    </AppLayout>
</template>
