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

const dateOnly = (d) => (d ? String(d).slice(0, 10) : '');

const tree = reactive({
    ...(props.asset.tree ?? {}),
    planted_on: dateOnly(props.asset.tree?.planted_on),
    removed_on: dateOnly(props.asset.tree?.removed_on),
    dedicated_to: {
        name: '', occasion: '', date: '',
        ...(props.asset.tree?.dedicated_to ?? {}),
    },
});
const vta = reactive({
    assessment_type: 'vta_visual',
    assessed_on: new Date().toISOString().slice(0, 10),
    failure_class: '',
    outcome: '',
    prescriptions: '',
    next_check_due: '',
});

// Analisi strumentali per valutazione: stato di espansione e form per riga
const analyses = reactive({});

const INSTRUMENTS = {
    resistograph: 'Resistografo',
    sonic_tomograph: 'Tomografo sonico',
    electric_tomograph: 'Tomografo elettrico',
    pull_test: 'Prova di trazione',
    dendro_densimeter: 'Dendrodensimetro',
    other: 'Altro strumento',
};

function analysisState(assessmentId) {
    if (! analyses[assessmentId]) {
        analyses[assessmentId] = {
            open: false, loading: false, items: [], showForm: false, saving: false, error: '',
            form: { instrument_type: 'resistograph', instrument_model: '', measured_at: '', measurement_height_cm: null, notes: '', report: null },
        };
    }
    return analyses[assessmentId];
}

async function toggleAnalyses(assessmentId) {
    const st = analysisState(assessmentId);
    st.open = ! st.open;
    if (st.open) await loadAnalyses(assessmentId);
}

async function loadAnalyses(assessmentId) {
    const st = analysisState(assessmentId);
    st.loading = true;
    try {
        const { data } = await axios.get(`/api/v1/assessments/${assessmentId}/instrumental-analyses`);
        st.items = data.data;
    } finally {
        st.loading = false;
    }
}

async function saveAnalysis(assessmentId) {
    const st = analysisState(assessmentId);
    st.saving = true;
    st.error = '';
    try {
        const form = new FormData();
        form.append('instrument_type', st.form.instrument_type);
        if (st.form.instrument_model) form.append('instrument_model', st.form.instrument_model);
        if (st.form.measured_at) form.append('measured_at', st.form.measured_at);
        if (st.form.measurement_height_cm != null && st.form.measurement_height_cm !== '') {
            form.append('measurement_height_cm', st.form.measurement_height_cm);
        }
        if (st.form.notes) form.append('notes', st.form.notes);
        if (st.form.report) form.append('report', st.form.report);

        await axios.post(`/api/v1/assessments/${assessmentId}/instrumental-analyses`, form);
        st.showForm = false;
        st.form = { instrument_type: 'resistograph', instrument_model: '', measured_at: '', measurement_height_cm: null, notes: '', report: null };
        await Promise.all([loadAnalyses(assessmentId), loadAssessments()]);
    } catch (err) {
        st.error = Object.values(err.response?.data?.errors ?? {})[0]?.[0] ?? 'Errore nel salvataggio';
    } finally {
        st.saving = false;
    }
}

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
                monumental_ref: tree.is_monumental ? (tree.monumental_ref || null) : null,
                is_protected: !! tree.is_protected,
                protection_ref: tree.is_protected ? (tree.protection_ref || null) : null,
                is_dedicated: !! tree.is_dedicated,
                dedicated_to: tree.is_dedicated
                    ? {
                        name: tree.dedicated_to.name || null,
                        occasion: tree.dedicated_to.occasion || null,
                        date: tree.dedicated_to.date || null,
                    }
                    : {},
                has_stake: !! tree.has_stake,
                has_bracing: !! tree.has_bracing,
                planted_on: tree.planted_on || null,
                removed_on: tree.removed_on || null,
                removal_reason: tree.removed_on ? (tree.removal_reason || null) : null,
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
        <h2 class="text-sm font-semibold">Scheda albero</h2>

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
            <label class="flex items-center gap-1.5"><input v-model="tree.is_dedicated" type="checkbox" :disabled="! canUpdate" class="rounded border-gray-300"> Dedicato</label>
            <label class="flex items-center gap-1.5"><input v-model="tree.has_stake" type="checkbox" :disabled="! canUpdate" class="rounded border-gray-300"> Palo tutore</label>
            <label class="flex items-center gap-1.5"><input v-model="tree.has_bracing" type="checkbox" :disabled="! canUpdate" class="rounded border-gray-300"> Consolidamenti</label>
        </div>

        <div v-if="tree.is_monumental || tree.is_protected" class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
            <label v-if="tree.is_monumental" class="block text-xs">
                <span class="text-gray-500">Riferimento monumentale (L. 10/2013, elenco AMI)</span>
                <input v-model="tree.monumental_ref" :disabled="! canUpdate" placeholder="es. AMI/03/000123" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
            </label>
            <label v-if="tree.is_protected" class="block text-xs">
                <span class="text-gray-500">Riferimento vincolo di tutela</span>
                <input v-model="tree.protection_ref" :disabled="! canUpdate" placeholder="es. vincolo paesaggistico D.Lgs. 42/2004" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
            </label>
        </div>

        <div v-if="tree.is_dedicated" class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-3">
            <label class="block text-xs">
                <span class="text-gray-500">Intitolato a</span>
                <input v-model="tree.dedicated_to.name" :disabled="! canUpdate" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
            </label>
            <label class="block text-xs">
                <span class="text-gray-500">Occasione (es. nuovo nato, memoria)</span>
                <input v-model="tree.dedicated_to.occasion" :disabled="! canUpdate" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
            </label>
            <label class="block text-xs">
                <span class="text-gray-500">Data della dedica</span>
                <input v-model="tree.dedicated_to.date" type="date" :disabled="! canUpdate" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
            </label>
        </div>

        <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-3">
            <label class="block text-xs">
                <span class="text-gray-500">Data di impianto</span>
                <input v-model="tree.planted_on" type="date" :disabled="! canUpdate" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
            </label>
            <label class="block text-xs">
                <span class="text-gray-500">Data di abbattimento/rimozione</span>
                <input v-model="tree.removed_on" type="date" :disabled="! canUpdate" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
            </label>
            <label v-if="tree.removed_on" class="block text-xs">
                <span class="text-gray-500">Motivo della rimozione</span>
                <input v-model="tree.removal_reason" :disabled="! canUpdate" placeholder="es. classe D, schianto, cantiere" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
            </label>
        </div>
        <p v-if="tree.removed_on" class="mt-2 text-xs text-amber-700">
            Con la data di rimozione valorizzata l'albero esce dalla consistenza del bilancio arboreo da quella data.
        </p>

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
                >+ Nuova valutazione</button>
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
                    class="rounded-lg border border-gray-200 px-3 py-2 text-sm"
                >
                    <div class="flex flex-wrap items-center gap-3">
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
                        <button
                            class="text-xs font-medium text-green-700 hover:underline"
                            :class="a.next_check_due ? '' : 'ml-auto'"
                            @click="toggleAnalyses(a.id)"
                        >Analisi strumentali ({{ a.instrumental_analyses_count ?? 0 }})</button>
                    </div>

                    <div v-if="analyses[a.id]?.open" class="mt-2 border-t border-gray-100 pt-2">
                        <p v-if="analyses[a.id].loading" class="text-xs text-gray-400">Caricamento…</p>
                        <table v-else-if="analyses[a.id].items.length" class="w-full text-xs">
                            <thead>
                                <tr class="text-left text-gray-400">
                                    <th class="py-1 pr-3 font-medium">Strumento</th>
                                    <th class="py-1 pr-3 font-medium">Modello</th>
                                    <th class="py-1 pr-3 font-medium">Data misura</th>
                                    <th class="py-1 pr-3 font-medium">Quota (cm)</th>
                                    <th class="py-1 pr-3 font-medium">Referto</th>
                                    <th class="py-1 font-medium">Note</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                <tr v-for="an in analyses[a.id].items" :key="an.id">
                                    <td class="py-1.5 pr-3 font-medium">{{ INSTRUMENTS[an.instrument_type] }}</td>
                                    <td class="py-1.5 pr-3">{{ an.instrument_model || '—' }}</td>
                                    <td class="py-1.5 pr-3">{{ fmt(an.measured_at) }}</td>
                                    <td class="py-1.5 pr-3">{{ an.measurement_height_cm ?? '—' }}</td>
                                    <td class="py-1.5 pr-3">
                                        <a v-if="an.document" :href="an.document.url" target="_blank" class="text-green-700 hover:underline">{{ an.document.title }}</a>
                                        <span v-else>—</span>
                                    </td>
                                    <td class="py-1.5 text-gray-500">{{ an.notes || '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <p v-else class="text-xs text-gray-400">Nessuna analisi strumentale registrata.</p>

                        <button
                            v-if="canUpdate && ! analyses[a.id].showForm"
                            class="mt-2 rounded-lg border border-green-700 px-2.5 py-1 text-xs font-medium text-green-700 hover:bg-green-50"
                            @click="analyses[a.id].showForm = true"
                        >+ Aggiungi analisi</button>

                        <form v-if="analyses[a.id].showForm" class="mt-2 space-y-2 rounded-lg bg-gray-50 p-2.5" @submit.prevent="saveAnalysis(a.id)">
                            <div class="grid grid-cols-2 gap-2 md:grid-cols-4">
                                <label class="block text-xs">
                                    <span class="text-gray-500">Strumento *</span>
                                    <select v-model="analyses[a.id].form.instrument_type" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm">
                                        <option v-for="(label, value) in INSTRUMENTS" :key="value" :value="value">{{ label }}</option>
                                    </select>
                                </label>
                                <label class="block text-xs">
                                    <span class="text-gray-500">Modello</span>
                                    <input v-model="analyses[a.id].form.instrument_model" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
                                </label>
                                <label class="block text-xs">
                                    <span class="text-gray-500">Data misura</span>
                                    <input v-model="analyses[a.id].form.measured_at" type="date" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
                                </label>
                                <label class="block text-xs">
                                    <span class="text-gray-500">Quota misura (cm)</span>
                                    <input v-model.number="analyses[a.id].form.measurement_height_cm" type="number" min="0" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
                                </label>
                            </div>
                            <label class="block text-xs">
                                <span class="text-gray-500">Note</span>
                                <textarea v-model="analyses[a.id].form.notes" rows="2" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm" />
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Referto (PDF, max 20 MB)</span>
                                <input type="file" accept="application/pdf" class="mt-1 block w-full text-xs" @change="analyses[a.id].form.report = $event.target.files?.[0] ?? null">
                            </label>
                            <p v-if="analyses[a.id].error" class="text-xs text-red-600">{{ analyses[a.id].error }}</p>
                            <div class="flex gap-2">
                                <button type="submit" :disabled="analyses[a.id].saving" class="rounded-lg bg-green-700 px-3 py-1.5 text-xs font-medium text-white hover:bg-green-800 disabled:opacity-50">
                                    {{ analyses[a.id].saving ? 'Salvataggio…' : 'Registra analisi' }}
                                </button>
                                <button type="button" class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs" @click="analyses[a.id].showForm = false">Annulla</button>
                            </div>
                        </form>
                    </div>
                </li>
                <li v-if="! assessments.length" class="py-2 text-sm text-gray-400">Nessuna valutazione registrata.</li>
            </ul>
        </div>
    </div>
</template>
