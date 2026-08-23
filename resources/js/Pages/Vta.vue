<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import { avvisoCaricamento } from '@/avvisi';

const data = ref(null);
const tutelati = ref([]);

// Ricerca di un albero per valutarlo o stamparne la perizia senza passare
// dal censimento: qui si lavora per albero, non per elenco
const ricerca = reactive({ q: '', rows: [], loading: false, cercato: false, errore: '' });
let ricercaTimer = null;

async function cercaAlberi() {
    const q = ricerca.q.trim();
    if (q.length < 2) {
        ricerca.rows = [];
        ricerca.cercato = false;
        ricerca.errore = '';

        return;
    }
    ricerca.loading = true;
    try {
        const { data } = await axios.get('/api/v1/assets', {
            params: { q, type_code: 'P103108', hide_removed: 1, per_page: 15 },
        });
        ricerca.rows = data.data;
        ricerca.cercato = true;
        ricerca.errore = '';
    } catch (err) {
        ricerca.rows = [];
        ricerca.cercato = false;
        ricerca.errore = avvisoCaricamento(err);
    } finally {
        ricerca.loading = false;
    }
}

function cercaConAttesa() {
    clearTimeout(ricercaTimer);
    ricercaTimer = setTimeout(cercaAlberi, 300);
}

const CLASS_COLORS = {
    'A': 'bg-green-100 text-green-800',
    'B': 'bg-lime-100 text-lime-800',
    'C': 'bg-yellow-100 text-yellow-800',
    'C/D': 'bg-orange-100 text-orange-800',
    'D': 'bg-red-100 text-red-800',
    'n.d.': 'bg-gray-100 text-gray-600',
};

const fmt = (d) => (d ? new Date(d).toLocaleDateString('it-IT') : '—');

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
    // che serve all'impresa per il proprio quadro d'insieme
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

onMounted(async () => {
    try {
        const [dashboard, protectedTrees] = await Promise.all([
            axios.get('/api/v1/vta/dashboard'),
            axios.get('/api/v1/vta/tutelati'),
        ]);
        data.value = dashboard.data.data;
        tutelati.value = protectedTrees.data.data;
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
            <div class="mb-4 flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-semibold">Scadenzario VTA</h1>
                    <p class="text-sm text-gray-500">Valutazioni di stabilità: stato del patrimonio arboreo e ricontrolli</p>
                </div>
                <div class="flex gap-2">
                    <a href="/api/v1/exports/cam?layer=P1&format=geojson" class="rounded-lg border border-green-700 px-3 py-2 text-sm font-medium text-green-700 hover:bg-green-50">
                        Export CAM P1 (GeoJSON)
                    </a>
                    <a href="/api/v1/exports/cam?layer=P1&format=shapefile" class="rounded-lg border border-green-700 px-3 py-2 text-sm font-medium text-green-700 hover:bg-green-50">
                        Export CAM P1 (Shapefile RDN2008)
                    </a>
                </div>
            </div>

            <div v-if="data" class="space-y-6">
                <!-- Contatori -->
                <div class="grid grid-cols-2 gap-4 md:grid-cols-5">
                    <div class="rounded-xl border border-gray-200 bg-white p-4">
                        <div class="text-2xl font-semibold">{{ data.trees_total }}</div>
                        <div class="text-xs text-gray-500">Alberi censiti</div>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-white p-4">
                        <div class="text-2xl font-semibold">{{ data.assessed }}</div>
                        <div class="text-xs text-gray-500">Con almeno una VTA</div>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-white p-4">
                        <div class="text-2xl font-semibold text-gray-500">{{ data.never_assessed }}</div>
                        <div class="text-xs text-gray-500">Mai valutati</div>
                    </div>
                    <div class="rounded-xl border border-red-200 bg-red-50 p-4">
                        <div class="text-2xl font-semibold text-red-700">{{ data.overdue_count }}</div>
                        <div class="text-xs text-red-600">Ricontrolli scaduti</div>
                    </div>
                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">
                        <div class="text-2xl font-semibold text-amber-700">{{ data.upcoming_count }}</div>
                        <div class="text-xs text-amber-600">In scadenza (30 gg)</div>
                    </div>
                </div>

                <!-- Ricerca per albero: da qui si valuta e si stampa la perizia -->
                <div class="rounded-xl border border-gray-200 bg-white p-4" data-test="vta-ricerca">
                    <h2 class="text-sm font-semibold">Valuta un albero</h2>
                    <p class="text-xs text-gray-500">
                        Cerca l'albero per codice, specie o note: da qui apri la scheda, registri
                        una nuova valutazione di stabilità e stampi la perizia.
                    </p>
                    <input
                        v-model="ricerca.q"
                        type="search"
                        data-test="vta-cerca-albero"
                        placeholder="Codice, specie o note dell'albero…"
                        class="mt-3 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-green-600 focus:outline-none sm:w-96"
                        @input="cercaConAttesa"
                    >
                    <p v-if="ricerca.loading" class="mt-2 text-xs text-gray-400">Ricerca…</p>
                    <p v-else-if="ricerca.errore" class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700">
                        {{ ricerca.errore }}
                        <button class="ml-1 font-medium underline" @click="cercaAlberi">Riprova</button>
                    </p>
                    <p v-else-if="ricerca.cercato && ! ricerca.rows.length" class="mt-2 text-xs text-gray-500">
                        Nessun albero trovato.
                    </p>
                    <ul v-else-if="ricerca.rows.length" class="mt-3 divide-y divide-gray-100 rounded-lg border border-gray-200">
                        <li v-for="a in ricerca.rows" :key="a.id" class="flex flex-wrap items-center gap-2 px-3 py-2 text-sm">
                            <span class="font-medium">{{ a.census_code || a.id.slice(0, 8) }}</span>
                            <span class="text-gray-500">{{ a.area?.name }}</span>
                            <span class="ml-auto flex gap-3">
                                <Link :href="`/censimento/${a.id}?vta=1`" class="font-medium text-green-700 hover:underline">
                                    Nuova valutazione
                                </Link>
                                <Link :href="`/censimento/${a.id}`" class="font-medium text-green-700 hover:underline">
                                    Apri scheda
                                </Link>
                            </span>
                        </li>
                    </ul>
                </div>

                <!-- Classi -->
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <h2 class="text-sm font-semibold">Classi di propensione al cedimento (ultima VTA per albero)</h2>
                    <div class="mt-3 flex flex-wrap gap-3">
                        <span
                            v-for="(count, cls) in data.by_class"
                            :key="cls"
                            class="rounded-full px-4 py-1.5 text-sm font-semibold"
                            :class="CLASS_COLORS[cls] ?? 'bg-gray-100'"
                        >{{ cls }}: {{ count }}</span>
                        <span v-if="! Object.keys(data.by_class).length" class="text-sm text-gray-400">Nessuna valutazione ancora registrata.</span>
                    </div>
                </div>

                <!-- Scaduti -->
                <div class="rounded-xl border border-gray-200 bg-white">
                    <h2 class="border-b border-gray-100 px-4 py-3 text-sm font-semibold text-red-700">
                        Ricontrolli scaduti ({{ data.overdue_count }})
                    </h2>
                    <table v-if="data.overdue.length" class="w-full text-sm">
                        <tbody class="divide-y divide-gray-50">
                            <tr v-for="row in data.overdue" :key="row.tree_id" class="hover:bg-red-50/40">
                                <td class="px-4 py-2 font-medium">{{ row.census_code || '—' }}</td>
                                <td class="px-4 py-2">
                                    <span v-if="row.failure_class" class="rounded-full px-2 py-0.5 text-xs font-bold" :class="CLASS_COLORS[row.failure_class]">{{ row.failure_class }}</span>
                                </td>
                                <td class="px-4 py-2 text-gray-500">VTA {{ fmt(row.assessed_on) }}</td>
                                <td class="px-4 py-2 font-semibold text-red-600">scaduto {{ fmt(row.next_check_due) }}</td>
                                <td class="px-4 py-2 text-right">
                                    <Link :href="`/censimento/${row.tree_id}?vta=1`" class="mr-3 font-medium text-green-700 hover:underline">Valuta</Link>
                                    <Link :href="`/censimento/${row.tree_id}`" class="font-medium text-green-700 hover:underline">Apri</Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else class="px-4 py-5 text-sm text-gray-400">Nessun ricontrollo scaduto.</p>
                </div>

                <!-- In scadenza -->
                <div class="rounded-xl border border-gray-200 bg-white">
                    <h2 class="border-b border-gray-100 px-4 py-3 text-sm font-semibold text-amber-700">
                        In scadenza nei prossimi 30 giorni ({{ data.upcoming_count }})
                    </h2>
                    <table v-if="data.upcoming.length" class="w-full text-sm">
                        <tbody class="divide-y divide-gray-50">
                            <tr v-for="row in data.upcoming" :key="row.tree_id" class="hover:bg-amber-50/40">
                                <td class="px-4 py-2 font-medium">{{ row.census_code || '—' }}</td>
                                <td class="px-4 py-2">
                                    <span v-if="row.failure_class" class="rounded-full px-2 py-0.5 text-xs font-bold" :class="CLASS_COLORS[row.failure_class]">{{ row.failure_class }}</span>
                                </td>
                                <td class="px-4 py-2 text-gray-500">VTA {{ fmt(row.assessed_on) }}</td>
                                <td class="px-4 py-2 font-medium text-amber-700">entro {{ fmt(row.next_check_due) }}</td>
                                <td class="px-4 py-2 text-right">
                                    <Link :href="`/censimento/${row.tree_id}?vta=1`" class="mr-3 font-medium text-green-700 hover:underline">Valuta</Link>
                                    <Link :href="`/censimento/${row.tree_id}`" class="font-medium text-green-700 hover:underline">Apri</Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else class="px-4 py-5 text-sm text-gray-400">Nessuna scadenza nei prossimi 30 giorni.</p>
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
                    <table v-if="tutelati.length" class="w-full text-sm">
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
                    <p v-else class="px-4 py-5 text-sm text-gray-400">Nessun albero monumentale, tutelato o dedicato registrato.</p>
                </div>
            </div>

            <div v-else class="mt-10 text-center" :class="loadError ? 'text-red-600' : 'text-gray-400'">
                {{ loadError || 'Caricamento…' }}
            </div>
        </div>
    </AppLayout>
</template>
