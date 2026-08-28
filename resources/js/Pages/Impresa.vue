<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import AvvisoErrore from '@/Components/AvvisoErrore.vue';
import { usaCaricamento } from '@/caricamento';
import { messaggioErrore } from '@/avvisi';

// Il portale dell'impresa appaltatrice: i soli ordini affidati alle sue
// squadre, con la richiesta formale di riprogrammazione (motivo codificato)

const { avviso, riprovaInCorso, carica, riprova } = usaCaricamento();

const dati = ref(null);

const CLASSI_STATO = {
    planned: 'bg-blue-100 text-blue-800',
    assigned: 'bg-indigo-100 text-indigo-800',
    in_progress: 'bg-amber-100 text-amber-900',
    suspended: 'bg-gray-200 text-gray-700',
    completed: 'bg-green-100 text-green-800',
};

async function caricaOrdini() {
    const { data } = await axios.get('/api/v1/impresa/ordini');
    dati.value = data;
}

// Una sola richiesta alla volta in compilazione, agganciata all'ordine
const richiesta = reactive({ ordineId: null, reason: 'maltempo', proposed_start: '', notes: '', busy: false, errore: '' });

function apriRichiesta(ordine) {
    richiesta.ordineId = richiesta.ordineId === ordine.id ? null : ordine.id;
    richiesta.reason = 'maltempo';
    richiesta.proposed_start = '';
    richiesta.notes = '';
    richiesta.errore = '';
}

async function inviaRichiesta(ordine) {
    richiesta.busy = true;
    richiesta.errore = '';
    try {
        await axios.post(`/api/v1/impresa/ordini/${ordine.id}/riprogrammazione`, {
            reason: richiesta.reason,
            proposed_start: richiesta.proposed_start || null,
            notes: richiesta.notes || null,
        });
    } catch (err) {
        richiesta.errore = messaggioErrore(err, 'Invio non riuscito');
        richiesta.busy = false;

        return;
    }
    richiesta.busy = false;
    richiesta.ordineId = null;
    // La ricarica passa dal solito avviso con "Riprova": se fallisse in
    // silenzio, l'elenco vecchio farebbe credere che l'invio non sia andato
    await carica(caricaOrdini);
}

const fmt = (d) => (d ? d.split('-').reverse().join('/') : '—');
const periodo = (o) => {
    if (o.planned_start && o.planned_end) return `${fmt(o.planned_start)} – ${fmt(o.planned_end)}`;
    if (o.planned_start) return `dal ${fmt(o.planned_start)}`;
    if (o.planned_end) return `entro ${fmt(o.planned_end)}`;

    return 'da concordare';
};

const inCorso = computed(() => (dati.value?.data ?? []).filter((o) => o.status !== 'completed'));
const completati = computed(() => (dati.value?.data ?? []).filter((o) => o.status === 'completed'));

onMounted(() => carica(caricaOrdini));
</script>

<template>
    <Head title="I lavori affidati" />
    <AppLayout>
        <div class="mx-auto max-w-3xl">
            <h1 class="text-xl font-semibold">I lavori affidati alla tua impresa</h1>
            <p class="mt-1 text-sm text-gray-500">
                Qui vedi gli ordini di lavoro assegnati alle squadre di cui fai parte
                <template v-if="dati?.squadre?.length">({{ dati.squadre.map((s) => s.name).join(', ') }})</template>.
                Se una data non va bene, chiedi la riprogrammazione: la richiesta arriva
                a chi gestisce i lavori, che risponde da qui.
            </p>

            <AvvisoErrore class="mt-4" :messaggio="avviso" :in-corso="riprovaInCorso" @riprova="riprova" />

            <div v-if="dati" class="mt-4 space-y-3">
                <div
                    v-for="ordine in inCorso"
                    :key="ordine.id"
                    class="rounded-xl border border-gray-200 bg-white p-4"
                    :data-test="`impresa-ordine-${ordine.code || ordine.id}`"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-mono text-xs text-gray-500">{{ ordine.code }}</span>
                        <span class="font-semibold">{{ ordine.title }}</span>
                        <span class="rounded-full px-2.5 py-0.5 text-xs font-medium" :class="CLASSI_STATO[ordine.status] ?? 'bg-gray-100 text-gray-600'">
                            {{ dati.stati[ordine.status] ?? ordine.status }}
                        </span>
                    </div>
                    <dl class="mt-2 grid grid-cols-1 gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                        <div class="flex justify-between gap-3"><dt class="text-gray-500">Dove</dt><dd class="text-right">{{ [ordine.area, ordine.localita, ordine.comune].filter(Boolean).join(' — ') || '—' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-gray-500">Periodo previsto</dt><dd>{{ periodo(ordine) }}</dd></div>
                        <div v-if="ordine.work_type" class="flex justify-between gap-3"><dt class="text-gray-500">Lavorazione</dt><dd class="text-right">{{ ordine.work_type }}</dd></div>
                    </dl>
                    <p v-if="ordine.description" class="mt-2 whitespace-pre-line text-sm text-gray-600">{{ ordine.description }}</p>
                    <p v-if="ordine.risks" class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900">Rischi: {{ ordine.risks }}</p>
                    <p v-if="(ordine.ppe ?? []).length" class="mt-1 text-xs text-gray-500">DPI richiesti: {{ ordine.ppe.join(', ') }}</p>

                    <!-- Richieste già fatte e loro esito -->
                    <ul v-if="ordine.richieste.length" class="mt-3 space-y-1.5">
                        <li
                            v-for="r in ordine.richieste"
                            :key="r.id"
                            class="rounded-lg px-3 py-2 text-xs"
                            :class="r.status === 'aperta' ? 'bg-blue-50 text-blue-900' : (r.status === 'accettata' ? 'bg-green-50 text-green-900' : 'bg-gray-100 text-gray-600')"
                            :data-test="`richiesta-${r.status}`"
                        >
                            Riprogrammazione chiesta ({{ r.motivo }}<template v-if="r.proposed_start">, proposta {{ fmt(r.proposed_start) }}</template>):
                            <strong>{{ { aperta: 'in attesa di risposta', accettata: 'accettata', rifiutata: 'non accolta' }[r.status] }}</strong>.
                            <span v-if="r.response_note" class="block text-gray-600">Risposta: {{ r.response_note }}</span>
                        </li>
                    </ul>

                    <div class="mt-3">
                        <button
                            v-if="! ordine.richieste.some((r) => r.status === 'aperta')"
                            class="rounded-lg border border-green-700 px-3 py-1.5 text-xs font-medium text-green-700 hover:bg-green-50"
                            data-test="chiedi-riprogrammazione"
                            @click="apriRichiesta(ordine)"
                        >Chiedi una riprogrammazione</button>

                        <form
                            v-if="richiesta.ordineId === ordine.id"
                            class="mt-2 space-y-2 rounded-lg bg-gray-50 p-3"
                            data-test="modulo-riprogrammazione"
                            @submit.prevent="inviaRichiesta(ordine)"
                        >
                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                <label class="block text-xs">
                                    <span class="text-gray-500">Motivo *</span>
                                    <select v-model="richiesta.reason" data-test="riprogrammazione-motivo" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-2 text-sm">
                                        <option v-for="(label, value) in dati.motivi" :key="value" :value="value">{{ label }}</option>
                                    </select>
                                </label>
                                <label class="block text-xs">
                                    <span class="text-gray-500">Nuova data proposta (se la sai già)</span>
                                    <input v-model="richiesta.proposed_start" type="date" data-test="riprogrammazione-data" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm">
                                </label>
                            </div>
                            <label class="block text-xs">
                                <span class="text-gray-500">Note</span>
                                <textarea v-model="richiesta.notes" rows="2" maxlength="1000" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm" />
                            </label>
                            <p v-if="richiesta.errore" class="text-sm text-red-600">{{ richiesta.errore }}</p>
                            <div class="flex gap-2">
                                <button type="submit" :disabled="richiesta.busy" data-test="riprogrammazione-invia" class="rounded-lg bg-green-700 px-4 py-2 text-sm font-medium text-white hover:bg-green-800 disabled:opacity-50">
                                    {{ richiesta.busy ? 'Invio…' : 'Invia la richiesta' }}
                                </button>
                                <button type="button" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50" @click="richiesta.ordineId = null">Annulla</button>
                            </div>
                        </form>
                    </div>
                </div>

                <p v-if="! inCorso.length" class="rounded-xl border border-gray-200 bg-white p-6 text-center text-sm text-gray-400">
                    Nessun lavoro in programma al momento.
                </p>

                <!-- Esiti di richieste su ordini usciti dall'elenco: l'esito
                     arriva sempre, anche se il lavoro è stato chiuso o riassegnato -->
                <template v-if="(dati.fuori_elenco ?? []).length">
                    <h2 class="pt-2 text-sm font-semibold text-gray-600">Richieste su lavori non più in elenco</h2>
                    <div
                        v-for="r in dati.fuori_elenco"
                        :key="r.id"
                        class="rounded-xl border border-gray-200 bg-white px-4 py-3 text-xs"
                        :class="r.status === 'aperta' ? 'text-blue-900' : 'text-gray-600'"
                        data-test="richiesta-fuori-elenco"
                    >
                        <span class="font-mono text-gray-400">{{ r.ordine_code }}</span>
                        {{ r.ordine_title }} — riprogrammazione ({{ r.motivo }}):
                        <strong>{{ { aperta: 'in attesa di risposta', accettata: 'accettata', rifiutata: 'non accolta' }[r.status] }}</strong>.
                        <span v-if="r.response_note" class="block">Risposta: {{ r.response_note }}</span>
                    </div>
                </template>

                <template v-if="completati.length">
                    <h2 class="pt-2 text-sm font-semibold text-gray-600">Completati di recente</h2>
                    <div v-for="ordine in completati" :key="ordine.id" class="rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm text-gray-600">
                        <span class="font-mono text-xs text-gray-400">{{ ordine.code }}</span>
                        {{ ordine.title }} — {{ [ordine.area, ordine.comune].filter(Boolean).join(', ') }}
                    </div>
                </template>
            </div>
        </div>
    </AppLayout>
</template>
