<script setup>
// Il campo della specie (o del nome comune) nell'app di campo, con le proposte
// calcolate sul telefono dal dizionario scaricato (resources/js/field/specie.js).
// Niente <datalist>: su iPhone, con centinaia di voci, Safari si bloccava a ogni
// lettera. Il testo resta libero: scegliere una proposta e' un gesto esplicito,
// e chi monta il componente compila gli altri campi con la voce scelta.
import { computed, ref } from 'vue';
import { proposteSpecie } from '@/field/specie';

const props = defineProps({
    modelValue: { type: [String, null], default: '' },
    voci: { type: Array, default: () => [] },
    // 'species' o 'common_name': che cosa si sta scrivendo
    campo: { type: String, default: 'species' },
    id: { type: String, default: '' },
    placeholder: { type: String, default: '' },
    // Il data-test del campo; la lista prende "<prova>-proposte"
    prova: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue', 'scelta', 'lascia']);

const aperto = ref(false);
const proposte = computed(() => (aperto.value ? proposteSpecie(props.voci, props.modelValue) : []));

function digita(evento) {
    emit('update:modelValue', evento.target.value);
    aperto.value = true;
}

function scegli(voce) {
    aperto.value = false;
    emit('scelta', voce);
}

// Invio: con una proposta in vista sceglie la prima; senza, il modulo fa come sempre
function invio(evento) {
    if (aperto.value && proposte.value[0]) {
        evento.preventDefault();
        scegli(proposte.value[0]);
    }
}

// Si lascia il campo: la lista si chiude (dopo che un eventuale tocco su una
// proposta e' arrivato) e chi monta il componente completa dal dizionario
function lascia() {
    setTimeout(() => { aperto.value = false; }, 150);
    emit('lascia');
}
</script>

<template>
    <div class="relative">
        <input
            :id="id || null"
            :value="modelValue ?? ''"
            type="text"
            autocomplete="off"
            autocorrect="off"
            spellcheck="false"
            :placeholder="placeholder"
            :data-test="prova || null"
            class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm"
            @input="digita"
            @focus="aperto = true"
            @keydown.escape="aperto = false"
            @keydown.enter="invio"
            @blur="lascia"
        >
        <ul
            v-if="proposte.length"
            class="absolute left-0 right-0 z-20 mt-1 max-h-72 overflow-y-auto rounded-lg border border-gray-300 bg-white py-1 text-sm shadow-lg"
            :data-test="prova ? `${prova}-proposte` : null"
        >
            <li v-for="voce in proposte" :key="voce.id ?? voce.species">
                <button type="button" class="block min-h-11 w-full px-3 py-2 text-left active:bg-green-50" @mousedown.prevent @click="scegli(voce)">
                    <span class="italic">{{ voce.species }}</span><span v-if="voce.cultivar"> '{{ voce.cultivar }}'</span>
                    <span v-if="voce.common_name" class="text-gray-600"> · {{ voce.common_name }}</span>
                    <span v-if="voce.locale" class="text-gray-500"> · già scritta su questo telefono</span>
                </button>
            </li>
        </ul>
    </div>
</template>
