<script setup>
import { computed, nextTick, ref } from 'vue';
import { corrisponde } from '@/ricerca';

/*
 * Scelta da un elenco con la ricerca a parole, la stessa di tutto il
 * programma: scrivendo "guidonia com" si trova "Comune di Guidonia
 * Montecelio", in qualunque ordine e anche a pezzi di parola.
 *
 * Sostituisce le tendine semplici sugli elenchi lunghi (committenti, aree,
 * località, tipi di catalogo, ordini): lì scrivendo si saltava solo alla
 * prima voce che inizia con quelle lettere.
 */
const props = defineProps({
    modelValue: { type: [String, null], default: '' },
    voci: { type: Array, default: () => [] },
    // Campo mostrato in elenco e nel campo chiuso
    campoNome: { type: String, default: 'name' },
    // Campi su cui cerca la ricerca a parole
    campiRicerca: { type: Array, default: () => ['name'] },
    // Etichetta della voce "nessun filtro" (es. "Tutti"); null = scelta
    // obbligatoria, senza voce vuota
    tutti: { type: String, default: null },
    segnaposto: { type: String, default: 'Seleziona…' },
    vuoto: { type: String, default: 'Nessuna voce trovata.' },
    // Taglia del campo: deve combaciare con i campi accanto, che cambiano
    // da pagina a pagina
    campoClasse: { type: String, default: 'px-2.5 py-1.5 text-sm' },
    disabilitato: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'cambia', 'apre']);

const aperto = ref(false);
const testo = ref('');
const evidenziato = ref(0);
const campo = ref(null);
const tendina = ref(null);
// Vero solo dopo un movimento con le frecce: l'Invio "cieco" appena entrati
// nel campo non deve sostituire una scelta già fatta
let navigato = false;
// Nei moduli il campo sta dentro una <label>: dopo il clic su una voce il
// browser può ridare il fuoco al campo, che senza questo freno si
// riaprirebbe vuoto scartando la scelta appena fatta
let appenaScelto = 0;

const nomeDi = (voce) => String(voce?.[props.campoNome] ?? '');

const scelto = computed(() => props.voci.find((v) => v.id === props.modelValue) ?? null);

const filtrate = computed(() => props.voci.filter((v) =>
    corrisponde(props.campiRicerca.map((c) => v[c]), testo.value)));

// La voce vuota resta in cima anche mentre si scrive: è la via di ritorno
const elenco = computed(() => [
    ...(props.tutti !== null ? [{ id: '', [props.campoNome]: props.tutti }] : []),
    ...filtrate.value,
]);

/*
 * Da dove parte l'evidenziazione: dal primo risultato vero (mai dalla voce
 * vuota: chi scrive "guidonia" e preme Invio vuole Guidonia, non azzerare
 * il filtro), o dalla voce già scelta quando non si è ancora scritto nulla.
 */
function voceIniziale() {
    if (! filtrate.value.length) return 0;
    if (testo.value.trim() === '') {
        const gia = elenco.value.findIndex((v) => v.id !== '' && v.id === props.modelValue);
        if (gia >= 0) return gia;
    }

    return props.tutti !== null ? 1 : 0;
}

function apri() {
    if (props.disabilitato) return;
    // Un clic per spostare il cursore nel testo non deve azzerare la ricerca
    if (aperto.value) return;
    if (Date.now() - appenaScelto < 300) return;
    testo.value = '';
    navigato = false;
    aperto.value = true;
    evidenziato.value = voceIniziale();
    // Chi carica l'elenco solo quando serve (es. ordini aperti) parte da qui
    emit('apre');
}

function scrivi(evento) {
    if (! aperto.value) aperto.value = true;
    testo.value = evento.target.value;
    navigato = false;
    evidenziato.value = voceIniziale();
}

function scegli(id) {
    emit('update:modelValue', id);
    emit('cambia', id);
    appenaScelto = Date.now();
    aperto.value = false;
    campo.value?.blur();
}

function evidenzia(indice) {
    evidenziato.value = indice;
    navigato = true;
    // La voce evidenziata deve restare in vista anche oltre le prime righe
    nextTick(() => tendina.value?.children[indice]?.scrollIntoView({ block: 'nearest' }));
}

function tasto(evento) {
    if (! aperto.value) return;
    if (evento.key === 'ArrowDown') {
        evento.preventDefault();
        evidenzia(Math.min(evidenziato.value + 1, elenco.value.length - 1));
    } else if (evento.key === 'ArrowUp') {
        evento.preventDefault();
        evidenzia(Math.max(evidenziato.value - 1, 0));
    } else if (evento.key === 'Enter') {
        // L'Invio conferma solo una scelta consapevole: qualcosa di scritto
        // o una voce raggiunta con le frecce. Un Invio appena entrati nel
        // campo (es. per inviare il modulo) non deve sostituire niente
        if (testo.value.trim() === '' && ! navigato) {
            aperto.value = false;

            return;
        }
        evento.preventDefault();
        if (elenco.value[evidenziato.value]) scegli(elenco.value[evidenziato.value].id);
    } else if (evento.key === 'Escape') {
        aperto.value = false;
        campo.value?.blur();
    }
}
</script>

<template>
    <div class="relative">
        <input
            ref="campo"
            type="text"
            :value="aperto ? testo : (scelto ? nomeDi(scelto) : (modelValue ? 'Voce non in elenco' : ''))"
            :placeholder="tutti ?? segnaposto"
            autocomplete="off"
            :disabled="disabilitato"
            class="block w-full rounded-lg border border-gray-300 focus:border-green-600 focus:outline-none disabled:bg-gray-50"
            :class="campoClasse"
            @focus="apri"
            @click="apri"
            @input="scrivi"
            @keydown="tasto"
            @blur="aperto = false"
        >
        <!-- mousedown.prevent sul contenitore: né il clic su una voce né il
             trascinamento della barra di scorrimento devono far perdere il
             fuoco al campo (il blur chiuderebbe la tendina a metà gesto) -->
        <div
            v-if="aperto"
            ref="tendina"
            class="absolute z-20 mt-1 max-h-56 w-full min-w-48 overflow-y-auto rounded-lg border border-gray-200 bg-white py-1 shadow-lg"
            @mousedown.prevent
        >
            <button
                v-for="(voce, i) in elenco"
                :key="voce.id || 'tutti'"
                type="button"
                class="block w-full px-3 py-1.5 text-left text-sm hover:bg-gray-50"
                :class="[
                    i === evidenziato ? 'bg-green-50' : '',
                    voce.id === '' ? 'text-gray-500' : '',
                    voce.id !== '' && voce.id === modelValue ? 'font-semibold text-green-800' : '',
                ]"
                @click="scegli(voce.id)"
            >{{ nomeDi(voce) }}</button>
            <p v-if="! filtrate.length" class="px-3 py-1.5 text-sm text-gray-400">{{ vuoto }}</p>
        </div>
    </div>
</template>
