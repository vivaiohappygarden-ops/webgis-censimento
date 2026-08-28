<script setup>
import { onMounted, reactive, ref } from 'vue';
import axios from 'axios';
import AvvisoErrore from '@/Components/AvvisoErrore.vue';
import ScegliCommittente from '@/Components/ScegliCommittente.vue';
import { usaCaricamento } from '@/caricamento';
import { messaggioErrore } from '@/avvisi';
import { fetchPdf } from '@/pdf';

// Stati di avanzamento lavori: bozza -> validato (immutabile) -> fatturato

const props = defineProps({
    clients: { type: Array, default: () => [] },
});

const { avviso, riprovaInCorso, carica, riprova } = usaCaricamento();

const elenco = ref([]);
const totale = ref(0);
const aperto = ref(null);       // SAL aperto nel dettaglio
const aliquote = ref([0, 4, 10, 22]);
const busy = ref(false);
const errore = ref('');

const STATI = {
    bozza: { label: 'Bozza', cls: 'bg-amber-100 text-amber-900' },
    validato: { label: 'Validato', cls: 'bg-green-100 text-green-800' },
    fatturato: { label: 'Fatturato', cls: 'bg-blue-100 text-blue-800' },
};

const eur = (v) => Number(v ?? 0).toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const num = (v) => Number(v ?? 0).toLocaleString('it-IT', { maximumFractionDigits: 2 });
const dataIt = (d) => (d ? d.split('-').reverse().join('/') : '—');

async function caricaElenco() {
    const { data } = await axios.get('/api/v1/sals');
    elenco.value = data.data;
    totale.value = data.totale ?? data.data.length;
}

// Nuovo SAL: committente + periodo
const nuovo = reactive({ apertoForm: false, client_id: '', period_from: '', period_to: '', notes: '', busy: false, errore: '' });

async function creaSal() {
    nuovo.busy = true;
    nuovo.errore = '';
    try {
        const { data } = await axios.post('/api/v1/sals', {
            client_id: nuovo.client_id,
            period_from: nuovo.period_from,
            period_to: nuovo.period_to,
            notes: nuovo.notes || null,
        });
        nuovo.apertoForm = false;
        Object.assign(nuovo, { client_id: '', period_from: '', period_to: '', notes: '' });
        await carica(caricaElenco);
        await apri(data.data.id);
    } catch (err) {
        nuovo.errore = messaggioErrore(err, 'Preparazione non riuscita');
    } finally {
        nuovo.busy = false;
    }
}

async function apri(id) {
    errore.value = '';
    try {
        const { data } = await axios.get(`/api/v1/sals/${id}`);
        aperto.value = data.data;
        aliquote.value = data.aliquote ?? aliquote.value;
    } catch (err) {
        errore.value = messaggioErrore(err, 'Apertura non riuscita');
    }
}

async function azione(fn, conferma = null) {
    // Niente azioni sovrapposte: due risposte in volo insieme potrebbero
    // riscrivere il dettaglio in ordine invertito
    if (busy.value) return;
    if (conferma && ! window.confirm(conferma)) return;
    busy.value = true;
    errore.value = '';
    try {
        await fn();
        await carica(caricaElenco);
    } catch (err) {
        errore.value = messaggioErrore(err, 'Operazione non riuscita');
    } finally {
        busy.value = false;
    }
}

const cambiaAliquota = (riga, valore) => azione(async () => {
    const { data } = await axios.patch(`/api/v1/sals/${aperto.value.id}`, {
        items: [{ id: riga.id, vat_rate: Number(valore) }],
    });
    aperto.value = data.data;
});

const togliRiga = (riga) => azione(async () => {
    const { data } = await axios.delete(`/api/v1/sals/${aperto.value.id}/items/${riga.id}`);
    aperto.value = data.data;
}, 'Togliere questa riga dal SAL? L\'ordine tornerà disponibile per un SAL futuro.');

const salvaNote = () => azione(async () => {
    const { data } = await axios.patch(`/api/v1/sals/${aperto.value.id}`, { notes: aperto.value.notes || null });
    aperto.value = data.data;
});

const valida = () => azione(async () => {
    const { data } = await axios.post(`/api/v1/sals/${aperto.value.id}/valida`);
    aperto.value = data.data;
},
'Vuoi validare questo SAL?\n\nRiceverà il numero e da quel momento non sarà più modificabile: '
+ 'righe, aliquote e importi restano quelli di adesso. Non si torna indietro: '
+ 'per correggerlo si prepara un altro SAL.');

function fatturato() {
    // Annulla sul prompt e' una rinuncia: il passaggio a "fatturato" non si
    // puo' disfare, quindi senza risposta non si parte
    const riferimento = window.prompt('Riferimento della fattura (facoltativo, es. n. 12/2026):', '');
    if (riferimento === null) return;
    azione(async () => {
        const { data } = await axios.post(`/api/v1/sals/${aperto.value.id}/fatturato`, {
            invoice_ref: riferimento.trim() || null,
        });
        aperto.value = data.data;
    });
}

const elimina = () => azione(async () => {
    await axios.delete(`/api/v1/sals/${aperto.value.id}`);
    aperto.value = null;
}, 'Eliminare questa bozza di SAL? Gli ordini torneranno disponibili.');

const stampaBusy = ref(false);

async function stampa() {
    stampaBusy.value = true;
    errore.value = '';
    const res = await fetchPdf(`/api/v1/sals/${aperto.value.id}/pdf`);
    if (res?.error) errore.value = res.error;
    stampaBusy.value = false;
}

onMounted(() => carica(caricaElenco));
</script>

<template>
    <div>
        <AvvisoErrore class="mb-3" :messaggio="avviso" :in-corso="riprovaInCorso" @riprova="riprova" />

        <div class="mb-3 flex flex-wrap items-center gap-2">
            <h2 class="text-sm font-semibold">Stati di avanzamento lavori</h2>
            <button
                class="ml-auto rounded-lg bg-green-700 px-3 py-1.5 text-xs font-medium text-white hover:bg-green-800"
                data-test="nuovo-sal"
                @click="nuovo.apertoForm = ! nuovo.apertoForm"
            >+ Nuovo SAL</button>
        </div>

        <form v-if="nuovo.apertoForm" class="mb-4 space-y-2 rounded-xl bg-green-50/50 p-3" @submit.prevent="creaSal">
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                <label class="block text-xs">
                    <span class="text-gray-500">Committente *</span>
                    <ScegliCommittente v-model="nuovo.client_id" data-test="sal-committente" class="mt-1 w-full" campo-classe="px-2.5 py-2 text-sm" :committenti="clients" />
                </label>
                <label class="block text-xs">
                    <span class="text-gray-500">Dal *</span>
                    <input v-model="nuovo.period_from" type="date" required data-test="sal-dal" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm">
                </label>
                <label class="block text-xs">
                    <span class="text-gray-500">Al *</span>
                    <input v-model="nuovo.period_to" type="date" required data-test="sal-al" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm">
                </label>
            </div>
            <p class="text-xs text-gray-500">
                Il SAL fotografa i lavori completati del committente nel periodo, con le quantità
                consuntivate e i prezzi del listino. Un lavoro già in un altro SAL non viene ripreso.
            </p>
            <p v-if="nuovo.errore" class="text-sm text-red-600" data-test="sal-errore-creazione">{{ nuovo.errore }}</p>
            <button type="submit" :disabled="nuovo.busy" data-test="sal-prepara" class="rounded-lg bg-green-700 px-4 py-2 text-sm font-medium text-white hover:bg-green-800 disabled:opacity-50">
                {{ nuovo.busy ? 'Preparazione…' : 'Prepara il SAL' }}
            </button>
        </form>

        <!-- Un'apertura fallita a dettaglio chiuso deve dirlo qui: dentro il
             dettaglio il messaggio non comparirebbe mai -->
        <p v-if="errore && ! aperto" class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" data-test="sal-errore">{{ errore }}</p>

        <!-- Elenco -->
        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wide text-gray-400">
                        <th class="px-4 py-2">Numero</th>
                        <th class="px-4 py-2">Committente</th>
                        <th class="px-4 py-2">Periodo</th>
                        <th class="px-4 py-2">Stato</th>
                        <th class="px-4 py-2 text-right">Totale (euro)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    <tr
                        v-for="s in elenco"
                        :key="s.id"
                        class="cursor-pointer hover:bg-gray-50"
                        :class="aperto?.id === s.id ? 'bg-green-50/50' : ''"
                        :data-test="`sal-riga-${s.code ?? 'bozza'}`"
                        @click="apri(s.id)"
                    >
                        <td class="px-4 py-2 font-mono text-xs">{{ s.code ?? '(bozza)' }}</td>
                        <td class="px-4 py-2">{{ s.client?.name }}</td>
                        <td class="px-4 py-2 text-gray-600">{{ dataIt(s.period_from) }} – {{ dataIt(s.period_to) }}</td>
                        <td class="px-4 py-2"><span class="rounded-full px-2.5 py-0.5 text-xs font-medium" :class="STATI[s.status]?.cls">{{ STATI[s.status]?.label ?? s.status }}</span></td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ eur(s.totali?.totale) }}</td>
                    </tr>
                    <tr v-if="! elenco.length">
                        <td colspan="5" class="px-4 py-6 text-center text-sm text-gray-400">Nessun SAL ancora: si parte da "Nuovo SAL".</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-if="totale > elenco.length" class="mt-1 text-xs text-gray-500">
            Mostrati i {{ elenco.length }} SAL più recenti di {{ totale }}.
        </p>

        <!-- Dettaglio -->
        <div v-if="aperto" class="mt-4 rounded-xl border border-gray-200 bg-white p-4" data-test="sal-dettaglio">
            <div class="flex flex-wrap items-center gap-2">
                <h3 class="text-sm font-semibold">
                    {{ aperto.code ?? 'SAL in bozza' }} — {{ aperto.client?.name }}
                </h3>
                <span class="rounded-full px-2.5 py-0.5 text-xs font-medium" :class="STATI[aperto.status]?.cls">{{ STATI[aperto.status]?.label }}</span>
                <span class="text-xs text-gray-500">{{ dataIt(aperto.period_from) }} – {{ dataIt(aperto.period_to) }}</span>
                <span v-if="aperto.validated_at" class="text-xs text-gray-400">validato il {{ aperto.validated_at.slice(0, 10).split('-').reverse().join('/') }}<template v-if="aperto.validator"> da {{ aperto.validator.name }}</template></span>
                <span v-if="aperto.invoice_ref" class="text-xs text-gray-400">fattura {{ aperto.invoice_ref }}</span>
                <button class="ml-auto text-gray-400 hover:text-gray-600" @click="aperto = null">✕</button>
            </div>

            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-gray-400">
                            <th class="px-2 py-1.5">Lavoro</th>
                            <th class="px-2 py-1.5 text-right">Quantità</th>
                            <th class="px-2 py-1.5">Unità</th>
                            <th class="px-2 py-1.5 text-right">Prezzo</th>
                            <th class="px-2 py-1.5 text-right">Imponibile</th>
                            <th class="px-2 py-1.5">IVA %</th>
                            <th v-if="aperto.status === 'bozza'" class="px-2 py-1.5"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        <tr v-for="riga in aperto.items" :key="riga.id">
                            <td class="px-2 py-1.5">
                                {{ riga.descrizione }}
                                <span v-if="riga.nota" class="block text-xs text-amber-700">{{ riga.nota }}</span>
                            </td>
                            <td class="px-2 py-1.5 text-right tabular-nums">{{ riga.quantity !== null ? num(riga.quantity) : '—' }}</td>
                            <td class="px-2 py-1.5">{{ riga.unit ?? '—' }}</td>
                            <td class="px-2 py-1.5 text-right tabular-nums">{{ riga.unit_price !== null ? eur(riga.unit_price) : '—' }}</td>
                            <td class="px-2 py-1.5 text-right tabular-nums">{{ eur(riga.imponibile) }}</td>
                            <td class="px-2 py-1.5">
                                <select
                                    v-if="aperto.status === 'bozza'"
                                    :value="riga.vat_rate"
                                    class="rounded-lg border border-gray-300 px-1.5 py-1 text-xs"
                                    :disabled="busy"
                                    data-test="sal-aliquota"
                                    @change="cambiaAliquota(riga, $event.target.value)"
                                >
                                    <option v-for="a in aliquote.includes(riga.vat_rate) ? aliquote : [riga.vat_rate, ...aliquote]" :key="a" :value="a">{{ a }}</option>
                                </select>
                                <span v-else class="tabular-nums">{{ num(riga.vat_rate) }}</span>
                            </td>
                            <td v-if="aperto.status === 'bozza'" class="px-2 py-1.5 text-right">
                                <button class="text-xs text-red-500 hover:underline disabled:opacity-50" :disabled="busy" data-test="sal-togli-riga" @click="togliRiga(riga)">togli</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Totali: gli stessi numeri della stampa -->
            <div class="mt-3 ml-auto w-full max-w-sm space-y-1 text-sm" data-test="sal-totali">
                <div class="flex justify-between"><span class="text-gray-500">Imponibile dei lavori</span><span class="tabular-nums">{{ eur(aperto.totali.imponibile_righe) }}</span></div>
                <div v-if="aperto.totali.spese_generali > 0" class="flex justify-between"><span class="text-gray-500">Spese generali ({{ num(aperto.overhead_pct) }}%)</span><span class="tabular-nums">{{ eur(aperto.totali.spese_generali) }}</span></div>
                <div v-for="voce in aperto.totali.per_aliquota" :key="voce.aliquota" class="flex justify-between">
                    <span class="text-gray-500">IVA {{ num(voce.aliquota) }}% su {{ eur(voce.imponibile) }}</span><span class="tabular-nums">{{ eur(voce.iva) }}</span>
                </div>
                <div class="flex justify-between border-t border-gray-200 pt-1 font-semibold"><span>Totale documento</span><span class="tabular-nums">{{ eur(aperto.totali.totale) }}</span></div>
            </div>

            <label class="mt-3 block text-xs">
                <span class="text-gray-500">Note</span>
                <textarea
                    v-model="aperto.notes"
                    rows="2"
                    maxlength="2000"
                    :disabled="aperto.status !== 'bozza'"
                    class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm"
                    @change="aperto.status === 'bozza' && salvaNote()"
                />
            </label>

            <p v-if="errore" class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" data-test="sal-errore">{{ errore }}</p>

            <div class="mt-3 flex flex-wrap gap-2">
                <button
                    v-if="aperto.status === 'bozza'"
                    class="rounded-lg bg-green-700 px-4 py-2 text-sm font-medium text-white hover:bg-green-800 disabled:opacity-50"
                    :disabled="busy"
                    data-test="sal-valida"
                    title="Assegna il numero e congela il documento"
                    @click="valida"
                >Valida</button>
                <button
                    v-if="aperto.status === 'validato'"
                    class="rounded-lg bg-green-700 px-4 py-2 text-sm font-medium text-white hover:bg-green-800 disabled:opacity-50"
                    :disabled="busy"
                    data-test="sal-fatturato"
                    title="Segna che la fattura e' stata emessa: i pagamenti non si gestiscono qui"
                    @click="fatturato"
                >Segna come fatturato</button>
                <button
                    class="rounded-lg border border-green-700 px-4 py-2 text-sm font-medium text-green-700 hover:bg-green-50 disabled:opacity-50"
                    :disabled="stampaBusy"
                    data-test="sal-pdf"
                    @click="stampa"
                >{{ stampaBusy ? 'Stampa…' : 'Stampa PDF' }}</button>
                <button
                    v-if="aperto.status === 'bozza'"
                    class="ml-auto rounded-lg border border-gray-300 px-4 py-2 text-sm text-red-600 hover:bg-red-50 disabled:opacity-50"
                    :disabled="busy"
                    data-test="sal-elimina"
                    @click="elimina"
                >Elimina la bozza</button>
            </div>
        </div>
    </div>
</template>
