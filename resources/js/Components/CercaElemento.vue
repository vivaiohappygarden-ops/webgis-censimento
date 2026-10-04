<script setup>
// Cerca un elemento censito a parole (cartellino, specie, area, tipo) e lo
// consegna a chi lo monta. Serve dove un modulo deve indicare un altro
// elemento: i bersagli di una valutazione VTA, gli elementi di un ordine.
import { ref, watch } from 'vue';
import axios from 'axios';

const props = defineProps({
    segnaposto: { type: String, default: 'Cerca per cartellino, specie o area…' },
    // Elementi da non proporre (per esempio l'albero che si sta valutando)
    escludi: { type: Array, default: () => [] },
    limite: { type: Number, default: 8 },
});
const emit = defineEmits(['scelto']);

const testo = ref('');
const risultati = ref([]);
const aperto = ref(false);
const busy = ref(false);
const errore = ref('');
let timer = null;
let ultima = 0;

watch(testo, (v) => {
    clearTimeout(timer);
    if (v.trim().length < 2) {
        risultati.value = [];
        aperto.value = false;

        return;
    }
    timer = setTimeout(cerca, 250);
});

async function cerca() {
    const q = testo.value.trim();
    if (q.length < 2) return;
    const n = ++ultima;
    busy.value = true;
    errore.value = '';
    try {
        const { data } = await axios.get('/api/v1/assets', { params: { q, per_page: props.limite, dettagli: 1, ordina: 'cartellino' } });
        if (n !== ultima) return;
        risultati.value = (data.data ?? []).filter((a) => ! props.escludi.includes(a.id));
        aperto.value = true;
    } catch (err) {
        errore.value = `Ricerca non riuscita (${err.response?.status ?? 'rete assente'}).`;
    } finally {
        if (n === ultima) busy.value = false;
    }
}

const descrizione = (a) => a.specie || a.tree?.species || a.object_type?.name || '';
const area = (a) => a.area?.name || a.area_name || '';
const etichetta = (a) => [a.census_code || 'senza cartellino', descrizione(a)].filter(Boolean).join(' · ');

function scegli(a) {
    emit('scelto', { ...a, etichetta: etichetta(a), descrizione: descrizione(a), area_nome: area(a) });
    testo.value = '';
    risultati.value = [];
    aperto.value = false;
}
</script>

<template>
    <div class="relative">
        <input
            v-model="testo"
            type="search"
            :placeholder="segnaposto"
            autocomplete="off"
            class="min-h-11 w-full rounded-lg border border-gray-300 px-2.5 text-sm md:min-h-9"
            data-test="cerca-elemento"
            @focus="risultati.length && (aperto = true)"
            @keydown.escape="aperto = false"
            @keydown.enter.prevent="risultati[0] && scegli(risultati[0])"
        >
        <ul v-if="aperto && risultati.length" class="absolute left-0 right-0 z-20 mt-1 max-h-64 overflow-y-auto rounded-lg border border-gray-200 bg-white py-1 text-sm shadow-lg" data-test="cerca-elemento-risultati">
            <li v-for="a in risultati" :key="a.id">
                <button type="button" class="block min-h-11 w-full px-3 py-2 text-left hover:bg-green-50 md:min-h-9" @click="scegli(a)">
                    <span class="font-semibold">{{ a.census_code || 'senza cartellino' }}</span>
                    <span v-if="descrizione(a)" class="text-gray-600"> · {{ descrizione(a) }}</span>
                    <span v-if="area(a)" class="text-gray-400"> · {{ area(a) }}</span>
                </button>
            </li>
        </ul>
        <p v-else-if="aperto && ! busy && testo.trim().length >= 2 && ! risultati.length" class="mt-1 text-xs text-gray-500">Nessun elemento trovato.</p>
        <p v-if="errore" class="mt-1 text-xs text-red-700">{{ errore }}</p>
    </div>
</template>
