<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import ScegliCommittente from '@/Components/ScegliCommittente.vue';
import { avvisoCaricamento } from '@/avvisi';

const data = ref(null);
const tutelati = ref([]);

const canValidate = computed(() => (usePage().props.auth?.user?.permissions ?? []).includes('assets.update'));

// Filtri della pagina: il committente vale per tutto (cruscotto, fasce,
// elenco, tutelati); stato e classe restringono solo l'elenco
const filtri = reactive({ client_id: '', status: '', class: '', q: '' });

const STATI = {
    '': 'Tutti gli alberi',
    assessed: 'Con almeno una VTA',
    never: 'Mai valutati',
    overdue: 'Ricontrolli scaduti',
    upcoming: 'In scadenza (30 gg)',
    ok: 'Valutati, nessuna scadenza vicina',
};

const CLASS_COLORS = {
    'A': 'bg-green-100 text-green-800',
    'B': 'bg-lime-100 text-lime-800',
    'C': 'bg-yellow-100 text-yellow-800',
    'C/D': 'bg-orange-100 text-orange-800',
    'D': 'bg-red-100 text-red-800',
    'n.d.': 'bg-gray-100 text-gray-600',
};

// La barra delle classi: stessa scala di colori dei distintivi, piu' piena
const BAR_COLORS = {
    'A': '#16a34a', 'B': '#84cc16', 'C': '#facc15', 'C/D': '#f97316', 'D': '#dc2626', 'n.d.': '#9ca3af',
};

const OUTCOMES = { ok: 'Nessun intervento', monitor: 'Monitorare', prescriptions: 'Prescrizioni', fell: 'Abbattimento' };

const fmt = (d) => (d ? new Date(d).toLocaleDateString('it-IT') : '—');

const committenteScelto = computed(() => committenti.value.find((c) => c.id === filtri.client_id) ?? null);

// Le tre fasce dell'agenda: chi e' urgente si vede senza cercarlo
const FASCE = [
    { chiave: 'overdue', titolo: 'Ricontrolli scaduti', testata: 'bg-red-50 text-red-700', bordo: 'border-red-200', vuoto: 'Nessun ricontrollo scaduto.' },
    { chiave: 'upcoming', titolo: 'In scadenza entro 30 giorni', testata: 'bg-amber-50 text-amber-700', bordo: 'border-gray-200', vuoto: 'Nessuna scadenza nei prossimi 30 giorni.' },
    { chiave: 'never', titolo: 'Prima valutazione da programmare', testata: 'bg-gray-50 text-gray-700', bordo: 'border-gray-200', vuoto: 'Tutti gli alberi hanno almeno una valutazione.' },
];
const RIGHE_FASCIA = 5;

const fasce = reactive({
    overdue: { rows: [], total: 0 },
    upcoming: { rows: [], total: 0 },
    never: { rows: [], total: 0 },
    errore: '',
});
let fasceRichiesta = 0;

async function caricaFasce() {
    const richiesta = ++fasceRichiesta;
    try {
        const risposte = await Promise.all(FASCE.map((f) => axios.get('/api/v1/vta/alberi', {
            params: {
                status: f.chiave,
                per_page: RIGHE_FASCIA,
                ...(filtri.client_id ? { client_id: filtri.client_id } : {}),
            },
        })));
        if (richiesta !== fasceRichiesta) return;
        FASCE.forEach((f, i) => {
            fasce[f.chiave].rows = risposte[i].data.data;
            fasce[f.chiave].total = risposte[i].data.total;
        });
        fasce.errore = '';
    } catch (err) {
        if (richiesta !== fasceRichiesta) return;
        fasce.errore = avvisoCaricamento(err);
    }
}

// Elenco completo degli alberi con l'ultima valutazione (il blocco di lavoro)
const elenco = reactive({ rows: [], total: 0, page: 1, lastPage: 1, paginaRichiesta: 1, loading: false, errore: '' });
let elencoTimer = null;
let elencoRichiesta = 0;

async function caricaElenco(pagina = 1) {
    // Un cambio di filtro mentre una risposta e' in volo: vale solo l'ultima
    // richiesta, o l'elenco mostrerebbe i dati del filtro precedente
    const richiesta = ++elencoRichiesta;
    // "Riprova" deve ripetere la pagina chiesta per ultima, non l'ultima
    // riuscita: dopo un cambio filtro fallito a pagina 3 si riparte da 1
    elenco.paginaRichiesta = pagina;
    elenco.loading = true;
    try {
        const { data: res } = await axios.get('/api/v1/vta/alberi', {
            params: {
                page: pagina,
                per_page: 25,
                ...(filtri.client_id ? { client_id: filtri.client_id } : {}),
                ...(filtri.status ? { status: filtri.status } : {}),
                ...(filtri.class ? { class: filtri.class } : {}),
                ...(filtri.q.trim() ? { q: filtri.q.trim() } : {}),
            },
        });
        if (richiesta !== elencoRichiesta) return;
        // Pagina oltre la fine (un filtro ha accorciato l'elenco): si va
        // all'ultima pagina vera invece di mostrare un vuoto che non e' tale
        if (! res.data.length && res.current_page > res.last_page) {
            caricaElenco(Math.max(1, res.last_page));

            return;
        }
        elenco.rows = res.data;
        elenco.total = res.total;
        elenco.page = res.current_page;
        elenco.lastPage = res.last_page;
        elenco.errore = '';
    } catch (err) {
        if (richiesta !== elencoRichiesta) return;
        elenco.rows = [];
        elenco.total = 0;
        elenco.errore = avvisoCaricamento(err);
    } finally {
        if (richiesta === elencoRichiesta) elenco.loading = false;
    }
}

function cercaConAttesa() {
    clearTimeout(elencoTimer);
    elencoTimer = setTimeout(() => caricaElenco(1), 300);
}

function apriElenco(status) {
    filtri.status = status;
    // I conteggi delle fasce non conoscono classe e ricerca: senza azzerarle
    // "Vedi tutti e 12" aprirebbe un elenco con meno alberi dei promessi
    filtri.class = '';
    filtri.q = '';
    caricaElenco(1);
    document.querySelector('[data-test=vta-elenco]')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function scegliClasse(cls) {
    const valore = cls === 'n.d.' ? 'nd' : cls;
    filtri.class = filtri.class === valore ? '' : valore;
    caricaElenco(1);
}

const vuotoElenco = computed(() => {
    // Con ricerca o classe attive il messaggio per stato direbbe il falso
    // ("Nessun ricontrollo scaduto" sotto la fascia che ne conta due)
    if (filtri.q.trim() || filtri.class) return 'Nessun albero trovato con questi filtri.';

    return {
        assessed: 'Nessun albero con valutazioni registrate.',
        never: 'Nessun albero senza valutazione.',
        overdue: 'Nessun ricontrollo scaduto.',
        upcoming: 'Nessuna scadenza nei prossimi 30 giorni.',
        ok: 'Nessun albero valutato con ricontrollo lontano.',
    }[filtri.status] ?? 'Nessun albero trovato.';
});

function specie(r) {
    return r.species || r.genus || r.common_name || '—';
}

function posizione(r) {
    return [r.area_name, r.locality_name].filter(Boolean).join(' · ') || '—';
}

// Selezione per le azioni collettive: si tengono gli id scelti anche
// cambiando pagina; cambiando committente si riparte da zero
const selezione = ref(new Set());

function commuta(id) {
    const s = new Set(selezione.value);
    if (s.has(id)) s.delete(id); else s.add(id);
    selezione.value = s;
}

const selezionabiliPagina = computed(() => elenco.rows.filter((r) => r.n_vta > 0).map((r) => r.id));
const paginaTuttaScelta = computed(() => selezionabiliPagina.value.length > 0
    && selezionabiliPagina.value.every((id) => selezione.value.has(id)));

function commutaPagina() {
    const s = new Set(selezione.value);
    if (paginaTuttaScelta.value) {
        selezionabiliPagina.value.forEach((id) => s.delete(id));
    } else {
        selezionabiliPagina.value.forEach((id) => s.add(id));
    }
    selezione.value = s;
}

// Validazione collettiva: prima la prova a vuoto (si legge cosa succederebbe),
// poi la conferma. Anteprima ed esecuzione passano dallo stesso endpoint.
const validazione = reactive({
    aperta: false, perCommittente: false, inCorso: false, errore: '',
    anteprima: null, esito: null,
});

function corpoValidazione(perCommittente) {
    return perCommittente
        ? { client_id: filtri.client_id }
        : { asset_ids: [...selezione.value] };
}

async function apriValidazione(perCommittente) {
    validazione.aperta = true;
    validazione.perCommittente = perCommittente;
    validazione.anteprima = null;
    validazione.esito = null;
    validazione.errore = '';
    validazione.inCorso = true;
    try {
        const { data: res } = await axios.post('/api/v1/vta/valida', {
            ...corpoValidazione(perCommittente), prova: 1,
        });
        validazione.anteprima = res.data;
    } catch (err) {
        validazione.errore = err.response?.data?.message ?? avvisoCaricamento(err);
    } finally {
        validazione.inCorso = false;
    }
}

async function confermaValidazione() {
    validazione.inCorso = true;
    validazione.errore = '';
    try {
        // Si confermano gli id contati in anteprima, non i filtri: una bozza
        // nata nel frattempo non viene validata senza essere stata mostrata
        const { data: res } = await axios.post('/api/v1/vta/valida', {
            assessment_ids: validazione.anteprima.validate.map((v) => v.id), prova: 0,
        });
        validazione.esito = res.data;
        selezione.value = new Set();
    } catch (err) {
        validazione.errore = err.response?.data?.message ?? avvisoCaricamento(err);
        validazione.inCorso = false;

        return;
    }
    validazione.inCorso = false;
    // Il ricaricamento sta fuori dal try dell'atto: un errore di rete qui non
    // deve coprire l'esito di una validazione ormai riuscita
    try {
        await Promise.all([caricaCruscotto(), caricaFasce(), caricaElenco(elenco.page)]);
    } catch (err) {
        loadError.value = avvisoCaricamento(err);
    }
}

function chiudiValidazione() {
    if (validazione.inCorso) return;
    validazione.aperta = false;
}

// Registro CSV: POST perche' 500 id non stanno in un indirizzo; la risposta
// e' il file, che si consegna al browser come scaricamento
const esportazione = reactive({ inCorso: false, errore: '' });

async function esportaRegistro(perCommittente) {
    esportazione.inCorso = true;
    esportazione.errore = '';
    try {
        const res = await axios.post('/api/v1/vta/registro',
            corpoValidazione(perCommittente), { responseType: 'blob' });
        const url = URL.createObjectURL(res.data);
        const a = document.createElement('a');
        a.href = url;
        // Data locale, non UTC: a ridosso della mezzanotte i due fusi divergono
        const oggi = new Date();
        a.download = `registro_vta_${oggi.getFullYear()}${pad(oggi.getMonth() + 1)}${pad(oggi.getDate())}.csv`;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    } catch (err) {
        // L'errore arriva come blob: va riletto come testo per il messaggio
        let messaggio = '';
        try {
            const corpo = JSON.parse(await err.response?.data?.text?.() ?? '');
            messaggio = corpo.message ?? '';
        } catch { /* risposta non leggibile: resta il messaggio generico */ }
        esportazione.errore = messaggio || avvisoCaricamento(err);
    } finally {
        esportazione.inCorso = false;
    }
}

// Bilancio arboreo (L. 10/2013): dal 1 gennaio dell'anno corrente a oggi
// (data locale, non UTC: a ridosso della mezzanotte i due fusi divergono)
const pad = (n) => String(n).padStart(2, '0');
const today = new Date();
const todayLocal = `${today.getFullYear()}-${pad(today.getMonth() + 1)}-${pad(today.getDate())}`;
const balance = reactive({
    from: `${today.getFullYear()}-01-01`,
    to: todayLocal,
    // Il bilancio di fine mandato riguarda un Comune: sommare piu' enti in un
    // foglio solo darebbe un numero che nessuno puo' usare. Vuoto = tutti,
    // che serve all'impresa per il proprio quadro d'insieme. Il filtro resta
    // suo: e' la scelta di chi riceve il documento, non una vista di lavoro
    client_id: '',
    loading: false,
    error: '',
    result: null,
});
const committenti = ref([]);

async function caricaCommittenti() {
    try {
        const { data } = await axios.get('/api/v1/clients', { params: { per_page: 100 } });
        committenti.value = data.data ?? [];
    } catch {
        committenti.value = [];
    }
}

// Il documento si scarica con i parametri scelti, e per un Comune alla volta
const urlBilancioPdf = computed(() => {
    const p = new URLSearchParams({ from: balance.from, to: balance.to });
    if (balance.client_id) p.set('client_id', balance.client_id);

    return `/api/v1/vta/bilancio/pdf?${p.toString()}`;
});

async function loadBalance() {
    balance.loading = true;
    balance.error = '';
    try {
        const { data: res } = await axios.get('/api/v1/vta/bilancio', {
            params: {
                from: balance.from,
                to: balance.to,
                ...(balance.client_id ? { client_id: balance.client_id } : {}),
            },
        });
        balance.result = res.data;
    } catch (err) {
        balance.error = Object.values(err.response?.data?.errors ?? {})[0]?.[0] ?? 'Errore nel calcolo';
    } finally {
        balance.loading = false;
    }
}

function qualifiche(t) {
    const q = [];
    if (t.is_monumental) q.push('Monumentale');
    if (t.is_protected) q.push('Tutelato');
    if (t.is_dedicated) q.push('Dedicato');
    return q.join(', ');
}

const loadError = ref('');
let cruscottoRichiesta = 0;

async function caricaCruscotto() {
    // Stessa guardia dell'elenco: due cambi rapidi di committente non devono
    // lasciare i numeri sul committente precedente
    const richiesta = ++cruscottoRichiesta;
    const params = filtri.client_id ? { client_id: filtri.client_id } : {};
    try {
        const [dashboard, protectedTrees] = await Promise.all([
            axios.get('/api/v1/vta/dashboard', { params }),
            axios.get('/api/v1/vta/tutelati', { params }),
        ]);
        if (richiesta !== cruscottoRichiesta) return;
        data.value = dashboard.data.data;
        tutelati.value = protectedTrees.data.data;
    } catch (err) {
        // Il fallimento di una richiesta ormai superata non deve coprire
        // con un errore i dati freschi gia' arrivati
        if (richiesta !== cruscottoRichiesta) return;
        throw err;
    }
}

async function cambiaCommittente() {
    loadError.value = '';
    selezione.value = new Set();
    try {
        await Promise.all([caricaCruscotto(), caricaFasce(), caricaElenco(1)]);
    } catch (err) {
        loadError.value = avvisoCaricamento(err);
    }
}

onMounted(async () => {
    try {
        await Promise.all([caricaCruscotto(), caricaFasce(), caricaElenco(1)]);
        // L'elenco dei committenti non deve far fallire il cruscotto se manca
        // il permesso: si carica a parte e in silenzio
        await caricaCommittenti();
        await loadBalance();
    } catch (err) {
        loadError.value = avvisoCaricamento(err);
    }
});
</script>

<template>
    <Head title="Scadenzario VTA" />

    <AppLayout>
        <div class="p-6">
            <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold">Scadenzario VTA</h1>
                    <p class="text-sm text-gray-500">L'agenda delle valutazioni di stabilità, in ordine di urgenza</p>
                </div>
                <label class="block text-xs">
                    <span class="text-gray-500">Committente</span>
                    <ScegliCommittente
                        v-model="filtri.client_id"
                        data-test="vta-filtro-committente"
                        class="mt-1 w-full sm:w-64"
                        :committenti="committenti"
                        tutti="Tutti"
                        @cambia="cambiaCommittente"
                    />
                </label>
            </div>

            <!-- Cruscotto gia' in pagina ma aggiornamento fallito (es. cambio
                 committente): l'errore va detto, o i numeri vecchi passerebbero
                 per buoni -->
            <p v-if="loadError && data" class="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
                {{ loadError }}
                <button class="ml-1 font-medium underline" @click="cambiaCommittente">Riprova</button>
            </p>

            <div v-if="data" class="flex flex-col gap-4 xl:flex-row xl:items-start">

                <!-- Colonna principale -->
                <div class="flex min-w-0 flex-grow flex-col gap-4">

                    <!-- Stato del patrimonio in una sola fascia -->
                    <div class="rounded-xl border border-gray-200 bg-white p-4" data-test="stato-patrimonio">
                        <div class="flex flex-wrap gap-x-7 gap-y-1 text-sm text-gray-500">
                            <span><span class="text-lg font-semibold text-gray-900">{{ data.trees_total }}</span> alberi</span>
                            <span><span class="text-lg font-semibold text-gray-900">{{ data.assessed }}</span> con VTA</span>
                            <span><span class="text-lg font-semibold text-gray-500">{{ data.never_assessed }}</span> mai valutati</span>
                            <span><span class="text-lg font-semibold text-red-700">{{ data.overdue_count }}</span> scaduti</span>
                            <span><span class="text-lg font-semibold text-amber-700">{{ data.upcoming_count }}</span> in scadenza (30 gg)</span>
                        </div>
                        <div v-if="Object.keys(data.by_class).length" class="mt-3 flex flex-wrap items-center gap-3">
                            <div class="flex h-2.5 min-w-40 flex-grow overflow-hidden rounded-full bg-gray-100">
                                <div
                                    v-for="(count, cls) in data.by_class"
                                    :key="cls"
                                    :style="{ flexGrow: count, backgroundColor: BAR_COLORS[cls] ?? '#9ca3af' }"
                                ></div>
                            </div>
                            <div class="flex flex-wrap gap-1.5">
                                <button
                                    v-for="(count, cls) in data.by_class"
                                    :key="cls"
                                    type="button"
                                    :data-test="`classe-${cls === 'n.d.' ? 'nd' : cls.replace('/', '-')}`"
                                    class="rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                    :class="[
                                        CLASS_COLORS[cls] ?? 'bg-gray-100',
                                        filtri.class === (cls === 'n.d.' ? 'nd' : cls) ? 'ring-2 ring-gray-500' : '',
                                    ]"
                                    :title="'Mostra nell\'elenco gli alberi in classe ' + cls"
                                    @click="scegliClasse(cls)"
                                >{{ cls }}: {{ count }}</button>
                            </div>
                        </div>
                        <p v-else class="mt-3 text-sm text-gray-400">Nessuna valutazione ancora registrata.</p>
                    </div>

                    <p v-if="fasce.errore" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
                        {{ fasce.errore }}
                        <button class="ml-1 font-medium underline" @click="caricaFasce">Riprova</button>
                    </p>

                    <!-- Le fasce dell'agenda -->
                    <div
                        v-for="f in FASCE"
                        :key="f.chiave"
                        class="rounded-xl border bg-white"
                        :class="f.bordo"
                        :data-test="`fascia-${f.chiave}`"
                    >
                        <div class="flex items-center justify-between rounded-t-xl px-4 py-2.5 text-sm font-semibold" :class="f.testata">
                            <span>{{ f.titolo }}</span>
                            <span>{{ fasce[f.chiave].total }}</span>
                        </div>
                        <div v-if="fasce[f.chiave].rows.length" class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <tbody class="divide-y divide-gray-50">
                                    <tr v-for="r in fasce[f.chiave].rows" :key="r.id">
                                        <td class="whitespace-nowrap px-4 py-2 font-medium">{{ r.census_code || r.id.slice(0, 8) }}</td>
                                        <td class="px-4 py-2">{{ specie(r) }}</td>
                                        <td class="max-w-64 truncate px-4 py-2 text-gray-500">{{ posizione(r) }}</td>
                                        <td class="px-4 py-2">
                                            <span v-if="r.failure_class" class="rounded-full px-2 py-0.5 text-xs font-bold" :class="CLASS_COLORS[r.failure_class]">{{ r.failure_class }}</span>
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-2">
                                            <span v-if="r.stato === 'scaduto'" class="font-semibold text-red-600">scaduta {{ fmt(r.next_check_due) }}</span>
                                            <span v-else-if="r.stato === 'in_scadenza'" class="font-medium text-amber-700">entro {{ fmt(r.next_check_due) }}</span>
                                            <span v-else class="text-gray-400">mai valutato</span>
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-2 text-right">
                                            <Link :href="`/censimento/${r.id}?vta=1`" class="mr-3 font-medium text-green-700 hover:underline">Valuta</Link>
                                            <Link :href="`/censimento/${r.id}`" class="font-medium text-green-700 hover:underline">Apri</Link>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p v-else class="px-4 py-3 text-sm text-gray-400">{{ f.vuoto }}</p>
                        <div v-if="fasce[f.chiave].total > RIGHE_FASCIA" class="border-t border-gray-100 px-4 py-2 text-sm font-semibold">
                            <button class="text-green-700 hover:underline" :data-test="`vedi-tutti-${f.chiave}`" @click="apriElenco(f.chiave)">
                                Vedi tutti e {{ fasce[f.chiave].total }} →
                            </button>
                        </div>
                    </div>

                    <!-- A posto, ripiegata (comprende anche chi non ha una data di ricontrollo) -->
                    <div class="flex items-center justify-between rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm text-gray-500">
                        <span>Valutati, nessuna scadenza entro 30 giorni: {{ Math.max(0, data.assessed - data.overdue_count - data.upcoming_count) }} {{ data.assessed - data.overdue_count - data.upcoming_count === 1 ? 'albero' : 'alberi' }}</span>
                        <button class="font-semibold text-green-700 hover:underline" data-test="vedi-tutti-ok" @click="apriElenco('ok')">Mostra →</button>
                    </div>

                    <!-- Elenco completo: alberi e valutazioni, con le azioni collettive -->
                    <div class="rounded-xl border border-gray-200 bg-white" data-test="vta-elenco">
                        <div class="flex flex-wrap items-end justify-between gap-3 border-b border-gray-100 px-4 py-3">
                            <div>
                                <h2 class="text-sm font-semibold">Alberi e valutazioni ({{ elenco.total }})</h2>
                                <p class="text-xs text-gray-500">
                                    Da ogni riga apri la scheda o registri una valutazione; con le caselle
                                    scegli gli alberi per la validazione o per il registro in CSV.
                                </p>
                            </div>
                            <div class="flex flex-wrap items-end gap-2">
                                <input
                                    v-model="filtri.q"
                                    type="search"
                                    data-test="vta-cerca-albero"
                                    placeholder="Codice, specie, area o note…"
                                    class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-green-600 focus:outline-none sm:w-64"
                                    @input="cercaConAttesa"
                                >
                                <select
                                    v-model="filtri.status"
                                    data-test="vta-filtro-stato"
                                    class="w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm sm:w-auto"
                                    @change="caricaElenco(1)"
                                >
                                    <option v-for="(label, value) in STATI" :key="value" :value="value">{{ label }}</option>
                                </select>
                                <select
                                    v-model="filtri.class"
                                    data-test="vta-filtro-classe"
                                    class="w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm sm:w-auto"
                                    @change="caricaElenco(1)"
                                >
                                    <option value="">Tutte le classi</option>
                                    <option value="A">Classe A</option>
                                    <option value="B">Classe B</option>
                                    <option value="C">Classe C</option>
                                    <option value="C/D">Classe C/D</option>
                                    <option value="D">Classe D</option>
                                    <option value="nd">Senza classe (n.d.)</option>
                                </select>
                            </div>
                        </div>

                        <!-- Azioni collettive: sulla selezione o sull'intero committente -->
                        <div class="flex flex-wrap items-center gap-2 border-b border-gray-100 bg-gray-50/60 px-4 py-2 text-sm">
                            <span class="text-gray-500">Selezionati: <span class="font-semibold text-gray-900">{{ selezione.size }}</span></span>
                            <template v-if="canValidate">
                                <button
                                    type="button"
                                    data-test="valida-selezione"
                                    class="rounded-lg bg-green-700 px-3 py-1.5 font-medium text-white hover:bg-green-800 disabled:opacity-50"
                                    :disabled="! selezione.size"
                                    :title="selezione.size ? 'Valida le perizie in bozza degli alberi selezionati' : 'Scegli prima gli alberi con le caselle'"
                                    @click="apriValidazione(false)"
                                >Valida le bozze selezionate</button>
                                <button
                                    v-if="filtri.client_id"
                                    type="button"
                                    data-test="valida-committente"
                                    class="rounded-lg border border-green-700 px-3 py-1.5 font-medium text-green-700 hover:bg-green-50"
                                    @click="apriValidazione(true)"
                                >Valida tutte le bozze di {{ committenteScelto?.name }}</button>
                            </template>
                            <button
                                type="button"
                                data-test="esporta-selezione"
                                class="rounded-lg border border-green-700 px-3 py-1.5 font-medium text-green-700 hover:bg-green-50 disabled:border-gray-300 disabled:text-gray-400"
                                :disabled="! selezione.size || esportazione.inCorso"
                                :title="selezione.size ? 'Scarica il registro CSV delle valutazioni degli alberi selezionati' : 'Scegli prima gli alberi con le caselle'"
                                @click="esportaRegistro(false)"
                            >{{ esportazione.inCorso ? 'Preparo il registro…' : 'Esporta il registro (selezione)' }}</button>
                            <button
                                v-if="filtri.client_id"
                                type="button"
                                data-test="esporta-committente"
                                class="rounded-lg border border-green-700 px-3 py-1.5 font-medium text-green-700 hover:bg-green-50 disabled:border-gray-300 disabled:text-gray-400"
                                :disabled="esportazione.inCorso"
                                @click="esportaRegistro(true)"
                            >Esporta il registro di {{ committenteScelto?.name }}</button>
                            <span v-if="esportazione.errore" class="text-red-700">{{ esportazione.errore }}</span>
                        </div>

                        <p v-if="elenco.errore" class="px-4 py-3 text-sm text-red-700">
                            {{ elenco.errore }}
                            <button class="ml-1 font-medium underline" @click="caricaElenco(elenco.paginaRichiesta)">Riprova</button>
                        </p>
                        <p v-else-if="elenco.loading && ! elenco.rows.length" class="px-4 py-5 text-sm text-gray-400">Caricamento…</p>
                        <p v-else-if="! elenco.rows.length" class="px-4 py-5 text-sm text-gray-400">{{ vuotoElenco }}</p>

                        <div v-else class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="text-left text-xs text-gray-400">
                                        <th class="w-8 px-4 py-2">
                                            <input
                                                type="checkbox"
                                                data-test="seleziona-pagina"
                                                :checked="paginaTuttaScelta"
                                                :disabled="! selezionabiliPagina.length"
                                                title="Seleziona gli alberi di questa pagina che hanno valutazioni"
                                                @change="commutaPagina"
                                            >
                                        </th>
                                        <th class="px-2 py-2 font-medium">Codice</th>
                                        <th class="px-4 py-2 font-medium">Specie</th>
                                        <th class="px-4 py-2 font-medium">Area</th>
                                        <th class="px-4 py-2 font-medium">Classe</th>
                                        <th class="px-4 py-2 font-medium">Ultima VTA</th>
                                        <th class="px-4 py-2 font-medium">Prossima verifica</th>
                                        <th class="px-4 py-2 text-right font-medium"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-50">
                                    <tr v-for="r in elenco.rows" :key="r.id" class="hover:bg-gray-50" data-test="vta-riga">
                                        <td class="px-4 py-2">
                                            <input
                                                type="checkbox"
                                                data-test="vta-seleziona"
                                                :checked="selezione.has(r.id)"
                                                :disabled="! r.n_vta"
                                                :title="r.n_vta ? '' : 'Nessuna valutazione registrata'"
                                                @change="commuta(r.id)"
                                            >
                                        </td>
                                        <td class="whitespace-nowrap px-2 py-2 font-medium">{{ r.census_code || r.id.slice(0, 8) }}</td>
                                        <td class="px-4 py-2">{{ specie(r) }}</td>
                                        <td class="max-w-56 px-4 py-2 text-gray-500">
                                            <span class="block truncate" :title="posizione(r)">{{ posizione(r) }}</span>
                                            <span v-if="! filtri.client_id && r.client_name" class="block truncate text-xs text-gray-400">{{ r.client_name }}</span>
                                        </td>
                                        <td class="px-4 py-2">
                                            <span v-if="r.failure_class" class="rounded-full px-2 py-0.5 text-xs font-bold" :class="CLASS_COLORS[r.failure_class]">{{ r.failure_class }}</span>
                                            <span v-else-if="r.assessed_on" class="text-xs text-gray-400">n.d.</span>
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-2 text-gray-500">
                                            <template v-if="r.assessed_on">
                                                {{ fmt(r.assessed_on) }}<span v-if="r.outcome"> · {{ OUTCOMES[r.outcome] }}</span>
                                                <span class="block text-xs" :class="r.n_bozze > 0 ? 'text-amber-700' : 'text-green-700'">
                                                    {{ r.n_vta }} {{ r.n_vta === 1 ? 'valutazione' : 'valutazioni' }}<template v-if="r.n_bozze > 0"> · {{ r.n_bozze }} in bozza</template><template v-else> · {{ r.n_vta === 1 ? 'validata' : 'tutte validate' }}</template>
                                                </span>
                                            </template>
                                            <span v-else class="text-gray-400">mai valutato</span>
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-2">
                                            <span v-if="r.stato === 'scaduto'" class="font-semibold text-red-600">scaduta {{ fmt(r.next_check_due) }}</span>
                                            <span v-else-if="r.stato === 'in_scadenza'" class="font-medium text-amber-700">entro {{ fmt(r.next_check_due) }}</span>
                                            <span v-else-if="r.next_check_due" class="text-gray-500">{{ fmt(r.next_check_due) }}</span>
                                            <span v-else class="text-gray-400">—</span>
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-2 text-right">
                                            <Link :href="`/censimento/${r.id}?vta=1`" class="mr-3 font-medium text-green-700 hover:underline">Valuta</Link>
                                            <Link :href="`/censimento/${r.id}`" class="font-medium text-green-700 hover:underline">Apri</Link>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div v-if="elenco.lastPage > 1 && ! elenco.errore" class="flex items-center justify-between border-t border-gray-100 px-4 py-2 text-sm">
                            <button
                                type="button"
                                data-test="elenco-precedente"
                                class="font-medium text-green-700 hover:underline disabled:text-gray-300 disabled:no-underline"
                                :disabled="elenco.page <= 1 || elenco.loading"
                                @click="caricaElenco(elenco.page - 1)"
                            >← Precedente</button>
                            <span class="text-gray-500">Pagina {{ elenco.page }} di {{ elenco.lastPage }}</span>
                            <button
                                type="button"
                                data-test="elenco-successiva"
                                class="font-medium text-green-700 hover:underline disabled:text-gray-300 disabled:no-underline"
                                :disabled="elenco.page >= elenco.lastPage || elenco.loading"
                                @click="caricaElenco(elenco.page + 1)"
                            >Successiva →</button>
                        </div>
                    </div>
                </div>

                <!-- Colonna degli strumenti -->
                <div class="flex w-full flex-shrink-0 flex-col gap-4 xl:w-80">

                    <!-- Bilancio arboreo, compatto: il dettaglio per specie e' nel documento -->
                    <div class="rounded-xl border border-gray-200 bg-white p-4">
                        <h2 class="text-sm font-semibold">Bilancio arboreo (L. 10/2013)</h2>
                        <p class="text-xs text-gray-500">Consistenza tra due date, con impianti e abbattimenti</p>
                        <label class="mt-2 block text-xs">
                            <span class="text-gray-500">Committente</span>
                            <ScegliCommittente
                                v-model="balance.client_id"
                                data-test="bilancio-committente"
                                class="mt-1 w-full"
                                :committenti="committenti"
                                tutti="Tutti"
                            />
                        </label>
                        <div class="mt-2 flex gap-2">
                            <label class="block flex-grow text-xs">
                                <span class="text-gray-500">Dal</span>
                                <input v-model="balance.from" type="date" class="mt-1 block w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
                            </label>
                            <label class="block flex-grow text-xs">
                                <span class="text-gray-500">Al</span>
                                <input v-model="balance.to" type="date" class="mt-1 block w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
                            </label>
                        </div>
                        <div class="mt-2 flex gap-2">
                            <button
                                class="rounded-lg bg-green-700 px-3 py-1.5 text-sm font-medium text-white hover:bg-green-800 disabled:opacity-50"
                                :disabled="balance.loading"
                                @click="loadBalance"
                            >{{ balance.loading ? 'Calcolo…' : 'Calcola' }}</button>
                            <a
                                :href="urlBilancioPdf"
                                data-test="bilancio-pdf"
                                class="rounded-lg border border-green-700 px-3 py-1.5 text-sm font-medium text-green-700 hover:bg-green-50"
                                :title="balance.client_id ? 'Documento da allegare agli atti del Comune' : 'Scegli un committente per il documento da consegnare all\'ente'"
                            >Scarica il documento</a>
                        </div>
                        <p v-if="balance.error" class="mt-2 text-sm text-red-600">{{ balance.error }}</p>
                        <dl v-if="balance.result" class="mt-3 space-y-1 border-t border-gray-100 pt-2 text-sm">
                            <div class="flex justify-between"><dt class="text-gray-500">Consistenza iniziale ({{ fmt(balance.result.from) }})</dt><dd class="font-semibold">{{ balance.result.initial_count }}</dd></div>
                            <div class="flex justify-between"><dt class="text-gray-500">Nuovi impianti</dt><dd class="font-semibold text-green-700">+{{ balance.result.planted_count }}</dd></div>
                            <div class="flex justify-between"><dt class="text-gray-500">Abbattimenti/rimozioni</dt><dd class="font-semibold text-red-600">−{{ balance.result.felled_count }}</dd></div>
                            <div class="flex justify-between"><dt class="text-gray-500">Consistenza finale ({{ fmt(balance.result.to) }})</dt><dd class="font-semibold">{{ balance.result.final_count }}</dd></div>
                            <div class="flex justify-between border-t border-gray-100 pt-1"><dt class="font-medium">Variazione del periodo</dt><dd class="font-semibold" :class="balance.result.variation >= 0 ? 'text-green-700' : 'text-red-600'">{{ balance.result.variation >= 0 ? '+' : '' }}{{ balance.result.variation }}</dd></div>
                            <div class="flex justify-between"><dt class="text-gray-500">Posti liberi / riservati / piantumati / non usabili</dt><dd class="text-right">{{ balance.result.planting_sites.free }} / {{ balance.result.planting_sites.reserved }} / {{ balance.result.planting_sites.planted }} / {{ balance.result.planting_sites.unusable }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-gray-500">Sostituzioni su posti da abbattimento</dt><dd class="text-right">{{ balance.result.planting_sites.replacements_from_felling }}</dd></div>
                        </dl>
                    </div>

                    <!-- Export CAM -->
                    <div class="rounded-xl border border-gray-200 bg-white p-4">
                        <h2 class="text-sm font-semibold">Export CAM</h2>
                        <div class="mt-2 flex flex-col gap-2">
                            <a href="/api/v1/exports/cam?layer=P1&format=geojson" class="rounded-lg border border-green-700 px-3 py-1.5 text-center text-sm font-medium text-green-700 hover:bg-green-50">
                                P1 (GeoJSON)
                            </a>
                            <a href="/api/v1/exports/cam?layer=P1&format=shapefile" class="rounded-lg border border-green-700 px-3 py-1.5 text-center text-sm font-medium text-green-700 hover:bg-green-50">
                                P1 (Shapefile RDN2008)
                            </a>
                        </div>
                    </div>

                    <!-- Alberi tutelati e dedicati -->
                    <div class="rounded-xl border border-gray-200 bg-white" data-test="tutelati">
                        <h2 class="px-4 py-3 text-sm font-semibold">Alberi monumentali, tutelati e dedicati ({{ tutelati.length }})</h2>
                        <ul v-if="tutelati.length" class="divide-y divide-gray-50 border-t border-gray-100">
                            <li v-for="t in tutelati" :key="t.asset_id" class="flex items-center justify-between gap-2 px-4 py-2 text-sm" :class="t.removed_on ? 'text-gray-400' : ''">
                                <span class="min-w-0">
                                    <Link :href="`/censimento/${t.asset_id}`" class="font-medium text-green-700 hover:underline">{{ t.census_code || '—' }}</Link>
                                    <span class="text-gray-500"> · {{ t.species || t.genus || t.common_name || '—' }}</span>{{ t.removed_on ? ' (rimosso)' : '' }}
                                </span>
                                <span class="whitespace-nowrap text-xs text-gray-500">{{ qualifiche(t) }}</span>
                            </li>
                        </ul>
                        <p v-else class="border-t border-gray-100 px-4 py-3 text-sm text-gray-400">Nessun albero monumentale, tutelato o dedicato registrato.</p>
                    </div>
                </div>
            </div>

            <div v-else class="mt-10 text-center" :class="loadError ? 'text-red-600' : 'text-gray-400'">
                {{ loadError || 'Caricamento…' }}
            </div>

            <!-- Finestra della validazione collettiva -->
            <div v-if="validazione.aperta" class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/40 p-4" data-test="modale-validazione">
                <div class="w-full max-w-lg rounded-xl bg-white shadow-xl">
                    <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3">
                        <h2 class="text-sm font-semibold">Validazione collettiva delle perizie</h2>
                        <button class="px-1 text-gray-400 hover:text-gray-600" :disabled="validazione.inCorso" @click="chiudiValidazione">✕</button>
                    </div>
                    <div class="max-h-96 space-y-3 overflow-y-auto px-4 py-3 text-sm">
                        <p v-if="validazione.errore" class="rounded-lg bg-red-50 px-3 py-2 text-red-700">{{ validazione.errore }}</p>
                        <p v-else-if="validazione.inCorso && ! validazione.anteprima" class="text-gray-400">Conto le perizie…</p>

                        <template v-else-if="validazione.esito">
                            <p class="rounded-lg bg-green-50 px-3 py-2 font-medium text-green-800">
                                {{ validazione.esito.validate.length === 1 ? 'Validata 1 perizia' : `Validate ${validazione.esito.validate.length} perizie` }}{{ validazione.esito.saltate.length ? `, ${validazione.esito.saltate.length === 1 ? '1 saltata' : validazione.esito.saltate.length + ' saltate'}` : '' }}.
                            </p>
                            <ul v-if="validazione.esito.saltate.length" class="space-y-1 text-xs text-gray-600">
                                <li v-for="s in validazione.esito.saltate.slice(0, 10)" :key="s.id">
                                    <span class="font-medium">{{ s.codice || '—' }}</span>: {{ s.motivo }}
                                </li>
                                <li v-if="validazione.esito.saltate.length > 10">… e {{ validazione.esito.saltate.length - 10 === 1 ? "un'altra" : `altre ${validazione.esito.saltate.length - 10}` }}.</li>
                            </ul>
                        </template>

                        <template v-else-if="validazione.anteprima">
                            <p>
                                {{ validazione.perCommittente
                                    ? `Perizie in bozza di ${committenteScelto?.name ?? 'questo committente'}:`
                                    : 'Perizie in bozza degli alberi selezionati:' }}
                                <span class="font-semibold">{{ validazione.anteprima.validate.length }}</span> da validare<template v-if="validazione.anteprima.saltate.length">,
                                <span class="font-semibold">{{ validazione.anteprima.saltate.length }}</span> {{ validazione.anteprima.saltate.length === 1 ? 'esclusa' : 'escluse' }}</template>.
                            </p>
                            <ul v-if="validazione.anteprima.saltate.length" class="space-y-1 text-xs text-gray-600">
                                <li v-for="s in validazione.anteprima.saltate.slice(0, 10)" :key="s.id">
                                    <span class="font-medium">{{ s.codice || '—' }}</span>: {{ s.motivo }}
                                </li>
                                <li v-if="validazione.anteprima.saltate.length > 10">… e {{ validazione.anteprima.saltate.length - 10 === 1 ? "un'altra" : `altre ${validazione.anteprima.saltate.length - 10}` }}.</li>
                            </ul>
                            <p v-if="validazione.anteprima.validate.length" class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
                                La validazione assegna il numero di protocollo e blocca il contenuto
                                tecnico: non si torna indietro. Una perizia sbagliata si supera con
                                una perizia successiva.
                            </p>
                            <p v-else class="text-gray-500">Non c'è nessuna perizia in bozza da validare.</p>
                        </template>
                    </div>
                    <div class="flex justify-end gap-2 border-t border-gray-100 px-4 py-3">
                        <button
                            class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50"
                            :disabled="validazione.inCorso"
                            @click="chiudiValidazione"
                        >Chiudi</button>
                        <button
                            v-if="! validazione.esito && validazione.anteprima?.validate.length"
                            data-test="conferma-validazione"
                            class="rounded-lg bg-green-700 px-3 py-1.5 text-sm font-medium text-white hover:bg-green-800 disabled:opacity-50"
                            :disabled="validazione.inCorso"
                            @click="confermaValidazione"
                        >{{ validazione.inCorso ? 'Validazione…' : (validazione.anteprima.validate.length === 1 ? 'Valida 1 perizia' : `Valida ${validazione.anteprima.validate.length} perizie`) }}</button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
