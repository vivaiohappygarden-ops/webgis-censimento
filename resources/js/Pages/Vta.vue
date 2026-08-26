<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import { avvisoCaricamento } from '@/avvisi';

const data = ref(null);
const tutelati = ref([]);

// Filtri della pagina: il committente vale per cruscotto, elenco e tutelati;
// stato e classe restringono l'elenco e si comandano anche dai riquadri
const filtri = reactive({ client_id: '', status: '', class: '', q: '' });

const STATI = {
    '': 'Tutti gli alberi',
    assessed: 'Con almeno una VTA',
    never: 'Mai valutati',
    overdue: 'Ricontrolli scaduti',
    upcoming: 'In scadenza (30 gg)',
};

const CLASS_COLORS = {
    'A': 'bg-green-100 text-green-800',
    'B': 'bg-lime-100 text-lime-800',
    'C': 'bg-yellow-100 text-yellow-800',
    'C/D': 'bg-orange-100 text-orange-800',
    'D': 'bg-red-100 text-red-800',
    'n.d.': 'bg-gray-100 text-gray-600',
};

const OUTCOMES = { ok: 'Nessun intervento', monitor: 'Monitorare', prescriptions: 'Prescrizioni', fell: 'Abbattimento' };

const fmt = (d) => (d ? new Date(d).toLocaleDateString('it-IT') : '—');

// Elenco degli alberi con l'ultima valutazione: la lista di lavoro
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

function scegliStato(status) {
    filtri.status = status;
    caricaElenco(1);
}

function scegliClasse(cls) {
    const valore = cls === 'n.d.' ? 'nd' : cls;
    filtri.class = filtri.class === valore ? '' : valore;
    caricaElenco(1);
}

const vuotoElenco = computed(() => {
    // Con ricerca o classe attive il messaggio per stato direbbe il falso
    // ("Nessun ricontrollo scaduto" sotto il riquadro che ne conta tre)
    if (filtri.q.trim() || filtri.class) return 'Nessun albero trovato con questi filtri.';

    return {
        assessed: 'Nessun albero con valutazioni registrate.',
        never: 'Nessun albero senza valutazione.',
        overdue: 'Nessun ricontrollo scaduto.',
        upcoming: 'Nessuna scadenza nei prossimi 30 giorni.',
    }[filtri.status] ?? 'Nessun albero trovato.';
});

function specie(r) {
    return r.species || r.genus || r.common_name || '—';
}

function posizione(r) {
    return [r.area_name, r.locality_name].filter(Boolean).join(' · ') || '—';
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
    // lasciare i riquadri sui numeri del committente precedente
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
    try {
        await Promise.all([caricaCruscotto(), caricaElenco(1)]);
    } catch (err) {
        loadError.value = avvisoCaricamento(err);
    }
}

onMounted(async () => {
    try {
        await Promise.all([caricaCruscotto(), caricaElenco(1)]);
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
                    <p class="text-sm text-gray-500">Valutazioni di stabilità: stato del patrimonio arboreo e ricontrolli</p>
                </div>
                <div class="flex flex-wrap items-end gap-2">
                    <label class="block text-xs">
                        <span class="text-gray-500">Committente</span>
                        <select
                            v-model="filtri.client_id"
                            data-test="vta-filtro-committente"
                            class="mt-1 block w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm sm:w-auto"
                            @change="cambiaCommittente"
                        >
                            <option value="">Tutti</option>
                            <option v-for="c in committenti" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </label>
                    <a href="/api/v1/exports/cam?layer=P1&format=geojson" class="rounded-lg border border-green-700 px-3 py-2 text-sm font-medium text-green-700 hover:bg-green-50">
                        Export CAM P1 (GeoJSON)
                    </a>
                    <a href="/api/v1/exports/cam?layer=P1&format=shapefile" class="rounded-lg border border-green-700 px-3 py-2 text-sm font-medium text-green-700 hover:bg-green-50">
                        Export CAM P1 (Shapefile RDN2008)
                    </a>
                </div>
            </div>

            <!-- Cruscotto gia' in pagina ma aggiornamento fallito (es. cambio
                 committente): l'errore va detto, o i numeri vecchi passerebbero
                 per buoni -->
            <p v-if="loadError && data" class="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
                {{ loadError }}
                <button class="ml-1 font-medium underline" @click="cambiaCommittente">Riprova</button>
            </p>

            <div v-if="data" class="space-y-6">
                <!-- Contatori: un clic filtra l'elenco qui sotto -->
                <div class="grid grid-cols-2 gap-4 md:grid-cols-5">
                    <button
                        type="button"
                        data-test="riquadro-totale"
                        class="rounded-xl border bg-white p-4 text-left hover:border-gray-400"
                        :class="filtri.status === '' ? 'border-gray-500 ring-1 ring-gray-400' : 'border-gray-200'"
                        @click="scegliStato('')"
                    >
                        <div class="text-2xl font-semibold">{{ data.trees_total }}</div>
                        <div class="text-xs text-gray-500">Alberi censiti</div>
                    </button>
                    <button
                        type="button"
                        data-test="riquadro-valutati"
                        class="rounded-xl border bg-white p-4 text-left hover:border-gray-400"
                        :class="filtri.status === 'assessed' ? 'border-gray-500 ring-1 ring-gray-400' : 'border-gray-200'"
                        @click="scegliStato('assessed')"
                    >
                        <div class="text-2xl font-semibold">{{ data.assessed }}</div>
                        <div class="text-xs text-gray-500">Con almeno una VTA</div>
                    </button>
                    <button
                        type="button"
                        data-test="riquadro-mai-valutati"
                        class="rounded-xl border bg-white p-4 text-left hover:border-gray-400"
                        :class="filtri.status === 'never' ? 'border-gray-500 ring-1 ring-gray-400' : 'border-gray-200'"
                        @click="scegliStato('never')"
                    >
                        <div class="text-2xl font-semibold text-gray-500">{{ data.never_assessed }}</div>
                        <div class="text-xs text-gray-500">Mai valutati</div>
                    </button>
                    <button
                        type="button"
                        data-test="riquadro-scaduti"
                        class="rounded-xl border bg-red-50 p-4 text-left hover:border-red-400"
                        :class="filtri.status === 'overdue' ? 'border-red-500 ring-1 ring-red-400' : 'border-red-200'"
                        @click="scegliStato('overdue')"
                    >
                        <div class="text-2xl font-semibold text-red-700">{{ data.overdue_count }}</div>
                        <div class="text-xs text-red-600">Ricontrolli scaduti</div>
                    </button>
                    <button
                        type="button"
                        data-test="riquadro-in-scadenza"
                        class="rounded-xl border bg-amber-50 p-4 text-left hover:border-amber-400"
                        :class="filtri.status === 'upcoming' ? 'border-amber-500 ring-1 ring-amber-400' : 'border-amber-200'"
                        @click="scegliStato('upcoming')"
                    >
                        <div class="text-2xl font-semibold text-amber-700">{{ data.upcoming_count }}</div>
                        <div class="text-xs text-amber-600">In scadenza (30 gg)</div>
                    </button>
                </div>

                <!-- Classi: un clic filtra l'elenco, un secondo clic toglie il filtro -->
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <h2 class="text-sm font-semibold">Classi di propensione al cedimento (ultima VTA per albero)</h2>
                    <div class="mt-3 flex flex-wrap gap-3">
                        <button
                            v-for="(count, cls) in data.by_class"
                            :key="cls"
                            type="button"
                            :data-test="`classe-${cls === 'n.d.' ? 'nd' : cls.replace('/', '-')}`"
                            class="rounded-full px-4 py-1.5 text-sm font-semibold"
                            :class="[
                                CLASS_COLORS[cls] ?? 'bg-gray-100',
                                filtri.class === (cls === 'n.d.' ? 'nd' : cls) ? 'ring-2 ring-gray-500' : '',
                            ]"
                            @click="scegliClasse(cls)"
                        >{{ cls }}: {{ count }}</button>
                        <span v-if="! Object.keys(data.by_class).length" class="text-sm text-gray-400">Nessuna valutazione ancora registrata.</span>
                    </div>
                </div>

                <!-- Elenco unico: la lista di lavoro dello scadenzario -->
                <div class="rounded-xl border border-gray-200 bg-white" data-test="vta-elenco">
                    <div class="flex flex-wrap items-end justify-between gap-3 border-b border-gray-100 px-4 py-3">
                        <div>
                            <h2 class="text-sm font-semibold">Alberi e valutazioni ({{ elenco.total }})</h2>
                            <p class="text-xs text-gray-500">
                                Da ogni riga apri la scheda, registri una nuova valutazione di
                                stabilità e stampi la perizia.
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
                                    <th class="px-4 py-2 font-medium">Codice</th>
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
                                    <td class="px-4 py-2 font-medium whitespace-nowrap">{{ r.census_code || r.id.slice(0, 8) }}</td>
                                    <td class="px-4 py-2">{{ specie(r) }}</td>
                                    <td class="px-4 py-2 text-gray-500">
                                        {{ posizione(r) }}
                                        <span v-if="! filtri.client_id && r.client_name" class="block text-xs text-gray-400">{{ r.client_name }}</span>
                                    </td>
                                    <td class="px-4 py-2">
                                        <span v-if="r.failure_class" class="rounded-full px-2 py-0.5 text-xs font-bold" :class="CLASS_COLORS[r.failure_class]">{{ r.failure_class }}</span>
                                        <span v-else-if="r.assessed_on" class="text-xs text-gray-400">n.d.</span>
                                    </td>
                                    <td class="px-4 py-2 text-gray-500 whitespace-nowrap">
                                        <template v-if="r.assessed_on">
                                            {{ fmt(r.assessed_on) }}<span v-if="r.outcome"> · {{ OUTCOMES[r.outcome] }}</span>
                                        </template>
                                        <span v-else class="text-gray-400">mai valutato</span>
                                    </td>
                                    <td class="px-4 py-2 whitespace-nowrap">
                                        <span v-if="r.stato === 'scaduto'" class="font-semibold text-red-600">scaduta {{ fmt(r.next_check_due) }}</span>
                                        <span v-else-if="r.stato === 'in_scadenza'" class="font-medium text-amber-700">entro {{ fmt(r.next_check_due) }}</span>
                                        <span v-else-if="r.next_check_due" class="text-gray-500">{{ fmt(r.next_check_due) }}</span>
                                        <span v-else class="text-gray-400">—</span>
                                    </td>
                                    <td class="px-4 py-2 text-right whitespace-nowrap">
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

                <!-- Bilancio arboreo -->
                <div class="rounded-xl border border-gray-200 bg-white">
                    <div class="flex flex-wrap items-end justify-between gap-3 border-b border-gray-100 px-4 py-3">
                        <div>
                            <h2 class="text-sm font-semibold">Bilancio arboreo (L. 10/2013)</h2>
                            <p class="text-xs text-gray-500">Consistenza del patrimonio arboreo tra due date, con nuovi impianti e abbattimenti</p>
                        </div>
                        <div class="flex flex-wrap items-end gap-2">
                            <label class="block text-xs">
                                <span class="text-gray-500">Committente</span>
                                <select
                                    v-model="balance.client_id"
                                    data-test="bilancio-committente"
                                    class="mt-1 block w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm sm:w-auto"
                                >
                                    <option value="">Tutti</option>
                                    <option v-for="c in committenti" :key="c.id" :value="c.id">{{ c.name }}</option>
                                </select>
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Dal</span>
                                <input v-model="balance.from" type="date" class="mt-1 block rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Al</span>
                                <input v-model="balance.to" type="date" class="mt-1 block rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
                            </label>
                            <button
                                class="rounded-lg bg-green-700 px-3 py-2 text-sm font-medium text-white hover:bg-green-800 disabled:opacity-50"
                                :disabled="balance.loading"
                                @click="loadBalance"
                            >{{ balance.loading ? 'Calcolo…' : 'Calcola' }}</button>
                            <a
                                :href="urlBilancioPdf"
                                data-test="bilancio-pdf"
                                class="rounded-lg border border-green-700 px-3 py-2 text-sm font-medium text-green-700 hover:bg-green-50"
                                :title="balance.client_id ? 'Documento da allegare agli atti del Comune' : 'Scegli un committente per il documento da consegnare all\'ente'"
                            >Scarica il documento</a>
                        </div>
                    </div>
                    <p v-if="balance.error" class="px-4 py-3 text-sm text-red-600">{{ balance.error }}</p>

                    <div v-if="balance.result" class="grid grid-cols-1 gap-0 lg:grid-cols-2">
                        <table class="w-full text-sm">
                            <tbody class="divide-y divide-gray-50">
                                <tr>
                                    <td class="px-4 py-2 text-gray-500">Consistenza iniziale ({{ fmt(balance.result.from) }})</td>
                                    <td class="px-4 py-2 text-right font-semibold">{{ balance.result.initial_count }}</td>
                                </tr>
                                <tr>
                                    <td class="px-4 py-2 text-gray-500">Nuovi impianti nel periodo</td>
                                    <td class="px-4 py-2 text-right font-semibold text-green-700">+{{ balance.result.planted_count }}</td>
                                </tr>
                                <tr>
                                    <td class="px-4 py-2 text-gray-500">Abbattimenti/rimozioni nel periodo</td>
                                    <td class="px-4 py-2 text-right font-semibold text-red-600">−{{ balance.result.felled_count }}</td>
                                </tr>
                                <tr>
                                    <td class="px-4 py-2 text-gray-500">Consistenza finale ({{ fmt(balance.result.to) }})</td>
                                    <td class="px-4 py-2 text-right font-semibold">{{ balance.result.final_count }}</td>
                                </tr>
                                <tr class="bg-gray-50/60">
                                    <td class="px-4 py-2 font-medium">Variazione del periodo</td>
                                    <td class="px-4 py-2 text-right font-semibold" :class="balance.result.variation >= 0 ? 'text-green-700' : 'text-red-600'">
                                        {{ balance.result.variation >= 0 ? '+' : '' }}{{ balance.result.variation }}
                                    </td>
                                </tr>
                                <tr>
                                    <td class="px-4 py-2 text-gray-500">Posti liberi attuali (liberi / riservati / piantumati / non utilizzabili)</td>
                                    <td class="px-4 py-2 text-right">
                                        {{ balance.result.planting_sites.free }} /
                                        {{ balance.result.planting_sites.reserved }} /
                                        {{ balance.result.planting_sites.planted }} /
                                        {{ balance.result.planting_sites.unusable }}
                                    </td>
                                </tr>
                                <tr>
                                    <td class="px-4 py-2 text-gray-500">Sostituzioni completate su posti da abbattimento</td>
                                    <td class="px-4 py-2 text-right">{{ balance.result.planting_sites.replacements_from_felling }}</td>
                                </tr>
                            </tbody>
                        </table>

                        <div class="border-t border-gray-100 lg:border-l lg:border-t-0">
                            <h3 class="px-4 pb-1 pt-3 text-xs font-semibold uppercase text-gray-400">Dettaglio per specie (nel periodo)</h3>
                            <table v-if="balance.result.by_species.length" class="w-full text-sm">
                                <thead>
                                    <tr class="text-left text-xs text-gray-400">
                                        <th class="px-4 py-1 font-medium">Specie</th>
                                        <th class="px-4 py-1 text-right font-medium">Piantati</th>
                                        <th class="px-4 py-1 text-right font-medium">Abbattuti</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-50">
                                    <tr v-for="s in balance.result.by_species" :key="s.species_label">
                                        <td class="px-4 py-1.5">{{ s.species_label }}</td>
                                        <td class="px-4 py-1.5 text-right text-green-700">{{ s.planted }}</td>
                                        <td class="px-4 py-1.5 text-right text-red-600">{{ s.felled }}</td>
                                    </tr>
                                </tbody>
                            </table>
                            <p v-else class="px-4 pb-4 pt-1 text-sm text-gray-400">Nessun impianto o abbattimento nel periodo selezionato.</p>
                        </div>
                    </div>
                </div>

                <!-- Alberi tutelati e dedicati -->
                <div class="rounded-xl border border-gray-200 bg-white">
                    <h2 class="border-b border-gray-100 px-4 py-3 text-sm font-semibold">
                        Alberi monumentali, tutelati e dedicati ({{ tutelati.length }})
                    </h2>
                    <div v-if="tutelati.length" class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs text-gray-400">
                                    <th class="px-4 py-2 font-medium">Codice</th>
                                    <th class="px-4 py-2 font-medium">Specie</th>
                                    <th class="px-4 py-2 font-medium">Qualifica</th>
                                    <th class="px-4 py-2 font-medium">Riferimenti</th>
                                    <th class="px-4 py-2 font-medium">Dedica</th>
                                    <th class="px-4 py-2 text-right font-medium"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                <tr v-for="t in tutelati" :key="t.asset_id" :class="t.removed_on ? 'text-gray-400' : ''">
                                    <td class="px-4 py-2 font-medium">{{ t.census_code || '—' }}{{ t.removed_on ? ' (rimosso)' : '' }}</td>
                                    <td class="px-4 py-2">{{ t.species || t.genus || t.common_name || '—' }}</td>
                                    <td class="px-4 py-2">{{ qualifiche(t) }}</td>
                                    <td class="px-4 py-2 text-gray-500">{{ [t.monumental_ref, t.protection_ref].filter(Boolean).join(' · ') || '—' }}</td>
                                    <td class="px-4 py-2 text-gray-500">
                                        <template v-if="t.is_dedicated && t.dedicated_to?.name">
                                            {{ t.dedicated_to.name }}<template v-if="t.dedicated_to.occasion"> ({{ t.dedicated_to.occasion }})</template><template v-if="t.dedicated_to.date"> · {{ fmt(t.dedicated_to.date) }}</template>
                                        </template>
                                        <template v-else>—</template>
                                    </td>
                                    <td class="px-4 py-2 text-right">
                                        <Link :href="`/censimento/${t.asset_id}`" class="font-medium text-green-700 hover:underline">Apri</Link>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-else class="px-4 py-5 text-sm text-gray-400">Nessun albero monumentale, tutelato o dedicato registrato.</p>
                </div>
            </div>

            <div v-else class="mt-10 text-center" :class="loadError ? 'text-red-600' : 'text-gray-400'">
                {{ loadError || 'Caricamento…' }}
            </div>
        </div>
    </AppLayout>
</template>
