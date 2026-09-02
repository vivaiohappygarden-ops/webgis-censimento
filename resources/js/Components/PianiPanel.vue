<script setup>
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';
import axios from 'axios';
import ScegliCommittente from '@/Components/ScegliCommittente.vue';
import ScegliVoce from '@/Components/ScegliVoce.vue';
import { avvisoCaricamento, messaggioErrore } from '@/avvisi';

/*
 * Piani di manutenzione pluriennali: per ogni area si dichiara "potatura
 * ogni 3 anni", "sfalcio ogni mese da marzo a ottobre", e il riquadro in
 * fondo genera gli ordini dovuti nel periodo scelto. Prima l'anteprima
 * (stesso metodo della conferma, sul server: il conteggio non puo' mentire),
 * poi la conferma.
 */
const props = defineProps({
    clients: { type: Array, default: () => [] },
    workTypes: { type: Array, default: () => [] },
    teams: { type: Array, default: () => [] },
    canManage: { type: Boolean, default: false },
});

const emit = defineEmits(['generated']);

const MESI = ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno',
    'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];

// --- Elenco dei piani -------------------------------------------------------
const piani = ref([]);
const caricamento = ref(false);
const erroreElenco = ref('');
const filtri = reactive({ client_id: '', area_id: '' });
const areeFiltro = ref([]);

async function carica() {
    caricamento.value = true;
    erroreElenco.value = '';
    try {
        const { data } = await axios.get('/api/v1/piani-manutenzione', {
            params: {
                client_id: filtri.client_id || undefined,
                area_id: filtri.area_id || undefined,
                per_page: 200,
            },
        });
        piani.value = data.data;
    } catch (err) {
        // Un elenco vuoto per errore non deve sembrare "nessun piano"
        erroreElenco.value = avvisoCaricamento(err);
    } finally {
        caricamento.value = false;
    }
}

// Le aree del committente scelto, per il filtro e per il modulo
async function caricaAree(clientId) {
    if (! clientId) return [];
    const { data } = await axios.get('/api/v1/areas', {
        params: { client_id: clientId, per_page: 100 },
    });
    return data.data;
}

watch(() => filtri.client_id, async (clientId) => {
    filtri.area_id = '';
    areeFiltro.value = [];
    if (clientId) {
        try { areeFiltro.value = await caricaAree(clientId); } catch { /* filtro area non disponibile, l'elenco resta filtrabile per committente */ }
    }
    await carica();
});
watch(() => filtri.area_id, carica);

/** "ogni 3 anni", "8 volte l'anno, marzo-ottobre": la frequenza in parole. */
function frequenzaLeggibile(p) {
    let base;
    if (p.interval_months % 12 === 0) {
        const anni = p.interval_months / 12;
        base = anni === 1 ? 'ogni anno' : `ogni ${anni} anni`;
    } else {
        base = p.interval_months === 1 ? 'ogni mese' : `ogni ${p.interval_months} mesi`;
    }
    if (! p.month_from || ! p.month_to) return base;
    const finestra = `${MESI[p.month_from - 1]}-${MESI[p.month_to - 1]}`;
    if (p.interval_months < 12) {
        // Quante volte l'anno ci sta il ciclo nella finestra (che puo'
        // scavalcare il capodanno: novembre-febbraio)
        const lunghezza = p.month_from <= p.month_to
            ? p.month_to - p.month_from + 1
            : 12 - p.month_from + 1 + p.month_to;
        const volte = Math.floor(lunghezza / p.interval_months);
        if (p.interval_months === 1) return `${volte} volte l'anno, ${finestra}`;
        return `${base} (${volte} ${volte === 1 ? 'volta' : 'volte'} l'anno), ${finestra}`;
    }
    return `${base}, ${finestra}`;
}

const committenteDi = (p) => p.area?.locality?.site?.client?.name ?? '';

// --- Creazione e modifica ---------------------------------------------------
const editor = reactive({ open: false, busy: false, error: '', id: null, form: {} });
const areeModulo = ref([]);
// Vero mentre il modulo si sta riempiendo da un piano esistente: il watcher
// sul committente non deve scambiare il riempimento per una scelta
// dell'utente e azzerare l'area appena messa
let riempimentoInCorso = false;

function vuoto() {
    return {
        client_id: '', area_id: '', work_type_id: '', interval_months: 12,
        month_from: '', month_to: '', team_id: '', notes: '', is_active: true,
    };
}

async function apriNuovo() {
    editor.id = null;
    editor.form = vuoto();
    editor.error = '';
    areeModulo.value = [];
    editor.open = true;
}

async function apriModifica(p) {
    riempimentoInCorso = true;
    editor.id = p.id;
    editor.form = {
        client_id: p.area?.locality?.site?.client?.id ?? '',
        area_id: p.area_id,
        work_type_id: p.work_type_id,
        interval_months: p.interval_months,
        month_from: p.month_from ?? '',
        month_to: p.month_to ?? '',
        team_id: p.team_id ?? '',
        notes: p.notes ?? '',
        is_active: p.is_active,
    };
    editor.error = '';
    editor.open = true;
    try { areeModulo.value = await caricaAree(editor.form.client_id); } catch { areeModulo.value = []; }
    // Il watcher sul committente scatta al prossimo giro di Vue: il flag
    // si abbassa dopo, o l'area appena riempita verrebbe azzerata
    await nextTick();
    riempimentoInCorso = false;
}

watch(() => editor.form.client_id, async (clientId, prima) => {
    if (prima === undefined || riempimentoInCorso) return;
    // Cambiando committente si riparte: l'area e la squadra vecchie non
    // appartengono piu' alla scelta
    editor.form.area_id = '';
    areeModulo.value = [];
    if (clientId) {
        try { areeModulo.value = await caricaAree(clientId); } catch { areeModulo.value = []; }
    }
    if (editor.form.team_id && ! squadreAmmesse.value.some((t) => t.id === editor.form.team_id)) {
        editor.form.team_id = '';
    }
});

// Un'impresa esterna appartiene a un committente: nella tendina compare
// solo scegliendo quel committente (le squadre interne per tutti)
const squadreAmmesse = computed(() => props.teams.filter((t) =>
    ! t.is_external || (t.client_id && t.client_id === editor.form.client_id)));
const etichettaSquadra = (t) => t.is_external
    ? `${t.name} — impresa${t.client ? ' di ' + t.client.name : ''}`
    : t.name;

async function salva() {
    editor.busy = true;
    editor.error = '';
    try {
        const f = editor.form;
        const payload = {
            area_id: f.area_id,
            work_type_id: f.work_type_id,
            interval_months: Number(f.interval_months),
            month_from: f.month_from === '' ? null : Number(f.month_from),
            month_to: f.month_to === '' ? null : Number(f.month_to),
            team_id: f.team_id || null,
            notes: f.notes || null,
            is_active: f.is_active,
        };
        if (editor.id) {
            await axios.patch(`/api/v1/piani-manutenzione/${editor.id}`, payload);
        } else {
            await axios.post('/api/v1/piani-manutenzione', payload);
        }
        editor.open = false;
        await carica();
    } catch (err) {
        editor.error = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
            ?? err.response?.data?.message ?? 'Salvataggio non riuscito';
    } finally {
        editor.busy = false;
    }
}

async function elimina(p) {
    if (! window.confirm(`Eliminare il piano "${p.work_type?.name ?? ''} - ${p.area?.name ?? ''}"?\n\nGli ordini già generati restano; non ne verranno generati altri.`)) return;
    try {
        await axios.delete(`/api/v1/piani-manutenzione/${p.id}`);
        await carica();
    } catch (err) {
        erroreElenco.value = messaggioErrore(err, 'Eliminazione non riuscita');
    }
}

// --- Generazione degli ordini del periodo -----------------------------------
// Periodo proposto: i prossimi 12 mesi a partire dal mese corrente
const oggi = new Date();
const mesePer = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
const generazione = reactive({
    da: mesePer(oggi),
    a: mesePer(new Date(oggi.getFullYear(), oggi.getMonth() + 11, 1)),
    busy: false,
    error: '',
    // L'anteprima e' la risposta del server con prova=1: stessa logica
    // della conferma, mai un conteggio calcolato a parte in pagina
    anteprima: null,
    esito: null,
});

// Cambiando il periodo l'anteprima vecchia non vale piu'
watch(() => [generazione.da, generazione.a], () => {
    generazione.anteprima = null;
    generazione.esito = null;
});

async function chiamaGenera(prova) {
    generazione.busy = true;
    generazione.error = '';
    try {
        const { data } = await axios.post('/api/v1/piani-manutenzione/genera', {
            da: generazione.da,
            a: generazione.a,
            ...(prova ? { prova: 1 } : {}),
        });
        if (prova) {
            generazione.anteprima = data.data;
            generazione.esito = null;
        } else {
            generazione.esito = data.data;
            generazione.anteprima = null;
            // Gli ordini nuovi devono comparire in elenco e in agenda
            emit('generated');
        }
    } catch (err) {
        generazione.error = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
            ?? messaggioErrore(err, 'Generazione non riuscita');
    } finally {
        generazione.busy = false;
    }
}

const fmtMese = (m) => (m ? `${m.slice(5, 7)}/${m.slice(0, 4)}` : '—');

onMounted(carica);
</script>

<template>
    <div>
        <p
            v-if="erroreElenco"
            class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700"
            data-test="piani-errore"
        >{{ erroreElenco }}</p>

        <!-- Filtri e nuovo piano -->
        <div class="mb-3 flex flex-wrap items-center gap-2">
            <div class="w-full sm:w-64">
                <ScegliCommittente
                    v-model="filtri.client_id"
                    :committenti="props.clients"
                    tutti="Tutti i committenti"
                />
            </div>
            <select
                v-if="areeFiltro.length"
                v-model="filtri.area_id"
                class="w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm sm:w-auto"
                data-test="piani-filtro-area"
            >
                <option value="">Tutte le aree</option>
                <option v-for="a in areeFiltro" :key="a.id" :value="a.id">{{ a.name }}</option>
            </select>
            <button
                v-if="props.canManage"
                class="ml-auto rounded-lg bg-green-700 px-4 py-1.5 text-sm font-medium text-white hover:bg-green-800"
                data-test="nuovo-piano"
                @click="apriNuovo"
            >Nuovo piano</button>
        </div>

        <!-- Elenco dei piani -->
        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-3 py-2">Area</th>
                        <th class="px-3 py-2">Committente</th>
                        <th class="px-3 py-2">Lavorazione</th>
                        <th class="px-3 py-2">Frequenza</th>
                        <th class="px-3 py-2">Squadra</th>
                        <th class="px-3 py-2">Attivo</th>
                        <th v-if="props.canManage" class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="caricamento">
                        <td colspan="7" class="px-3 py-6 text-center text-gray-400">Caricamento…</td>
                    </tr>
                    <tr v-else-if="! piani.length">
                        <td colspan="7" class="px-3 py-6 text-center text-gray-400">
                            Nessun piano di manutenzione. Con "Nuovo piano" si dichiara la ricorrenza di una lavorazione su un'area.
                        </td>
                    </tr>
                    <tr
                        v-for="p in piani"
                        :key="p.id"
                        class="border-t border-gray-100"
                        :class="p.is_active ? '' : 'text-gray-400'"
                        :data-test="`piano-${p.id}`"
                    >
                        <td class="px-3 py-2 font-medium">{{ p.area?.name ?? 'Area eliminata' }}</td>
                        <td class="px-3 py-2">{{ committenteDi(p) }}</td>
                        <td class="px-3 py-2">{{ p.work_type?.name }}</td>
                        <td class="px-3 py-2">{{ frequenzaLeggibile(p) }}</td>
                        <td class="px-3 py-2">{{ p.team?.name ?? '—' }}</td>
                        <td class="px-3 py-2">{{ p.is_active ? 'Sì' : 'No' }}</td>
                        <td v-if="props.canManage" class="whitespace-nowrap px-3 py-2 text-right">
                            <button class="text-green-700 hover:underline" @click="apriModifica(p)">Modifica</button>
                            <button class="ml-3 text-red-600 hover:underline" @click="elimina(p)">Elimina</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Modulo di creazione e modifica -->
        <div
            v-if="editor.open"
            class="mt-4 rounded-xl border border-gray-200 bg-white p-4"
            data-test="piano-editor"
        >
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-semibold">{{ editor.id ? 'Modifica piano' : 'Nuovo piano' }}</h2>
                <button class="text-gray-400 hover:text-gray-600" aria-label="Chiudi" @click="editor.open = false">✕</button>
            </div>
            <p v-if="editor.error" class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" data-test="piano-editor-errore">{{ editor.error }}</p>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <label class="block text-sm">
                    <span class="text-gray-600">Committente</span>
                    <ScegliCommittente
                        v-model="editor.form.client_id"
                        :committenti="props.clients"
                        campo-classe="mt-1 w-full px-2.5 py-1.5 text-sm"
                    />
                </label>
                <label class="block text-sm">
                    <span class="text-gray-600">Area</span>
                    <ScegliVoce
                        v-model="editor.form.area_id"
                        :voci="areeModulo"
                        :disabilitato="! editor.form.client_id"
                        :segnaposto="editor.form.client_id ? 'Seleziona…' : 'Prima il committente'"
                        campo-classe="mt-1 w-full px-2.5 py-1.5 text-sm"
                        vuoto="Nessun'area per questo committente."
                    />
                </label>
                <label class="block text-sm">
                    <span class="text-gray-600">Lavorazione</span>
                    <select v-model="editor.form.work_type_id" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm" data-test="piano-lavorazione">
                        <option value="" disabled>Seleziona…</option>
                        <option v-for="wt in props.workTypes" :key="wt.id" :value="wt.id">{{ wt.name }}</option>
                    </select>
                </label>
                <label class="block text-sm">
                    <span class="text-gray-600">Ricorre ogni (mesi)</span>
                    <input
                        v-model="editor.form.interval_months"
                        type="number" min="1" max="120"
                        class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm"
                        data-test="piano-intervallo"
                    >
                    <span class="mt-1 block text-xs text-gray-500">36 = ogni 3 anni, 12 = ogni anno, 1 = ogni mese</span>
                </label>
                <div class="text-sm sm:col-span-2">
                    <span class="text-gray-600">Finestra stagionale (facoltativa)</span>
                    <div class="mt-1 flex flex-wrap items-center gap-2">
                        <select v-model="editor.form.month_from" class="rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm" data-test="piano-mese-da">
                            <option value="">Tutto l'anno</option>
                            <option v-for="(m, i) in MESI" :key="i" :value="i + 1">da {{ m }}</option>
                        </select>
                        <select v-model="editor.form.month_to" class="rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm" data-test="piano-mese-a">
                            <option value="">—</option>
                            <option v-for="(m, i) in MESI" :key="i" :value="i + 1">a {{ m }}</option>
                        </select>
                    </div>
                    <span class="mt-1 block text-xs text-gray-500">
                        Con "da novembre a febbraio" la finestra scavalca il capodanno (potature invernali).
                    </span>
                </div>
                <label class="block text-sm">
                    <span class="text-gray-600">Squadra di preferenza</span>
                    <select v-model="editor.form.team_id" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm" data-test="piano-squadra">
                        <option value="">Nessuna</option>
                        <option v-for="t in squadreAmmesse" :key="t.id" :value="t.id">{{ etichettaSquadra(t) }}</option>
                    </select>
                </label>
                <label class="block text-sm">
                    <span class="text-gray-600">Note (finiscono nella descrizione degli ordini)</span>
                    <input v-model="editor.form.notes" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
                </label>
                <label class="flex items-center gap-2 text-sm sm:col-span-2">
                    <input v-model="editor.form.is_active" type="checkbox" class="rounded border-gray-300">
                    <span>Attivo (i piani spenti restano ma non generano)</span>
                </label>
            </div>
            <div class="mt-4 flex flex-wrap gap-2">
                <button
                    class="rounded-lg bg-green-700 px-4 py-1.5 text-sm font-medium text-white hover:bg-green-800 disabled:opacity-50"
                    :disabled="editor.busy || ! editor.form.area_id || ! editor.form.work_type_id || ! editor.form.interval_months"
                    data-test="piano-salva"
                    @click="salva"
                >{{ editor.id ? 'Salva le modifiche' : 'Crea il piano' }}</button>
                <button class="rounded-lg border border-gray-300 px-4 py-1.5 text-sm text-gray-600 hover:bg-gray-50" @click="editor.open = false">Annulla</button>
            </div>
        </div>

        <!-- Generazione degli ordini del periodo -->
        <div v-if="props.canManage" class="mt-4 rounded-xl border border-gray-200 bg-white p-4" data-test="piani-generazione">
            <h2 class="text-sm font-semibold">Genera gli ordini del periodo</h2>
            <p class="mt-1 text-xs text-gray-500">
                Per ogni piano attivo si calcolano le scadenze dovute nel periodo (massimo 12 mesi),
                a partire dall'ultimo lavoro esistente di quell'area e lavorazione. Prima l'anteprima, poi la conferma:
                gli ordini nascono pianificati al primo del mese e si correggono in agenda.
            </p>
            <div class="mt-3 flex flex-wrap items-end gap-2">
                <label class="block text-sm">
                    <span class="text-gray-600">Dal mese</span>
                    <input v-model="generazione.da" type="month" class="mt-1 block w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm sm:w-auto" data-test="genera-da">
                </label>
                <label class="block text-sm">
                    <span class="text-gray-600">Al mese</span>
                    <input v-model="generazione.a" type="month" class="mt-1 block w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm sm:w-auto" data-test="genera-a">
                </label>
                <button
                    class="rounded-lg border border-green-700 px-4 py-1.5 text-sm font-medium text-green-700 hover:bg-green-50 disabled:opacity-50"
                    :disabled="generazione.busy"
                    data-test="genera-anteprima"
                    @click="chiamaGenera(true)"
                >Anteprima</button>
                <button
                    v-if="generazione.anteprima"
                    class="rounded-lg bg-green-700 px-4 py-1.5 text-sm font-medium text-white hover:bg-green-800 disabled:opacity-50"
                    :disabled="generazione.busy || ! generazione.anteprima.creati.length"
                    data-test="genera-conferma"
                    @click="chiamaGenera(false)"
                >Conferma: crea {{ generazione.anteprima.creati.length }} {{ generazione.anteprima.creati.length === 1 ? 'ordine' : 'ordini' }}</button>
            </div>
            <p v-if="generazione.error" class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" data-test="genera-errore">{{ generazione.error }}</p>

            <template v-for="(blocco, tipo) in { anteprima: generazione.anteprima, esito: generazione.esito }" :key="tipo">
                <div v-if="blocco" class="mt-3" :data-test="`genera-${tipo}`">
                    <p class="text-sm">
                        <template v-if="tipo === 'anteprima'">
                            {{ blocco.creati.length }} {{ blocco.creati.length === 1 ? 'ordine verrebbe creato' : 'ordini verrebbero creati' }}<template v-if="blocco.saltati.length">, {{ blocco.saltati.length }} {{ blocco.saltati.length === 1 ? 'voce saltata' : 'voci saltate' }}</template>.
                        </template>
                        <template v-else>
                            {{ blocco.creati.length }} {{ blocco.creati.length === 1 ? 'ordine creato' : 'ordini creati' }}<template v-if="blocco.saltati.length">, {{ blocco.saltati.length }} {{ blocco.saltati.length === 1 ? 'voce saltata' : 'voci saltate' }}</template>.
                        </template>
                    </p>
                    <div v-if="blocco.creati.length" class="mt-2 overflow-x-auto rounded-lg border border-gray-200">
                        <table class="w-full text-xs">
                            <thead class="bg-gray-50 text-left text-gray-500">
                                <tr>
                                    <th class="px-2 py-1.5">Mese</th>
                                    <th class="px-2 py-1.5">Area</th>
                                    <th class="px-2 py-1.5">Lavorazione</th>
                                    <th class="px-2 py-1.5">Squadra</th>
                                    <th v-if="tipo === 'esito'" class="px-2 py-1.5">Codice</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(r, i) in blocco.creati" :key="i" class="border-t border-gray-100">
                                    <td class="px-2 py-1.5 tabular-nums">{{ fmtMese(r.mese) }}</td>
                                    <td class="px-2 py-1.5">{{ r.area }}</td>
                                    <td class="px-2 py-1.5">{{ r.lavorazione }}</td>
                                    <td class="px-2 py-1.5">
                                        {{ r.squadra ?? '—' }}
                                        <span v-if="r.avvertenza" class="block text-amber-700">{{ r.avvertenza }}</span>
                                    </td>
                                    <td v-if="tipo === 'esito'" class="px-2 py-1.5 font-mono">{{ r.codice }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-if="blocco.saltati.length" class="mt-2 overflow-x-auto rounded-lg border border-gray-200">
                        <table class="w-full text-xs">
                            <thead class="bg-gray-50 text-left text-gray-500">
                                <tr>
                                    <th class="px-2 py-1.5">Mese</th>
                                    <th class="px-2 py-1.5">Area</th>
                                    <th class="px-2 py-1.5">Lavorazione</th>
                                    <th class="px-2 py-1.5">Perché è saltata</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(r, i) in blocco.saltati" :key="i" class="border-t border-gray-100">
                                    <td class="px-2 py-1.5 tabular-nums">{{ fmtMese(r.mese) }}</td>
                                    <td class="px-2 py-1.5">{{ r.area }}</td>
                                    <td class="px-2 py-1.5">{{ r.lavorazione }}</td>
                                    <td class="px-2 py-1.5">{{ r.motivo }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </template>
        </div>
    </div>
</template>
