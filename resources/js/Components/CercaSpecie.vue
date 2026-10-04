<script setup>
// Campo di testo con le proposte del dizionario delle specie: si scrive il nome
// botanico o quello comune (anche regionale: "pino romano") e scegliendo una
// voce chi monta il componente compila genere, famiglia e nome comune.
import { ref } from 'vue';
import axios from 'axios';

const props = defineProps({
    modelValue: { type: [String, null], default: '' },
    // Quale testo resta nel campo dopo la scelta: la specie o il nome comune
    campo: { type: String, default: 'species' },
    segnaposto: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue', 'scelta']);

const risultati = ref([]);
const aperto = ref(false);
let timer = null;
let ultima = 0;

function digita(evento) {
    const valore = evento.target.value;
    emit('update:modelValue', valore);
    clearTimeout(timer);
    if (valore.trim().length < 2) {
        risultati.value = [];
        aperto.value = false;

        return;
    }
    timer = setTimeout(() => cerca(valore.trim()), 220);
}

async function cerca(q) {
    const n = ++ultima;
    try {
        const { data } = await axios.get('/api/v1/specie', { params: { q } });
        if (n !== ultima) return;
        risultati.value = data.data ?? [];
        aperto.value = risultati.value.length > 0;
    } catch {
        risultati.value = [];
        aperto.value = false;
    }
}

function scegli(voce) {
    emit('update:modelValue', props.campo === 'common_name' ? (voce.common_name || voce.species) : voce.species);
    emit('scelta', voce);
    risultati.value = [];
    aperto.value = false;
}

// Invio: con una proposta in vista la sceglie; senza, il modulo si invia come sempre
function invio(evento) {
    if (aperto.value && risultati.value[0]) {
        evento.preventDefault();
        scegli(risultati.value[0]);
    }
}
</script>

<template>
    <div class="relative">
        <input
            :value="modelValue ?? ''"
            type="text"
            autocomplete="off"
            :placeholder="segnaposto"
            class="mt-1 w-full rounded-lg border border-gray-300 bg-white px-2.5 py-2 text-sm"
            :data-test="`campo-${campo}`"
            @input="digita"
            @focus="risultati.length && (aperto = true)"
            @keydown.escape="aperto = false"
            @keydown.enter="invio"
            @blur="setTimeout(() => (aperto = false), 150)"
        >
        <ul v-if="aperto" class="absolute left-0 right-0 z-20 mt-1 max-h-60 overflow-y-auto rounded-lg border border-gray-200 bg-white py-1 text-sm shadow-lg" :data-test="`specie-proposte-${campo}`">
            <li v-for="voce in risultati" :key="voce.id">
                <button type="button" class="block min-h-11 w-full px-3 py-1.5 text-left hover:bg-green-50 md:min-h-9" @mousedown.prevent="scegli(voce)">
                    <span class="font-semibold italic">{{ voce.species }}</span><span v-if="voce.cultivar"> '{{ voce.cultivar }}'</span>
                    <span v-if="voce.common_name" class="text-gray-600"> · {{ voce.common_name }}</span>
                    <span v-if="voce.family" class="text-gray-400"> · {{ voce.family }}</span>
                    <span v-if="voce.propria" class="ml-1 rounded bg-green-50 px-1 text-[11px] text-green-800">dell'organizzazione</span>
                </button>
            </li>
        </ul>
    </div>
</template>
