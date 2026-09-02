<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import axios from 'axios';
import AvvisoErrore from '@/Components/AvvisoErrore.vue';
import ScegliCommittente from '@/Components/ScegliCommittente.vue';
import { usaCaricamento } from '@/caricamento';
import { messaggioErrore } from '@/avvisi';
import { fetchPdf } from '@/pdf';

// Stati di avanzamento lavori: bozza -> validato (immutabile) -> fatturato.
// "Incassato" non e' uno stato ma la data d'incasso registrata; "in ritardo"
// e' una condizione derivata (fatturato, non incassato, scadenza passata).

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

// Quadro dei crediti: i numeri li fa il server (la stessa strada dei
// totali di pagina e stampa), qui si mostrano e basta
const crediti = ref(null);
const filtroCredito = ref('');

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

async function caricaCrediti() {
    const { data } = await axios.get('/api/v1/sals/crediti');
    crediti.value = data.data;
}

// Elenco e quadro viaggiano insieme: ogni azione che cambia un SAL
// cambia anche i crediti, e i due riquadri non devono mai contraddirsi
const caricaTutto = () => Promise.all([caricaElenco(), caricaCrediti()]);

// La data di oggi nel calendario locale (toISOString taglierebbe in UTC
// e la sera proporrebbe il giorno sbagliato)
const oggiLocale = () => new Date().toLocaleDateString('sv-SE');

// I numeri in testa: del committente filtrato, o il totale
const quadro = computed(() => {
    if (! crediti.value) return null;
    if (! filtroCredito.value) return crediti.value.totale;
    return crediti.value.per_committente.find((r) => r.client?.id === filtroCredito.value)
        ?? { emesso: 0, da_incassare: 0, scaduto: 0, incassato_anno: 0, ritardo_medio_giorni: null };
});

const righeCrediti = computed(() => {
    const righe = crediti.value?.per_committente ?? [];
    return filtroCredito.value ? righe.filter((r) => r.client?.id === filtroCredito.value) : righe;
});

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
        await carica(caricaTutto);
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
        await carica(caricaTutto);
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

// Registrazione della fattura: il documento fiscale lo emette il
// commercialista, qui se ne scrivono gli estremi sul SAL validato
const fattura = reactive({ apertoForm: false, invoice_ref: '', invoiced_at: '', payment_due_at: '' });

function apriFormFattura() {
    fattura.apertoForm = ! fattura.apertoForm;
    if (fattura.apertoForm) {
        Object.assign(fattura, { invoice_ref: '', invoiced_at: oggiLocale(), payment_due_at: '' });
    }
}

const registraFattura = () => azione(async () => {
    const { data } = await axios.post(`/api/v1/sals/${aperto.value.id}/fatturato`, {
        invoice_ref: fattura.invoice_ref.trim() || null,
        invoiced_at: fattura.invoiced_at || null,
        payment_due_at: fattura.payment_due_at || null,
    });
    aperto.value = data.data;
    fattura.apertoForm = false;
});

// Registrazione dell'incasso: un fatto contabile sul SAL fatturato
const incasso = reactive({ apertoForm: false, paid_at: '', paid_note: '' });

function apriFormIncasso() {
    incasso.apertoForm = ! incasso.apertoForm;
    if (incasso.apertoForm) {
        Object.assign(incasso, { paid_at: oggiLocale(), paid_note: '' });
    }
}

const registraIncasso = () => azione(async () => {
    const { data } = await axios.post(`/api/v1/sals/${aperto.value.id}/incasso`, {
        paid_at: incasso.paid_at,
        paid_note: incasso.paid_note.trim() || null,
    });
    aperto.value = data.data;
    incasso.apertoForm = false;
});

const annullaIncasso = () => azione(async () => {
    const { data } = await axios.delete(`/api/v1/sals/${aperto.value.id}/incasso`);
    aperto.value = data.data;
}, 'Annullare l\'incasso registrato? Il SAL torna "fatturato, da incassare".');

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

onMounted(() => carica(caricaTutto));
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

        <!-- Quadro dei crediti: quanto deve ancora entrare, e da chi -->
        <div v-if="crediti" class="mb-4 rounded-xl border border-gray-200 bg-white p-3" data-test="sal-crediti">
            <div class="flex flex-wrap items-center gap-2">
                <h3 class="text-sm font-semibold">Quadro dei crediti</h3>
                <ScegliCommittente
                    v-model="filtroCredito"
                    class="ml-auto w-full sm:w-64"
                    campo-classe="px-2.5 py-1.5 text-xs"
                    tutti="Tutti i committenti"
                    :committenti="clients"
                    data-test="sal-crediti-committente"
                />
            </div>
            <p class="mt-1 text-xs text-gray-500">
                La fattura la emette il commercialista: qui se ne registrano gli estremi sul SAL validato, poi l'incasso quando il pagamento arriva.
                Un SAL fatturato oltre la scadenza è conteggiato come scaduto finché l'incasso non viene registrato.
            </p>
            <div v-if="quadro" class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-4">
                <div class="rounded-lg bg-gray-50 p-2">
                    <div class="text-xs text-gray-500">Emesso, da fatturare</div>
                    <div class="tabular-nums text-sm font-semibold" data-test="sal-crediti-emesso">{{ eur(quadro.emesso) }}</div>
                </div>
                <div class="rounded-lg bg-gray-50 p-2">
                    <div class="text-xs text-gray-500">Fatturato, da incassare</div>
                    <div class="tabular-nums text-sm font-semibold" data-test="sal-crediti-da-incassare">{{ eur(quadro.da_incassare) }}</div>
                </div>
                <div class="rounded-lg p-2" :class="quadro.scaduto > 0 ? 'bg-red-50' : 'bg-gray-50'">
                    <div class="text-xs" :class="quadro.scaduto > 0 ? 'text-red-700' : 'text-gray-500'">di cui oltre la scadenza</div>
                    <div class="tabular-nums text-sm font-semibold" :class="quadro.scaduto > 0 ? 'text-red-700' : ''" data-test="sal-crediti-scaduto">
                        {{ eur(quadro.scaduto) }}<span v-if="quadro.ritardo_medio_giorni" class="ml-1 text-xs font-normal">(media {{ quadro.ritardo_medio_giorni }} gg)</span>
                    </div>
                </div>
                <div class="rounded-lg bg-gray-50 p-2">
                    <div class="text-xs text-gray-500">Incassato nel {{ crediti.anno }}</div>
                    <div class="tabular-nums text-sm font-semibold" data-test="sal-crediti-incassato">{{ eur(quadro.incassato_anno) }}</div>
                </div>
            </div>
            <div v-if="righeCrediti.length" class="mt-2 overflow-x-auto">
                <table class="w-full text-xs">
                    <thead>
                        <tr class="text-left uppercase tracking-wide text-gray-400">
                            <th class="px-2 py-1">Committente</th>
                            <th class="px-2 py-1 text-right">Emesso</th>
                            <th class="px-2 py-1 text-right">Da incassare</th>
                            <th class="px-2 py-1 text-right">di cui scaduto</th>
                            <th class="px-2 py-1 text-right">Incassato {{ crediti.anno }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        <tr v-for="r in righeCrediti" :key="r.client?.id ?? 'x'" :data-test="`sal-crediti-riga-${r.client?.id}`">
                            <td class="px-2 py-1">{{ r.client?.name ?? '—' }}</td>
                            <td class="px-2 py-1 text-right tabular-nums">{{ eur(r.emesso) }}</td>
                            <td class="px-2 py-1 text-right tabular-nums">{{ eur(r.da_incassare) }}</td>
                            <td class="px-2 py-1 text-right tabular-nums" :class="r.scaduto > 0 ? 'font-medium text-red-700' : ''">
                                {{ eur(r.scaduto) }}<span v-if="r.ritardo_medio_giorni"> ({{ r.ritardo_medio_giorni }} gg)</span>
                            </td>
                            <td class="px-2 py-1 text-right tabular-nums">{{ eur(r.incassato_anno) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Elenco -->
        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wide text-gray-400">
                        <th class="px-4 py-2">Numero</th>
                        <th class="px-4 py-2">Committente</th>
                        <th class="px-4 py-2">Periodo</th>
                        <th class="px-4 py-2">Stato</th>
                        <th class="px-4 py-2">Fattura</th>
                        <th class="px-4 py-2">Scadenza</th>
                        <th class="px-4 py-2">Incasso</th>
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
                        <td class="px-4 py-2 text-xs text-gray-600">
                            <template v-if="s.status === 'fatturato'">{{ s.invoice_ref || dataIt(s.invoiced_at) }}</template>
                            <template v-else>—</template>
                        </td>
                        <!-- Il ritardo si segnala sulla scadenza, in modo sobrio: colore e giorni -->
                        <td class="px-4 py-2 text-xs" :class="s.ritardo_giorni ? 'font-medium text-red-700' : 'text-gray-600'">
                            {{ dataIt(s.payment_due_at) }}<template v-if="s.ritardo_giorni"> (+{{ s.ritardo_giorni }} gg)</template>
                        </td>
                        <td class="px-4 py-2 text-xs text-gray-600">{{ dataIt(s.paid_at) }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ eur(s.totali?.totale) }}</td>
                    </tr>
                    <tr v-if="! elenco.length">
                        <td colspan="8" class="px-4 py-6 text-center text-sm text-gray-400">Nessun SAL ancora: si parte da "Nuovo SAL".</td>
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
                <span v-if="aperto.status === 'fatturato'" class="text-xs text-gray-400" data-test="sal-estremi-fattura">
                    fattura {{ aperto.invoice_ref || 'senza numero' }}<template v-if="aperto.invoiced_at"> del {{ dataIt(aperto.invoiced_at) }}</template><template v-if="aperto.payment_due_at">, scadenza {{ dataIt(aperto.payment_due_at) }}</template>
                </span>
                <span v-if="aperto.ritardo_giorni" class="text-xs font-medium text-red-700" data-test="sal-ritardo">in ritardo di {{ aperto.ritardo_giorni }} {{ aperto.ritardo_giorni === 1 ? 'giorno' : 'giorni' }}</span>
                <span v-if="aperto.paid_at" class="text-xs text-green-700" data-test="sal-incassato">
                    incassato il {{ dataIt(aperto.paid_at) }}<template v-if="aperto.paid_note"> ({{ aperto.paid_note }})</template>
                </span>
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
                    title="Scrive gli estremi della fattura emessa dal commercialista e segna il SAL come fatturato"
                    @click="apriFormFattura"
                >Registra la fattura</button>
                <button
                    v-if="aperto.status === 'fatturato' && ! aperto.paid_at"
                    class="rounded-lg bg-green-700 px-4 py-2 text-sm font-medium text-white hover:bg-green-800 disabled:opacity-50"
                    :disabled="busy"
                    data-test="sal-registra-incasso"
                    title="Scrive la data in cui il pagamento e' arrivato"
                    @click="apriFormIncasso"
                >Registra l'incasso</button>
                <button
                    v-if="aperto.paid_at"
                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-red-600 hover:bg-red-50 disabled:opacity-50"
                    :disabled="busy"
                    data-test="sal-annulla-incasso"
                    @click="annullaIncasso"
                >Annulla l'incasso</button>
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

            <!-- Registrazione della fattura: numero, data e scadenza, tutti
                 facoltativi (un SAL puo' essere segnato fatturato anche solo
                 per stato); senza data vale il giorno di oggi -->
            <form
                v-if="fattura.apertoForm && aperto.status === 'validato'"
                class="mt-3 space-y-2 rounded-xl bg-green-50/50 p-3"
                data-test="sal-form-fattura"
                @submit.prevent="registraFattura"
            >
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                    <label class="block text-xs">
                        <span class="text-gray-500">Numero fattura</span>
                        <input v-model="fattura.invoice_ref" type="text" maxlength="100" placeholder="es. FT 12/2026" data-test="sal-fattura-numero" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm">
                    </label>
                    <label class="block text-xs">
                        <span class="text-gray-500">Data fattura</span>
                        <input v-model="fattura.invoiced_at" type="date" data-test="sal-fattura-data" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm">
                    </label>
                    <label class="block text-xs">
                        <span class="text-gray-500">Scadenza di pagamento</span>
                        <input v-model="fattura.payment_due_at" type="date" data-test="sal-fattura-scadenza" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm">
                    </label>
                </div>
                <p class="text-xs text-gray-500">
                    La fattura la emette il commercialista: qui se ne registrano gli estremi.
                    Con la scadenza compilata il quadro dei crediti segnala i ritardi.
                </p>
                <button type="submit" :disabled="busy" data-test="sal-fattura-salva" class="rounded-lg bg-green-700 px-4 py-2 text-sm font-medium text-white hover:bg-green-800 disabled:opacity-50">Registra e segna come fatturato</button>
            </form>

            <!-- Registrazione dell'incasso: la data del pagamento arrivato -->
            <form
                v-if="incasso.apertoForm && aperto.status === 'fatturato' && ! aperto.paid_at"
                class="mt-3 space-y-2 rounded-xl bg-green-50/50 p-3"
                data-test="sal-form-incasso"
                @submit.prevent="registraIncasso"
            >
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                    <label class="block text-xs">
                        <span class="text-gray-500">Data d'incasso *</span>
                        <input v-model="incasso.paid_at" type="date" required data-test="sal-incasso-data" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm">
                    </label>
                    <label class="block text-xs sm:col-span-2">
                        <span class="text-gray-500">Nota</span>
                        <input v-model="incasso.paid_note" type="text" maxlength="200" placeholder="es. bonifico n. 123 del 05/09" data-test="sal-incasso-nota" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm">
                    </label>
                </div>
                <button type="submit" :disabled="busy" data-test="sal-incasso-salva" class="rounded-lg bg-green-700 px-4 py-2 text-sm font-medium text-white hover:bg-green-800 disabled:opacity-50">Registra l'incasso</button>
            </form>
        </div>
    </div>
</template>
