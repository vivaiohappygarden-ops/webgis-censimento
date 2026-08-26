<script setup>
import { computed, nextTick, ref } from 'vue';
import { corrisponde } from '@/ricerca';

/*
 * Scelta del committente con la ricerca a parole, la stessa di tutto il
 * programma: scrivendo "guidonia com" si trova "Comune di Guidonia
 * Montecelio", e si cerca anche per codice, partita IVA e codice fiscale.
 *
 * Sostituisce le tendine semplici, dove scrivendo si saltava solo alla
 * prima voce che inizia con quelle lettere.
 */
const props = defineProps({
    modelValue: { type: [String, null], default: '' },
    committenti: { type: Array, default: () => [] },
    // Etichetta della voce "nessun filtro" (es. "Tutti"); null = scelta
    // obbligatoria, senza voce vuota
    tutti: { type: String, default: null },
    // Taglia del campo: deve combaciare con i campi accanto, che cambiano
    // da pagina a pagina
    campoClasse: { type: String, default: 'px-2.5 py-1.5 text-sm' },
});

const emit = defineEmits(['update:modelValue', 'cambia']);

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

const scelto = computed(() => props.committenti.find((c) => c.id === props.modelValue) ?? null);

const filtrati = computed(() => props.committenti.filter((c) =>
    corrisponde([c.name, c.code, c.vat_number, c.fiscal_code], testo.value)));

// La voce vuota resta in cima anche mentre si scrive: è la via di ritorno
const voci = computed(() => [
    ...(props.tutti !== null ? [{ id: '', name: props.tutti }] : []),
    ...filtrati.value,
]);

/*
 * Da dove parte l'evidenziazione: dal primo risultato vero (mai dalla voce
 * vuota: chi scrive "guidonia" e preme Invio vuole Guidonia, non azzerare
 * il filtro), o dalla voce già scelta quando non si è ancora scritto nulla.
 */
function voceIniziale() {
    if (! filtrati.value.length) return 0;
    if (testo.value.trim() === '') {
        const gia = voci.value.findIndex((v) => v.id !== '' && v.id === props.modelValue);
        if (gia >= 0) return gia;
    }

    return props.tutti !== null ? 1 : 0;
}

function apri() {
    // Un clic per spostare il cursore nel testo non deve azzerare la ricerca
    if (aperto.value) return;
    if (Date.now() - appenaScelto < 300) return;
    testo.value = '';
    navigato = false;
    aperto.value = true;
    evidenziato.value = voceIniziale();
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
        evidenzia(Math.min(evidenziato.value + 1, voci.value.length - 1));
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
        if (voci.value[evidenziato.value]) scegli(voci.value[evidenziato.value].id);
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
            :value="aperto ? testo : (scelto?.name ?? (modelValue ? 'Committente non in elenco' : ''))"
            :placeholder="tutti ?? 'Seleziona…'"
            autocomplete="off"
            class="block w-full rounded-lg border border-gray-300 focus:border-green-600 focus:outline-none"
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
                v-for="(voce, i) in voci"
                :key="voce.id || 'tutti'"
                type="button"
                class="block w-full px-3 py-1.5 text-left text-sm hover:bg-gray-50"
                :class="[
                    i === evidenziato ? 'bg-green-50' : '',
                    voce.id === '' ? 'text-gray-500' : '',
                    voce.id !== '' && voce.id === modelValue ? 'font-semibold text-green-800' : '',
                ]"
                @click="scegli(voce.id)"
            >{{ voce.name }}</button>
            <p v-if="! filtrati.length" class="px-3 py-1.5 text-sm text-gray-400">Nessun committente trovato.</p>
        </div>
    </div>
</template>
