<script setup>
// Relazione annuale del verde: il PDF per committente e anno da consegnare
// all'ente a fine anno. Qui si scelgono committente e anno; i numeri e il
// documento li prepara il server.
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import ScegliCommittente from '@/Components/ScegliCommittente.vue';
import { fetchPdf } from '@/pdf';

const props = defineProps({
    clients: { type: Array, default: () => [] },
});

const annoCorrente = new Date().getFullYear();
const clientId = ref('');
const anno = ref(annoCorrente);
const primoAnno = ref(annoCorrente);
const inCorso = ref(false);
const errore = ref('');

// Gli anni proposti partono dal primo anno con dati del committente scelto:
// una tendina di anni vuoti non serve a nessuno
const anni = computed(() => {
    const lista = [];
    for (let a = annoCorrente; a >= primoAnno.value; a--) lista.push(a);
    return lista;
});

watch(clientId, async (id) => {
    errore.value = '';
    if (! id) return;
    try {
        const { data } = await axios.get('/api/v1/reports/relazione-annuale/primo-anno', { params: { client_id: id } });
        primoAnno.value = Math.min(data.data.primo_anno, annoCorrente);
    } catch {
        // Senza il primo anno la tendina resta sull'anno corrente: si può
        // comunque stampare, il dato è solo una comodità
        primoAnno.value = annoCorrente;
    }
    if (anno.value < primoAnno.value) anno.value = annoCorrente;
});

async function stampa() {
    if (! clientId.value) {
        errore.value = 'Scegli il committente della relazione.';
        return;
    }
    inCorso.value = true;
    errore.value = '';
    const res = await fetchPdf(`/api/v1/reports/relazione-annuale/pdf?client_id=${clientId.value}&anno=${anno.value}`);
    if (res?.error) errore.value = res.error;
    inCorso.value = false;
}
</script>

<template>
    <div class="mt-4 rounded-xl border border-gray-200 bg-white p-4" data-test="relazione-annuale">
        <h2 class="text-sm font-semibold">Relazione annuale del verde</h2>
        <p class="mt-1 text-xs text-gray-500">
            Il documento da consegnare al committente a fine anno: patrimonio, bilancio arboreo,
            lavori, controlli, segnalazioni, trattamenti e stima della CO2, con firma del tecnico.
            Le sezioni senza dati nell'anno non vengono stampate.
        </p>
        <div class="mt-3 flex flex-wrap items-end gap-2">
            <label class="block w-full text-xs sm:w-auto">
                <span class="text-gray-500">Committente</span>
                <ScegliCommittente
                    v-model="clientId"
                    data-test="relazione-committente"
                    class="mt-1 w-full sm:w-64"
                    campo-classe="px-2 py-2 text-sm"
                    :committenti="props.clients"
                />
            </label>
            <label class="block text-xs">
                <span class="text-gray-500">Anno</span>
                <select
                    v-model.number="anno"
                    data-test="relazione-anno"
                    class="mt-1 block rounded-lg border border-gray-300 px-2.5 py-2 text-sm"
                >
                    <option v-for="a in anni" :key="a" :value="a">{{ a }}</option>
                </select>
            </label>
            <button
                class="rounded-lg bg-green-700 px-3 py-2 text-sm font-medium text-white hover:bg-green-800 disabled:opacity-50"
                :disabled="inCorso"
                data-test="relazione-pdf"
                @click="stampa"
            >{{ inCorso ? 'Preparazione...' : 'Stampa la relazione (PDF)' }}</button>
        </div>
        <p v-if="errore" class="mt-2 text-xs text-red-600" data-test="relazione-errore">{{ errore }}</p>
    </div>
</template>
