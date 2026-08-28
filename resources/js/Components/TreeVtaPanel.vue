<script setup>
import { computed, nextTick, onMounted, reactive, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { fetchPdf } from '@/pdf';
import AvvisoErrore from '@/Components/AvvisoErrore.vue';
import { usaCaricamento } from '@/caricamento';

const props = defineProps({
    asset: { type: Object, required: true },
    canUpdate: { type: Boolean, default: false },
    // Arrivando dallo scadenzario VTA il modulo si apre già pronto
    apriValutazione: { type: Boolean, default: false },
});
const emit = defineEmits(['saved']);

// Le voci delle tendine agronomiche arrivano dal server (config/agronomia.php):
// un'unica fonte per tendine, validazione e stampe, senza copie nel JS
const agronomia = usePage().props.agronomia ?? {};
// Un valore fuori dizionario (per esempio da un vecchio import) resta
// visibile come voce in piu', invece di sparire dalla tendina
const conValoreAttuale = (voci, attuale) => {
    const base = voci ?? [];

    return attuale && ! base.includes(attuale) ? [attuale, ...base] : base;
};

// Eliminare una bozza chiede un permesso piu' alto della correzione:
// l'operatore in campo registra e corregge, ma non butta via
const canDelete = computed(() => (usePage().props.auth?.user?.permissions ?? []).includes('assets.delete'));

// Le valutazioni sono lo storico dell'albero: se non arrivano deve dirlo,
// non far credere che l'albero non sia mai stato valutato
const { avviso, riprovaInCorso, carica, riprova } = usaCaricamento();

const assessments = ref([]);
const savingTree = ref(false);
const savingVta = ref(false);
const treeError = ref('');
const vtaError = ref('');
const showVtaForm = ref(false);

const dateOnly = (d) => (d ? String(d).slice(0, 10) : '');

// Data odierna locale in formato ISO: un ricontrollo che scade oggi non è scaduto
const pad = (n) => String(n).padStart(2, '0');
const todayIso = () => {
    const d = new Date();

    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
};

const tree = reactive({
    ...(props.asset.tree ?? {}),
    planted_on: dateOnly(props.asset.tree?.planted_on),
    removed_on: dateOnly(props.asset.tree?.removed_on),
    dedicated_to: {
        name: '', occasion: '', date: '',
        ...(props.asset.tree?.dedicated_to ?? {}),
    },
});
// Scheda estesa della perizia: etichette e struttura vuota
const CONTESTO = {
    ambito: 'Ambito',
    sito_radicazione: 'Sito di radicazione',
    disposizione: 'Disposizione',
    accessibilita: 'Accessibilità',
};
const GIUDIZIO = {
    fase_fisiologica: 'Fase fisiologica',
    stato_vegetativo: 'Stato vegetativo',
    sintetico: 'Giudizio sintetico',
};
const PARTI = {
    rilevamenti: 'Rilevamenti generali',
    radici: 'Radici',
    colletto: 'Colletto',
    fusto: 'Fusto',
    castello: 'Castello',
    branche: 'Branche',
    chioma: 'Chioma e foglie',
};

function blankSurvey() {
    return {
        contesto: { ambito: '', sito_radicazione: '', disposizione: '', accessibilita: '' },
        interferenze: '',
        giudizio: { fase_fisiologica: '', stato_vegetativo: '', sintetico: '', patologie_quarantena: '' },
        difetti: Object.fromEntries(Object.keys(PARTI).map((k) => [k, ''])),
        integrazione_vta: '',
        priorita_intervento: '',
        conclusioni: '',
    };
}

const showSurvey = ref(false);
// I bersagli si scrivono uno per riga: nel documento diventano l'elenco
// delle presenze che l'eventuale cedimento metterebbe a rischio
const blankVta = () => ({
    id: null,
    version: null,
    assessment_type: 'vta_visual',
    assessed_on: todayIso(),
    failure_class: '',
    outcome: '',
    prescriptions: '',
    next_check_due: '',
    is_public: false,
    assessor_external: '',
    bersagli: '',
    survey: blankSurvey(),
});
const vta = reactive(blankVta());

/** Riapre una valutazione già registrata per correggerla. */
function editAssessment(a) {
    Object.assign(vta, blankVta(), {
        id: a.id,
        version: a.version,
        assessment_type: a.assessment_type,
        assessed_on: dateOnly(a.assessed_on),
        failure_class: a.failure_class ?? '',
        outcome: a.outcome ?? '',
        prescriptions: a.prescriptions ?? '',
        next_check_due: dateOnly(a.next_check_due),
        is_public: !! a.is_public,
        assessor_external: a.assessor_external ?? '',
        bersagli: (Array.isArray(a.targets) ? a.targets : Object.values(a.targets ?? {})).join('\n'),
        survey: { ...blankSurvey(), ...(a.survey ?? {}),
            contesto: { ...blankSurvey().contesto, ...(a.survey?.contesto ?? {}) },
            giudizio: { ...blankSurvey().giudizio, ...(a.survey?.giudizio ?? {}) },
            difetti: { ...blankSurvey().difetti, ...(a.survey?.difetti ?? {}) } },
    });
    showVtaForm.value = true;
    showSurvey.value = true;
    vtaError.value = '';
}

// Perizia in PDF di una valutazione: comporre il documento richiede
// qualche secondo (foto e mappa), quindi il pulsante lo dice
const periziaError = ref('');
const periziaBusy = ref('');

async function openPerizia(assessmentId) {
    periziaError.value = '';
    periziaBusy.value = assessmentId;
    try {
        const res = await fetchPdf(`/api/v1/assessments/${assessmentId}/perizia-pdf`);
        if (res?.error) {
            periziaError.value = res.error;
        } else {
            // Alla prima stampa la perizia riceve il numero di protocollo:
            // si ricarica l'elenco perché compaia accanto alla valutazione
            await loadAssessments();
        }
    } finally {
        periziaBusy.value = '';
    }
}

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
            form: {
                instrument_type: 'resistograph', instrument_model: '', measured_at: '',
                measurement_height_cm: null, notes: '', report: null,
                // Misure: coppie voce/valore, come si leggono sul referto
                measures: [{ voce: '', valore: '' }],
            },
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
        st.error = '';
    } catch {
        st.error = 'Errore nel caricamento delle analisi.';
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
        for (const m of st.form.measures) {
            if (m.voce.trim() !== '' && m.valore.trim() !== '') {
                form.append(`measures[${m.voce.trim()}]`, m.valore.trim());
            }
        }
        if (st.form.report) form.append('report', st.form.report);

        await axios.post(`/api/v1/assessments/${assessmentId}/instrumental-analyses`, form);
        st.showForm = false;
        st.form = {
            instrument_type: 'resistograph', instrument_model: '', measured_at: '',
            measurement_height_cm: null, notes: '', report: null,
            measures: [{ voce: '', valore: '' }],
        };
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
            version: props.asset.version,
            tree: {
                genus: tree.genus || null,
                species: tree.species || null,
                cultivar: tree.cultivar || null,
                family: tree.family || null,
                common_name: tree.common_name || null,
                age_years_est: tree.age_years_est || null,
                age_qualifier: tree.age_qualifier || null,
                social_position: tree.social_position || null,
                growth_site: tree.growth_site || null,
                target: tree.target || null,
                height_m: tree.height_m || null,
                dbh_cm: tree.dbh_cm || null,
                trunk_circumference_cm: tree.trunk_circumference_cm || null,
                crown_diameter_m: tree.crown_diameter_m || null,
                crown_insertion_m: tree.crown_insertion_m || null,
                age_class: tree.age_class || null,
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
        treeError.value = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
            ?? err.response?.data?.message ?? 'Errore nel salvataggio';
    } finally {
        savingTree.value = false;
    }
}

async function saveVta() {
    savingVta.value = true;
    vtaError.value = '';
    try {
        const payload = {
            assessment_type: vta.assessment_type,
            assessed_on: vta.assessed_on,
            failure_class: vta.failure_class || null,
            outcome: vta.outcome || null,
            prescriptions: vta.prescriptions || null,
            next_check_due: vta.next_check_due || null,
            is_public: !! vta.is_public,
            assessor_external: vta.assessor_external || null,
            targets: vta.bersagli.split('\n').map((r) => r.trim()).filter(Boolean),
            survey: vta.survey,
        };
        if (vta.id) {
            await axios.patch(`/api/v1/assessments/${vta.id}`, { ...payload, version: vta.version });
        } else {
            await axios.post(`/api/v1/assets/${props.asset.id}/assessments`, payload);
        }
        showVtaForm.value = false;
        showSurvey.value = false;
        Object.assign(vta, blankVta());
        await loadAssessments();
    } catch (err) {
        vtaError.value = err.response?.status === 409
            ? 'La valutazione è stata modificata da qualcun altro: ricarica la pagina.'
            : (Object.values(err.response?.data?.errors ?? {})[0]?.[0]
                ?? err.response?.data?.message ?? 'Errore nel salvataggio');
    } finally {
        savingVta.value = false;
    }
}

const validando = ref(null);

/**
 * Validazione: la perizia diventa un atto e non si tocca piu'. Si chiede
 * conferma perche' non si torna indietro, e la conferma dice anche cosa fare
 * in caso di errore, che e' la domanda che viene subito dopo.
 */
async function validaPerizia(a) {
    const conferma = window.confirm(
        'Vuoi validare e chiudere questa perizia?\n\n'
        + 'Da questo momento il contenuto tecnico non sara\' piu\' modificabile ne\' '
        + 'cancellabile, e il documento portera\' numero di protocollo, data, il tuo nome '
        + 'e l\'impronta del contenuto.\n\n'
        + 'Non si torna indietro: se in seguito serve una correzione, si registra una '
        + 'nuova perizia sullo stesso albero, che supera questa.'
    );
    if (! conferma) return;

    periziaError.value = '';
    validando.value = a.id;
    try {
        await axios.post(`/api/v1/assessments/${a.id}/valida`);
        await loadAssessments();
    } catch (err) {
        periziaError.value = err.response?.data?.message ?? 'Errore nella validazione';
    } finally {
        validando.value = null;
    }
}

/**
 * Eliminazione di una valutazione IN BOZZA: per le validate il server
 * risponde 409 (un atto emesso non si toglie). La cancellazione e' morbida
 * e resta nel registro di controllo: la conferma dice cosa si sta buttando.
 */
const eliminando = ref(null);

async function eliminaValutazione(a) {
    const conferma = window.confirm(
        `Vuoi eliminare questa valutazione in bozza del ${fmt(a.assessed_on)}`
        + `${a.failure_class ? ` (classe ${a.failure_class})` : ''}?\n\n`
        + 'Verranno eliminate anche le sue analisi strumentali. '
        + 'L\'operazione resta registrata nel registro di controllo.'
    );
    if (! conferma) return;

    periziaError.value = '';
    eliminando.value = a.id;
    try {
        await axios.delete(`/api/v1/assessments/${a.id}`);
        await loadAssessments();
        emit('saved');
    } catch (err) {
        periziaError.value = err.response?.data?.message ?? 'Eliminazione non riuscita';
    } finally {
        eliminando.value = null;
    }
}

const fmt = (d) => (d ? new Date(d).toLocaleDateString('it-IT') : '—');
// Numeri all'italiana per la stima CO2 (punto per le migliaia, virgola decimale)
const num = (v, dec = 0) => Number(v).toLocaleString('it-IT', { minimumFractionDigits: dec, maximumFractionDigits: dec });
const fmtOra = (d) => (d ? new Date(d).toLocaleString('it-IT', { dateStyle: 'short', timeStyle: 'short' }) : '—');

onMounted(async () => {
    await carica(loadAssessments);
    if (props.apriValutazione && props.canUpdate) {
        showVtaForm.value = true;
        nextTick(() => document.querySelector('[data-test=vta-bersagli]')?.scrollIntoView({ block: 'center' }));
    }
});
</script>

<template>
    <div class="mt-6 rounded-xl border border-gray-200 bg-white p-6">
        <h2 class="text-sm font-semibold">Scheda albero</h2>

        <AvvisoErrore class="mt-3" :messaggio="avviso" :in-corso="riprovaInCorso" @riprova="riprova" />

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
                <span class="text-gray-500">Cultivar</span>
                <input v-model="tree.cultivar" :disabled="! canUpdate" data-test="cultivar" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
            </label>
            <label class="block text-xs">
                <span class="text-gray-500">Famiglia</span>
                <input v-model="tree.family" :disabled="! canUpdate" data-test="family" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
            </label>
            <label class="block text-xs">
                <span class="text-gray-500">Nome comune</span>
                <input v-model="tree.common_name" :disabled="! canUpdate" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
            </label>
            <label class="block text-xs">
                <span class="text-gray-500">Stato vegetativo</span>
                <select v-model="tree.vegetative_state" :disabled="! canUpdate" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm">
                    <option :value="null">—</option>
                    <option v-for="voce in conValoreAttuale(agronomia.stato_vegetativo, tree.vegetative_state)" :key="voce">{{ voce }}</option>
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
            <label class="block text-xs">
                <span class="text-gray-500">Altezza primo palco (m)</span>
                <input v-model.number="tree.crown_insertion_m" type="number" step="0.1" :disabled="! canUpdate" data-test="crown-insertion" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
            </label>
            <label class="block text-xs">
                <span class="text-gray-500">Età (anni)</span>
                <input v-model.number="tree.age_years_est" type="number" step="1" min="0" :disabled="! canUpdate" data-test="age-years" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
            </label>
            <label class="block text-xs">
                <span class="text-gray-500">Età: precisa o stimata</span>
                <select v-model="tree.age_qualifier" :disabled="! canUpdate" data-test="age-qualifier" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm">
                    <option :value="null">—</option>
                    <option v-for="voce in conValoreAttuale(agronomia.qualificatore_eta, tree.age_qualifier)" :key="voce">{{ voce }}</option>
                </select>
            </label>
            <label class="block text-xs">
                <span class="text-gray-500">Fase fisiologica</span>
                <select v-model="tree.age_class" :disabled="! canUpdate" data-test="age-class" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm">
                    <option :value="null">—</option>
                    <option v-for="voce in conValoreAttuale(agronomia.fase_fisiologica, tree.age_class)" :key="voce">{{ voce }}</option>
                </select>
            </label>
            <label class="block text-xs">
                <span class="text-gray-500">Numero di fusti</span>
                <input v-model.number="tree.trunk_count" type="number" step="1" min="1" :disabled="! canUpdate" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
            </label>
            <label class="block text-xs">
                <span class="text-gray-500">Posizione sociale</span>
                <select v-model="tree.social_position" :disabled="! canUpdate" data-test="social-position" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm">
                    <option :value="null">—</option>
                    <option v-for="voce in conValoreAttuale(agronomia.posizione_sociale, tree.social_position)" :key="voce">{{ voce }}</option>
                </select>
            </label>
            <label class="block text-xs">
                <span class="text-gray-500">Sito di crescita</span>
                <select v-model="tree.growth_site" :disabled="! canUpdate" data-test="growth-site" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm">
                    <option :value="null">—</option>
                    <option v-for="voce in conValoreAttuale(agronomia.sito_di_crescita, tree.growth_site)" :key="voce">{{ voce }}</option>
                </select>
            </label>
            <label class="block text-xs">
                <span class="text-gray-500">Bersaglio (frequentazione)</span>
                <select v-model="tree.target" :disabled="! canUpdate" data-test="target" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm">
                    <option :value="null">—</option>
                    <option v-for="voce in conValoreAttuale(agronomia.bersaglio, tree.target)" :key="voce">{{ voce }}</option>
                </select>
            </label>
        </div>
        <p class="mt-1 text-xs text-gray-500">
            Le misure lasciate vuote compaiono nella perizia come "non rilevate", mai come zero.
        </p>

        <!-- La stessa stima del portale pubblico: si controlla qui prima di accenderla -->
        <div v-if="asset.co2" class="mt-3 rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-600" data-test="stima-co2">
            Anidride carbonica immagazzinata (stima): <strong>{{ num(asset.co2.co2_kg) }} kg</strong><template v-if="asset.co2.annuo_kg !== null"> · assorbimento medio {{ num(asset.co2.annuo_kg, 1) }} kg/anno</template><template v-if="asset.co2.valore_euro !== null"> · controvalore <strong>{{ num(asset.co2.valore_euro) }} euro</strong></template>
            <span class="mt-1 block text-gray-400">
                Valore stimato, non misurato: {{ asset.co2.metodo }}<template v-if="asset.co2.valore_euro !== null">; prezzo di {{ num(asset.co2.prezzo_tonnellata) }} euro per tonnellata di CO2 ({{ asset.co2.prezzo_fonte }})</template>.
                È il dato che compare sul portale pubblico, se acceso per il committente.
            </span>
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
        </div>
        <!-- L'abbattimento non si scrive qui: si registra una volta sola in fondo
             alla scheda, così stato, data e bilancio arboreo restano allineati -->
        <p v-if="tree.removed_on" class="mt-2 text-xs text-amber-700">
            Albero abbattuto il {{ tree.removed_on.split('-').reverse().join('/') }}<template v-if="tree.removal_reason"> — {{ tree.removal_reason }}</template>.
            Da quella data esce dalla consistenza del bilancio arboreo.
        </p>
        <p v-else class="mt-2 text-xs text-gray-500">
            La data di abbattimento si registra in fondo alla scheda, con "Registra abbattimento".
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
                    data-test="nuova-valutazione"
                    @click="Object.assign(vta, blankVta()); showVtaForm = ! showVtaForm"
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
                <p v-if="vta.id" class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900" data-test="vta-in-correzione">
                    Stai correggendo una valutazione già registrata. Se la perizia era già stata
                    emessa, il documento corretto uscirà con un numero e una data nuovi.
                </p>
                <div class="grid gap-2 md:grid-cols-2">
                    <label class="block text-xs">
                        <span class="text-gray-500">Bersagli (uno per riga: cosa c'è sotto o vicino all'albero)</span>
                        <textarea
                            v-model="vta.bersagli"
                            rows="3"
                            data-test="vta-bersagli"
                            placeholder="area giochi&#10;marciapiede&#10;posti auto"
                            class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm"
                        />
                    </label>
                    <label class="block text-xs">
                        <span class="text-gray-500">Rilievo eseguito da (se non sei tu)</span>
                        <input v-model="vta.assessor_external" maxlength="254" placeholder="nome e cognome del rilevatore" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
                    </label>
                </div>
                <label class="block text-xs">
                    <span class="text-gray-500">Prescrizioni</span>
                    <textarea v-model="vta.prescriptions" rows="2" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm" />
                </label>
                <div class="grid gap-2 md:grid-cols-2">
                    <label class="block text-xs">
                        <span class="text-gray-500">Prossimo controllo (vuoto = automatico dalla classe)</span>
                        <input v-model="vta.next_check_due" type="date" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
                    </label>
                    <label class="block text-xs">
                        <span class="text-gray-500">Priorità dell'intervento</span>
                        <input v-model="vta.survey.priorita_intervento" maxlength="100" placeholder="es. entro 30 giorni" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
                    </label>
                </div>

                <label class="flex items-start gap-2 rounded-lg border border-gray-200 bg-white p-3 text-xs">
                    <input v-model="vta.is_public" type="checkbox" class="mt-0.5 rounded border-gray-300" data-test="vta-pubblica">
                    <span>
                        Pubblica come atto sul portale del committente
                        <span class="block text-gray-500">
                            Compare nella cronologia pubblica dell'albero come "Relazione tecnica",
                            con la data e le prescrizioni. Senza spunta resta solo nel gestionale.
                        </span>
                    </span>
                </label>

                <!-- Scheda estesa: serve solo a chi redige la perizia -->
                <button type="button" class="text-xs font-medium text-green-700 hover:underline" data-test="vta-scheda-estesa" @click="showSurvey = ! showSurvey">
                    {{ showSurvey ? 'Nascondi' : 'Compila' }} la scheda estesa per la perizia
                </button>

                <div v-if="showSurvey" class="space-y-2 rounded-lg border border-green-200 bg-white p-3">
                    <p class="text-xs font-medium text-gray-500">Inquadramento del contesto</p>
                    <div class="grid gap-2 md:grid-cols-4">
                        <label v-for="(label, key) in CONTESTO" :key="key" class="block text-xs">
                            <span class="text-gray-500">{{ label }}</span>
                            <input v-model="vta.survey.contesto[key]" maxlength="150" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
                        </label>
                    </div>
                    <label class="block text-xs">
                        <span class="text-gray-500">Interferenze (linee aeree, sottoservizi, manufatti)</span>
                        <input v-model="vta.survey.interferenze" maxlength="1000" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
                    </label>

                    <p class="pt-1 text-xs font-medium text-gray-500">Giudizio complessivo</p>
                    <div class="grid gap-2 md:grid-cols-3">
                        <label v-for="(label, key) in GIUDIZIO" :key="key" class="block text-xs">
                            <span class="text-gray-500">{{ label }}</span>
                            <input v-model="vta.survey.giudizio[key]" maxlength="150" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
                        </label>
                    </div>
                    <label class="block text-xs">
                        <span class="text-gray-500">Patologie da quarantena</span>
                        <input v-model="vta.survey.giudizio.patologie_quarantena" maxlength="500" placeholder="vuoto = non rilevate" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
                    </label>

                    <p class="pt-1 text-xs font-medium text-gray-500">Difetti riscontrati, parte per parte</p>
                    <label v-for="(label, key) in PARTI" :key="key" class="block text-xs">
                        <span class="text-gray-500">{{ label }}</span>
                        <textarea v-model="vta.survey.difetti[key]" rows="1" maxlength="2000" :data-test="`vta-difetto-${key}`" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm" />
                    </label>

                    <label class="block text-xs">
                        <span class="text-gray-500">Integrazione o completamento della valutazione</span>
                        <input v-model="vta.survey.integrazione_vta" maxlength="1000" placeholder="vuoto = valutazione completa" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
                    </label>
                    <label class="block text-xs">
                        <span class="text-gray-500">Conclusioni (vuoto = testo automatico dalla classe)</span>
                        <textarea v-model="vta.survey.conclusioni" rows="3" maxlength="4000" data-test="vta-conclusioni" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm" />
                    </label>
                </div>
                <p v-if="vtaError" class="text-sm text-red-600">{{ vtaError }}</p>
                <div class="flex gap-2">
                    <button type="submit" :disabled="savingVta" class="rounded-lg bg-green-700 px-4 py-2 text-sm font-medium text-white hover:bg-green-800 disabled:opacity-50">
                        {{ savingVta ? 'Salvataggio…' : (vta.id ? 'Salva la correzione' : 'Registra valutazione') }}
                    </button>
                    <button
                        type="button"
                        class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50"
                        @click="showVtaForm = false; Object.assign(vta, blankVta())"
                    >Annulla</button>
                </div>
            </form>

            <p v-if="periziaError" class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" data-test="vta-perizia-errore">{{ periziaError }}</p>

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
                        <span v-if="a.next_check_due" class="ml-auto text-xs" :class="String(a.next_check_due).slice(0, 10) < todayIso() ? 'font-semibold text-red-600' : 'text-gray-500'">
                            ricontrollo {{ fmt(a.next_check_due) }}
                        </span>
                        <button
                            class="text-xs font-medium text-green-700 hover:underline"
                            :class="a.next_check_due ? '' : 'ml-auto'"
                            @click="toggleAnalyses(a.id)"
                        >Analisi strumentali ({{ a.instrumental_analyses_count ?? 0 }})</button>
                        <button
                            v-if="canUpdate && ! a.validated_at"
                            class="text-xs font-medium text-green-700 hover:underline"
                            data-test="vta-correggi"
                            @click="editAssessment(a)"
                        >Correggi</button>
                        <button
                            v-if="canUpdate && ! a.validated_at"
                            class="text-xs font-medium text-green-700 hover:underline disabled:opacity-50"
                            :disabled="validando === a.id"
                            data-test="vta-valida"
                            title="Chiude la perizia: da qui non e' piu' modificabile"
                            @click="validaPerizia(a)"
                        >{{ validando === a.id ? 'Validazione…' : 'Valida e chiudi' }}</button>
                        <button
                            class="text-xs font-medium text-green-700 hover:underline disabled:opacity-50"
                            :disabled="periziaBusy === a.id"
                            data-test="vta-perizia"
                            @click="openPerizia(a.id)"
                        >{{ periziaBusy === a.id ? 'Preparazione…' : 'Perizia PDF' }}</button>
                        <button
                            v-if="canDelete && ! a.validated_at"
                            class="text-xs text-red-500 hover:underline disabled:opacity-50"
                            :disabled="eliminando === a.id"
                            data-test="vta-elimina"
                            title="Solo le bozze si eliminano: una perizia validata si supera con una nuova"
                            @click="eliminaValutazione(a)"
                        >{{ eliminando === a.id ? 'Eliminazione…' : 'Elimina' }}</button>
                        <span v-if="a.report_number" class="text-xs text-gray-400">perizia {{ a.report_number }}</span>
                    </div>

                    <p
                        v-if="a.validated_at"
                        data-test="vta-validata"
                        class="mt-1.5 rounded-lg bg-gray-50 px-2.5 py-1.5 text-xs text-gray-600"
                    >
                        Perizia validata e chiusa il {{ fmtOra(a.validated_at) }}<span v-if="a.validator"> da {{ a.validator.name }}</span>.
                        Il contenuto non e' piu' modificabile: per correggerla registra una nuova perizia su questo albero.
                        <span v-if="a.content_hash" class="block break-all text-gray-400">Impronta {{ String(a.content_hash).slice(0, 16).toUpperCase() }}</span>
                    </p>

                    <div v-if="analyses[a.id]?.open" class="mt-2 border-t border-gray-100 pt-2">
                        <p v-if="analyses[a.id].error && ! analyses[a.id].showForm" class="text-xs text-red-600">{{ analyses[a.id].error }}</p>
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
                                    <td class="py-1.5 text-gray-500">
                                        <span v-for="(v, k) in (an.measures ?? {})" :key="k" class="mr-2">{{ k }}: {{ v }}</span>
                                        {{ an.notes || '' }}
                                        <span v-if="! an.notes && ! Object.keys(an.measures ?? {}).length">—</span>
                                    </td>
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
                            <div class="text-xs">
                                <span class="text-gray-500">Misure lette sullo strumento (finiscono nella perizia)</span>
                                <div
                                    v-for="(m, i) in analyses[a.id].form.measures"
                                    :key="i"
                                    class="mt-1 flex gap-2"
                                >
                                    <input
                                        v-model="m.voce"
                                        placeholder="voce (es. residuo sano)"
                                        :data-test="`misura-voce-${i}`"
                                        class="w-1/2 rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm"
                                    >
                                    <input
                                        v-model="m.valore"
                                        placeholder="valore (es. 62%)"
                                        :data-test="`misura-valore-${i}`"
                                        class="w-1/2 rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm"
                                    >
                                    <button
                                        v-if="analyses[a.id].form.measures.length > 1"
                                        type="button"
                                        class="px-1 text-gray-400 hover:text-gray-600"
                                        @click="analyses[a.id].form.measures.splice(i, 1)"
                                    >✕</button>
                                </div>
                                <button
                                    type="button"
                                    class="mt-1 text-xs font-medium text-green-700 hover:underline"
                                    data-test="aggiungi-misura"
                                    @click="analyses[a.id].form.measures.push({ voce: '', valore: '' })"
                                >+ Aggiungi misura</button>
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
