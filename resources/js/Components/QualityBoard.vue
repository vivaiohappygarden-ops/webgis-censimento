<script setup>
import { onMounted, reactive, ref } from 'vue';
import axios from 'axios';

const props = defineProps({
    personnel: { type: Array, default: () => [] },
    canManage: { type: Boolean, default: false },
});
const emit = defineEmits(['open-order']);

const NC_STATUS = {
    open: { label: 'Aperta', cls: 'bg-red-100 text-red-800' },
    action: { label: 'In azione', cls: 'bg-amber-100 text-amber-800' },
    verified: { label: 'Verificata', cls: 'bg-blue-100 text-blue-800' },
    closed: { label: 'Chiusa', cls: 'bg-gray-200 text-gray-600' },
};
const NC_SEVERITY = { minor: 'Lieve', major: 'Grave', critical: 'Critica' };
const NC_TRANSITIONS = {
    open: ['action', 'closed'],
    action: ['verified', 'open'],
    verified: ['closed', 'action'],
    closed: [],
};

const filters = reactive({ status: '', severity: '' });
const rows = ref([]);
const loading = ref(false);
const loadError = ref(false);
const busyId = ref('');
const errorById = reactive({});

// La riga espansa mostra l'editor di causa, azione, scadenza, responsabile
const expanded = ref('');
const editor = reactive({ root_cause: '', corrective_action: '', due_on: '', responsible_id: '' });

let seq = 0;
async function reload() {
    const mySeq = ++seq;
    loading.value = true;
    try {
        const { data } = await axios.get('/api/v1/non-conformities', { params: {
            per_page: 100,
            ...(filters.status ? { status: filters.status } : {}),
            ...(filters.severity ? { severity: filters.severity } : {}),
        } });
        if (mySeq !== seq) return;
        rows.value = data.data;
        loadError.value = false;
    } catch {
        if (mySeq === seq) loadError.value = true;
    } finally {
        if (mySeq === seq) loading.value = false;
    }
}
defineExpose({ reload });

function toggleExpand(nc) {
    if (expanded.value === nc.id) {
        expanded.value = '';
        return;
    }
    expanded.value = nc.id;
    Object.assign(editor, {
        root_cause: nc.root_cause ?? '',
        corrective_action: nc.corrective_action ?? '',
        due_on: (nc.due_on ?? '').slice(0, 10),
        responsible_id: nc.responsible_id ?? '',
    });
}

async function patch(nc, payload) {
    busyId.value = nc.id;
    delete errorById[nc.id];
    try {
        await axios.patch(`/api/v1/non-conformities/${nc.id}`, payload);
        await reload();
    } catch (err) {
        errorById[nc.id] = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
            ?? err.response?.data?.message ?? 'Aggiornamento non riuscito';
    } finally {
        busyId.value = '';
    }
}

function saveEditor(nc) {
    patch(nc, {
        root_cause: editor.root_cause.trim() || null,
        corrective_action: editor.corrective_action.trim() || null,
        due_on: editor.due_on || null,
        responsible_id: editor.responsible_id || null,
    });
}

const fmtDate = (iso) => (iso ? new Date(iso).toLocaleDateString('it-IT') : '—');

onMounted(reload);
</script>

<template>
    <div>
        <div class="mb-3 flex flex-wrap gap-2">
            <select v-model="filters.status" data-test="nc-filter-status" class="rounded-lg border border-gray-300 px-2.5 py-2 text-sm" @change="reload">
                <option value="">Tutti gli stati</option>
                <option v-for="(s, value) in NC_STATUS" :key="value" :value="value">{{ s.label }}</option>
            </select>
            <select v-model="filters.severity" class="rounded-lg border border-gray-300 px-2.5 py-2 text-sm" @change="reload">
                <option value="">Tutte le gravità</option>
                <option v-for="(label, value) in NC_SEVERITY" :key="value" :value="value">{{ label }}</option>
            </select>
        </div>

        <p v-if="loadError" class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
            Caricamento non riuscito.
            <button class="ml-1 font-medium underline" @click="reload">Riprova</button>
        </p>

        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
            <table class="w-full text-sm" data-test="nc-list">
                <thead>
                    <tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wide text-gray-400">
                        <th class="px-4 py-2.5 font-medium">Codice</th>
                        <th class="px-4 py-2.5 font-medium">Gravità</th>
                        <th class="px-4 py-2.5 font-medium">Stato</th>
                        <th class="px-4 py-2.5 font-medium">Descrizione</th>
                        <th class="px-4 py-2.5 font-medium">Ordine</th>
                        <th class="px-4 py-2.5 font-medium">Responsabile</th>
                        <th class="px-4 py-2.5 font-medium">Scadenza</th>
                        <th v-if="canManage" class="px-4 py-2.5 font-medium">Azioni</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50 align-top">
                    <template v-for="nc in rows" :key="nc.id">
                        <tr class="hover:bg-gray-50/60" data-test="nc-row">
                            <td class="cursor-pointer px-4 py-2 font-medium" @click="toggleExpand(nc)">{{ nc.code }}</td>
                            <td class="px-4 py-2">{{ NC_SEVERITY[nc.severity] }}</td>
                            <td class="px-4 py-2">
                                <span class="rounded-full px-2.5 py-0.5 text-xs font-medium" :class="NC_STATUS[nc.status]?.cls">
                                    {{ NC_STATUS[nc.status]?.label ?? nc.status }}
                                </span>
                            </td>
                            <td class="max-w-64 truncate px-4 py-2" :title="nc.description">{{ nc.description }}</td>
                            <td class="px-4 py-2">
                                <button v-if="nc.work_order" class="text-green-800 hover:underline" @click="emit('open-order', nc.work_order.id)">
                                    {{ nc.work_order.code }}
                                </button>
                                <span v-else class="text-gray-400">—</span>
                            </td>
                            <td class="px-4 py-2 text-gray-600">{{ nc.responsible?.name ?? '—' }}</td>
                            <td class="px-4 py-2 text-gray-500">{{ fmtDate(nc.due_on) }}</td>
                            <td v-if="canManage" class="px-4 py-2">
                                <div class="flex flex-wrap gap-1.5">
                                    <button
                                        v-for="to in NC_TRANSITIONS[nc.status] ?? []"
                                        :key="to"
                                        class="rounded border border-green-700 px-2 py-0.5 text-xs font-medium text-green-700 hover:bg-green-50 disabled:opacity-50"
                                        :disabled="busyId === nc.id"
                                        :data-test="`nc-to-${to}`"
                                        @click="patch(nc, { status: to })"
                                    >{{ NC_STATUS[to].label }}</button>
                                </div>
                                <p v-if="errorById[nc.id]" class="mt-1 text-xs text-red-600">{{ errorById[nc.id] }}</p>
                            </td>
                        </tr>
                        <tr v-if="expanded === nc.id">
                            <td :colspan="canManage ? 8 : 7" class="bg-gray-50/60 px-4 py-3">
                                <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                                    <label class="block text-xs">
                                        <span class="text-gray-500">Causa</span>
                                        <textarea v-model="editor.root_cause" rows="2" :disabled="! canManage || nc.status === 'closed'" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm disabled:bg-gray-100" />
                                    </label>
                                    <label class="block text-xs">
                                        <span class="text-gray-500">Azione correttiva</span>
                                        <textarea v-model="editor.corrective_action" rows="2" :disabled="! canManage || nc.status === 'closed'" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm disabled:bg-gray-100" />
                                    </label>
                                    <label class="block text-xs">
                                        <span class="text-gray-500">Scadenza</span>
                                        <input v-model="editor.due_on" type="date" :disabled="! canManage || nc.status === 'closed'" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm disabled:bg-gray-100">
                                    </label>
                                    <label class="block text-xs">
                                        <span class="text-gray-500">Responsabile</span>
                                        <select v-model="editor.responsible_id" :disabled="! canManage || nc.status === 'closed'" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-2 text-sm disabled:bg-gray-100">
                                            <option value="">—</option>
                                            <option v-for="p in personnel" :key="p.id" :value="p.id">{{ p.name }}</option>
                                        </select>
                                    </label>
                                </div>
                                <button
                                    v-if="canManage && nc.status !== 'closed'"
                                    class="mt-2 rounded-lg bg-green-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                                    :disabled="busyId === nc.id"
                                    data-test="nc-save"
                                    @click="saveEditor(nc)"
                                >Salva</button>
                            </td>
                        </tr>
                    </template>
                    <tr v-if="! rows.length && ! loading">
                        <td :colspan="canManage ? 8 : 7" class="px-4 py-8 text-center text-gray-400">
                            Nessuna non conformità: i controlli qualità con esito negativo compariranno qui.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
