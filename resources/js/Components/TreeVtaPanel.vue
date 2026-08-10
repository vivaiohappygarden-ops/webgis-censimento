<script setup>
import { onMounted, reactive, ref } from 'vue';
import axios from 'axios';

const props = defineProps({
    asset: { type: Object, required: true },
    canUpdate: { type: Boolean, default: false },
});
const emit = defineEmits(['saved']);

const assessments = ref([]);
const savingTree = ref(false);
const savingVta = ref(false);
const treeError = ref('');
const vtaError = ref('');
const showVtaForm = ref(false);

const tree = reactive({ ...(props.asset.tree ?? {}) });
const vta = reactive({
    assessment_type: 'vta_visual',
    assessed_on: new Date().toISOString().slice(0, 10),
    failure_class: '',
    outcome: '',
    prescriptions: '',
    next_check_due: '',
});

const CLASS_COLORS = {
    'A': 'bg-green-100 text-green-800',
    'B': 'bg-lime-100 text-lime-800',
    'C': 'bg-yellow-100 text-yellow-800',
    'C/D': 'bg-orange-100 text-orange-800',
    'D': 'bg-red-100 text-red-800',
};
const TYPES = {
    vta_visual: 'VTA visiva', vta_instrumental: 'VTA strumentale', vsa: 'VSA',
    pull_test: 'Prova di trazione', aerial_inspection: 'Ispezione in quota', other: 'Altro',
};
const OUTCOMES = { ok: 'Nessun intervento', monitor: 'Monitorare', prescriptions: 'Prescrizioni', fell: 'Abbattimento' };

async function loadAssessments() {
    const { data } = await axios.get(`/api/v1/assets/${props.asset.id}/assessments`);
    assessments.value = data.data;
}

async function saveTree() {
    savingTree.value = true;
    treeError.value = '';
    try {
        await axios.patch(`/api/v1/assets/${props.asset.id}`, {
            tree: {
                genus: tree.genus || null,
                species: tree.species || null,
                common_name: tree.common_name || null,
                height_m: tree.height_m || null,
                dbh_cm: tree.dbh_cm || null,
                trunk_circumference_cm: tree.trunk_circumference_cm || null,
                crown_diameter_m: tree.crown_diameter_m || null,
                trunk_count: tree.trunk_count || 1,
                vegetative_state: tree.vegetative_state || null,
                is_monumental: !! tree.is_monumental,
                is_protected: !! tree.is_protected,
                has_stake: !! tree.has_stake,
                has_bracing: !! tree.has_bracing,
                planted_on: tree.planted_on ? String(tree.planted_on).slice(0, 10) : null,
            },
        });
        emit('saved');
    } catch (err) {
        treeError.value = Object.values(err.response?.data?.errors ?? {})[0]?.[0] ?? 'Errore nel salvataggio';
    } finally {
        savingTree.value = false;
    }
}

async function saveVta() {
    savingVta.value = true;
    vtaError.value = '';
    try {
        await axios.post(`/api/v1/assets/${props.asset.id}/assessments`, {
            assessment_type: vta.assessment_type,
            assessed_on: vta.assessed_on,
            failure_class: vta.failure_class || null,
            outcome: vta.outcome || null,
            prescriptions: vta.prescriptions || null,
            next_check_due: vta.next_check_due || null,
        });
        showVtaForm.value = false;
        Object.assign(vta, { failure_class: '', outcome: '', prescriptions: '', next_check_due: '' });
        await loadAssessments();
    } catch (err) {
        vtaError.value = Object.values(err.response?.data?.errors ?? {})[0]?.[0] ?? 'Errore nel salvataggio';
    } finally {
        savingVta.value = false;
    }
}

const fmt = (d) => (d ? new Date(d).toLocaleDateString('it-IT') : '—');

onMounted(loadAssessments);
</script>

<template>
    <div class="mt-6 rounded-xl border border-gray-200 bg-white p-6">
        <h2 class="text-sm font-semibold">🌳 Scheda albero</h2>

        <div class="mt-3 grid grid-cols-2 gap-3 md:grid-cols-4">
            <label class="block text-xs">
                <span class="text-gray-500">Genere</span>
                <input v-model="tree.genus" :disabled="! canUpdate" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
            </label>
            <label class="block text-xs">
                <span class="text-gray-500">Specie</span>
                <input v-model="tree.species" :disabled="! canUpdate" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
            </label>
            <label class="block text-xs">
                <span class="text-gray-500">Nome comune</span>
                <input v-model="tree.common_name" :disabled="! canUpdate" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
            </label>
            <label class="block text-xs">
                <span class="text-gray-500">Stato vegetativo</span>
                <select v-model="tree.vegetative_state" :disabled="! canUpdate" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm">
                    <option :value="null">—</option>
                    <option>buono</option><option>medio</option><option>scarso</option><option>deperiente</option><option>secco</option>
                </select>
            </label>
            <label class="block text-xs">
                <span class="text-gray-500">Altezza (m)</span>
                <input v-model.number="tree.height_m" type="number" step="0.1" :disabled="! canUpdate" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
            </label>
            <label class="block text-xs">
                <span class="text-gray-500">Ø fusto DBH (cm)</span>
                <input v-model.number="tree.dbh_cm" type="number" step="0.5" :disabled="! canUpdate" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
            </label>
            <label class="block text-xs">
                <span class="text-gray-500">Circonf. fusto (cm)</span>
                <input v-model.number="tree.trunk_circumference_cm" type="number" step="1" :disabled="! canUpdate" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
            </label>
            <label class="block text-xs">
                <span class="text-gray-500">Ø chioma (m)</span>
                <input v-model.number="tree.crown_diameter_m" type="number" step="0.5" :disabled="! canUpdate" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
            </label>
        </div>

        <div class="mt-3 flex flex-wrap gap-4 text-sm">
            <label class="flex items-center gap-1.5"><input v-model="tree.is_monumental" type="checkbox" :disabled="! canUpdate" class="rounded border-gray-300"> Monumentale</label>
            <label class="flex items-center gap-1.5"><input v-model="tree.is_protected" type="checkbox" :disabled="! canUpdate" class="rounded border-gray-300"> Soggetto a tutela</label>
            <label class="flex items-center gap-1.5"><input v-model="tree.has_stake" type="checkbox" :disabled="! canUpdate" class="rounded border-gray-300"> Palo tutore</label>
            <label class="flex items-center gap-1.5"><input v-model="tree.has_bracing" type="checkbox" :disabled="! canUpdate" class="rounded border-gray-300"> Consolidamenti</label>
        </div>

        <p v-if="treeError" class="mt-2 text-sm text-red-600">{{ treeError }}</p>
        <button
            v-if="canUpdate"
            class="mt-3 rounded-lg bg-green-700 px-4 py-2 text-sm font-medium text-white hover:bg-green-800 disabled:opacity-50"
            :disabled="savingTree"
            @click="saveTree"
        >{{ savingTree ? 'Salvataggio…' : 'Salva dati albero' }}</button>

        <!-- VTA -->
        <div class="mt-6 border-t border-gray-100 pt-4">
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-semibold">Valutazioni di stabilità (VTA)</h3>
                <button
                    v-if="canUpdate"
                    class="rounded-lg bg-green-700 px-3 py-1.5 text-xs font-medium text-white hover:bg-green-800"
                    @click="showVtaForm = ! showVtaForm"
                >＋ Nuova valutazione</button>
            </div>

            <form v-if="showVtaForm" class="mt-3 space-y-2 rounded-xl bg-green-50/50 p-3" @submit.prevent="saveVta">
                <div class="grid grid-cols-2 gap-2 md:grid-cols-4">
                    <label class="block text-xs">
                        <span class="text-gray-500">Tipo *</span>
                        <select v-model="vta.assessment_type" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm">
                            <option v-for="(label, value) in TYPES" :key="value" :value="value">{{ label }}</option>
                        </select>
                    </label>
                    <label class="block text-xs">
                        <span class="text-gray-500">Data *</span>
                        <input v-model="vta.assessed_on" type="date" required class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
                    </label>
                    <label class="block text-xs">
                        <span class="text-gray-500">Classe cedimento (CPC)</span>
                        <select v-model="vta.failure_class" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm">
                            <option value="">—</option>
                            <option>A</option><option>B</option><option>C</option><option>C/D</option><option>D</option>
                        </select>
                    </label>
                    <label class="block text-xs">
                        <span class="text-gray-500">Esito</span>
                        <select v-model="vta.outcome" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm">
                            <option value="">—</option>
                            <option v-for="(label, value) in OUTCOMES" :key="value" :value="value">{{ label }}</option>
                        </select>
                    </label>
                </div>
                <label class="block text-xs">
                    <span class="text-gray-500">Prescrizioni</span>
                    <textarea v-model="vta.prescriptions" rows="2" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm" />
                </label>
                <label class="block text-xs md:w-56">
                    <span class="text-gray-500">Prossimo controllo (vuoto = automatico dalla classe)</span>
                    <input v-model="vta.next_check_due" type="date" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
                </label>
                <p v-if="vtaError" class="text-sm text-red-600">{{ vtaError }}</p>
                <button type="submit" :disabled="savingVta" class="rounded-lg bg-green-700 px-4 py-2 text-sm font-medium text-white hover:bg-green-800 disabled:opacity-50">
                    {{ savingVta ? 'Salvataggio…' : 'Registra valutazione' }}
                </button>
            </form>

            <ul class="mt-3 space-y-2">
                <li
                    v-for="a in assessments"
                    :key="a.id"
                    class="flex flex-wrap items-center gap-3 rounded-lg border border-gray-200 px-3 py-2 text-sm"
                >
                    <span v-if="a.failure_class" class="rounded-full px-2.5 py-0.5 text-xs font-bold" :class="CLASS_COLORS[a.failure_class]">
                        {{ a.failure_class }}
                    </span>
                    <span class="font-medium">{{ TYPES[a.assessment_type] }}</span>
                    <span class="text-gray-500">{{ fmt(a.assessed_on) }}</span>
                    <span v-if="a.outcome" class="text-gray-500">· {{ OUTCOMES[a.outcome] }}</span>
                    <span v-if="a.assessor" class="text-gray-400">· {{ a.assessor.name }}</span>
                    <span v-if="a.next_check_due" class="ml-auto text-xs" :class="new Date(a.next_check_due) < new Date() ? 'font-semibold text-red-600' : 'text-gray-500'">
                        ricontrollo {{ fmt(a.next_check_due) }}
                    </span>
                </li>
                <li v-if="! assessments.length" class="py-2 text-sm text-gray-400">Nessuna valutazione registrata.</li>
            </ul>
        </div>
    </div>
</template>
