<script setup>
import { onMounted, ref } from 'vue';
import axios from 'axios';
import { avvisoCaricamento } from '@/avvisi';

// Riquadro "Calendario da abbonamento": mostra l'indirizzo personale del
// feed iCal e permette di copiarlo o rigenerarlo. Il gettone nasce alla
// prima apertura di questo pannello (è la GET a crearlo).
const url = ref('');
const caricamento = ref(false);
const errore = ref('');
const copiato = ref(false);
const rigenerando = ref(false);

async function carica() {
    caricamento.value = true;
    errore.value = '';
    try {
        const { data } = await axios.get('/api/v1/calendario/gettone');
        url.value = data.data.url;
    } catch (err) {
        errore.value = avvisoCaricamento(err);
    } finally {
        caricamento.value = false;
    }
}

async function copia() {
    try {
        await navigator.clipboard.writeText(url.value);
        copiato.value = true;
        setTimeout(() => { copiato.value = false; }, 2000);
    } catch {
        // Senza permesso sugli appunti (o su http): si seleziona il campo,
        // così basta Ctrl+C
        document.querySelector('[data-test="calendario-url"]')?.select();
    }
}

async function rigenera() {
    const conferma = 'Rigenerare l\'indirizzo del calendario?\n\n'
        + 'Il vecchio indirizzo smette di funzionare: chi lo usava dovrà abbonarsi di nuovo con quello nuovo.';
    if (! window.confirm(conferma)) return;

    rigenerando.value = true;
    errore.value = '';
    try {
        const { data } = await axios.post('/api/v1/calendario/gettone/rigenera');
        url.value = data.data.url;
    } catch (err) {
        errore.value = avvisoCaricamento(err);
    } finally {
        rigenerando.value = false;
    }
}

onMounted(carica);
</script>

<template>
    <div class="mt-4 rounded-xl border border-gray-200 bg-white p-4" data-test="calendario-abbonamento">
        <h2 class="text-sm font-semibold">Calendario da abbonamento</h2>
        <p class="mt-1 text-xs text-gray-500">
            Abbonandoti a questo indirizzo da Google Calendar, iPhone o Outlook vedi sul telefono
            l'agenda dei lavori e le scadenze (ricontrolli VTA, patentini), senza aprire il gestionale.
            L'indirizzo è personale: mostra solo ciò che i tuoi permessi ti fanno vedere qui.
        </p>

        <p v-if="errore" class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" data-test="calendario-errore">
            {{ errore }}
            <button class="ml-1 font-medium underline" @click="carica">Riprova</button>
        </p>

        <div v-else class="mt-3 flex flex-wrap items-center gap-2">
            <input
                :value="caricamento ? 'Caricamento…' : url"
                readonly
                class="w-full min-w-0 rounded-lg border border-gray-300 bg-gray-50 px-2.5 py-1.5 font-mono text-xs text-gray-700 sm:flex-1 sm:w-auto"
                data-test="calendario-url"
                @focus="$event.target.select()"
            >
            <button
                class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                :disabled="caricamento || ! url"
                data-test="calendario-copia"
                @click="copia"
            >{{ copiato ? 'Copiato' : 'Copia' }}</button>
            <button
                class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                :disabled="caricamento || rigenerando || ! url"
                data-test="calendario-rigenera"
                @click="rigenera"
            >{{ rigenerando ? 'Rigenerazione…' : 'Rigenera' }}</button>
        </div>

        <p class="mt-2 text-xs text-gray-400">
            Se l'indirizzo finisce in mani sbagliate, "Rigenera" lo sostituisce: il vecchio smette di funzionare.
        </p>
    </div>
</template>
