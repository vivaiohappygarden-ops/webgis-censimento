<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import TestataSezione from '@/Components/Nuovo/TestataSezione.vue';
import AvvisoErrore from '@/Components/AvvisoErrore.vue';
import { SCHEDE_IMPOSTAZIONI } from '@/nuovo/sezioni';
import { usaCaricamento } from '@/caricamento';
import { corrisponde } from '@/ricerca';
import { BOTTONE, BOTTONE_SECONDARIO, CARTA, ETICHETTA } from '@/nuovo/stile';

const CAMPO = 'mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm';

// Le zone dell'organizzazione (punto 4 del committente, 04/10/2026): gruppi
// di committenti; un utente assegnato a una zona vede e tocca solo il loro
// territorio. Le gestisce la sede centrale: chi e' di zona qui non entra.
const page = usePage();
const nuova = computed(() => page.props.interfaccia?.modo === 'nuova');
const dati = ref({ zone: [], committenti: [], utenti: [] });
const { caricamento, errore, carica } = usaCaricamento();

async function load() {
    const { data } = await axios.get('/api/v1/zone');
    dati.value = data.data;
    moduli.value = Object.fromEntries(data.data.zone.map((z) => [z.id, moduloDa(z)]));
}
onMounted(() => carica(load));

// Un modulo per zona, modificabile sul posto
const moduli = ref({});
function moduloDa(z) {
    return {
        name: z.name, code: z.code ?? '', notes: z.notes ?? '',
        client_ids: new Set(z.committenti.map((c) => c.id)), user_ids: new Set(z.utenti.map((u) => u.id)),
        cercaCommittente: '', cercaUtente: '', busy: false, errore: '', salvato: false,
    };
}
const committentiFiltrati = (m) => dati.value.committenti.filter((c) => ! m.cercaCommittente || corrisponde([c.name, c.code], m.cercaCommittente));
const utentiFiltrati = (m) => dati.value.utenti.filter((u) => ! m.cercaUtente || corrisponde([u.name, u.email], m.cercaUtente));
function alterna(insieme, id) {
    if (insieme.has(id)) insieme.delete(id); else insieme.add(id);
}
const messaggioErrore = (err, predefinito) => Object.values(err.response?.data?.errors ?? {})[0]?.[0] ?? err.response?.data?.message ?? `${predefinito} (errore ${err.response?.status ?? 'di rete'})`;

async function salva(z) {
    const m = moduli.value[z.id];
    m.busy = true; m.errore = ''; m.salvato = false;
    try {
        await axios.patch(`/api/v1/zone/${z.id}`, {
            name: m.name.trim(), code: m.code.trim() || null, notes: m.notes.trim() || null,
            client_ids: [...m.client_ids], user_ids: [...m.user_ids],
        });
        await load();
        moduli.value[z.id].salvato = true;
    } catch (err) {
        m.errore = messaggioErrore(err, 'Zona non salvata');
    } finally {
        if (moduli.value[z.id]) moduli.value[z.id].busy = false;
    }
}
async function elimina(z) {
    if (! window.confirm(`Eliminare la zona "${z.name}"? I committenti restano, cambia solo chi li vede.`)) return;
    const m = moduli.value[z.id];
    m.errore = '';
    try {
        await axios.delete(`/api/v1/zone/${z.id}`);
        await load();
    } catch (err) {
        m.errore = messaggioErrore(err, 'Zona non eliminata');
    }
}

// Zona nuova
const creazione = reactive({ aperta: false, name: '', code: '', busy: false, errore: '' });
async function crea() {
    creazione.busy = true; creazione.errore = '';
    try {
        await axios.post('/api/v1/zone', { name: creazione.name.trim(), code: creazione.code.trim() || null });
        creazione.name = ''; creazione.code = ''; creazione.aperta = false;
        await load();
    } catch (err) {
        creazione.errore = messaggioErrore(err, 'Zona non creata');
    } finally {
        creazione.busy = false;
    }
}

// Chi non sta in nessuna zona e' centrale: lo si dice, perche' e' la regola che conta
const utentiCentrali = computed(() => {
    const assegnati = new Set(dati.value.zone.flatMap((z) => z.utenti.map((u) => u.id)));
    return dati.value.utenti.filter((u) => ! assegnati.has(u.id));
});
const committentiSenzaZona = computed(() => {
    const assegnati = new Set(dati.value.zone.flatMap((z) => z.committenti.map((c) => c.id)));
    return dati.value.committenti.filter((c) => ! assegnati.has(c.id));
});
</script>

<template>
    <Head title="Zone" />
    <AppLayout>
        <div class="mx-auto max-w-[1640px] p-4 md:p-6 lg:px-7">
            <div v-if="nuova" class="mb-4"><TestataSezione titolo="Impostazioni" attiva="zone" :schede="SCHEDE_IMPOSTAZIONI" /></div>
            <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="flex min-h-9 items-center text-2xl font-bold">Zone</h1>
                    <p class="max-w-3xl text-sm text-gray-600">Una zona è un gruppo di committenti. Chi è assegnato a una zona vede e tocca solo le sedi, le aree, gli elementi, i lavori e le segnalazioni di quei committenti; che cosa può fare lo decide il suo ruolo. Chi non ha zone è della sede centrale e vede tutto.</p>
                </div>
                <button type="button" :class="BOTTONE" data-test="zona-nuova" @click="creazione.aperta = ! creazione.aperta">Nuova zona</button>
            </div>

            <form v-if="creazione.aperta" :class="CARTA" class="mb-4 flex flex-wrap items-end gap-3 p-4" data-test="modulo-zona-nuova" @submit.prevent="crea">
                <label class="block w-full sm:w-72"><span :class="ETICHETTA">Nome della zona</span><input v-model="creazione.name" required maxlength="150" :class="CAMPO" placeholder="es. Emilia-Romagna" data-test="zona-nome"></label>
                <label class="block w-full sm:w-32"><span :class="ETICHETTA">Sigla</span><input v-model="creazione.code" maxlength="30" :class="CAMPO" placeholder="es. ER"></label>
                <button type="submit" :class="BOTTONE" :disabled="creazione.busy || ! creazione.name.trim()" data-test="zona-crea">Crea la zona</button>
                <button type="button" :class="BOTTONE_SECONDARIO" @click="creazione.aperta = false">Annulla</button>
                <p v-if="creazione.errore" class="w-full text-sm text-red-700">{{ creazione.errore }}</p>
            </form>

            <AvvisoErrore v-if="errore" :messaggio="errore" class="mb-4" />
            <p v-else-if="caricamento" class="text-sm text-gray-500">Caricamento delle zone…</p>

            <template v-else>
                <p v-if="! dati.zone.length" :class="CARTA" class="p-4 text-sm text-gray-600" data-test="zone-vuoto">Nessuna zona: tutti gli utenti sono della sede centrale e vedono tutto. Si crea una zona quando serve che chi lavora in un territorio non veda gli altri.</p>

                <div class="space-y-4">
                    <section v-for="z in dati.zone" :key="z.id" :class="CARTA" class="p-4" data-test="zona">
                        <div v-if="moduli[z.id]" class="grid gap-4 lg:grid-cols-[1fr_1fr_1fr]">
                            <div class="space-y-3">
                                <label class="block"><span :class="ETICHETTA">Nome</span><input v-model="moduli[z.id].name" required maxlength="150" :class="CAMPO" data-test="zona-modifica-nome"></label>
                                <label class="block"><span :class="ETICHETTA">Sigla</span><input v-model="moduli[z.id].code" maxlength="30" :class="CAMPO"></label>
                                <label class="block"><span :class="ETICHETTA">Note</span><textarea v-model="moduli[z.id].notes" rows="3" maxlength="2000" :class="CAMPO" /></label>
                                <div class="flex flex-wrap items-center gap-2">
                                    <button type="button" :class="BOTTONE" :disabled="moduli[z.id].busy || ! moduli[z.id].name.trim()" data-test="zona-salva" @click="salva(z)">Salva la zona</button>
                                    <button type="button" :class="BOTTONE_SECONDARIO" :disabled="moduli[z.id].busy" data-test="zona-elimina" @click="elimina(z)">Elimina</button>
                                    <span v-if="moduli[z.id].salvato" class="text-sm text-green-800" data-test="zona-salvata">Salvata.</span>
                                </div>
                                <p v-if="moduli[z.id].errore" class="text-sm text-red-700" data-test="zona-errore">{{ moduli[z.id].errore }}</p>
                            </div>
                            <div>
                                <div class="flex items-center justify-between"><span :class="ETICHETTA">Committenti della zona ({{ moduli[z.id].client_ids.size }})</span></div>
                                <input v-model="moduli[z.id].cercaCommittente" :class="CAMPO" class="mt-1" placeholder="Cerca un committente…" aria-label="Cerca un committente">
                                <ul class="mt-2 max-h-72 divide-y divide-gray-100 overflow-y-auto rounded-lg border border-gray-200">
                                    <li v-for="c in committentiFiltrati(moduli[z.id])" :key="c.id">
                                        <label class="flex min-h-11 cursor-pointer items-center gap-2 px-3 text-sm hover:bg-gray-50 md:min-h-9">
                                            <input type="checkbox" class="rounded border-gray-300" :checked="moduli[z.id].client_ids.has(c.id)" :data-test="`zona-committente-${c.id}`" @change="alterna(moduli[z.id].client_ids, c.id)">
                                            <span class="truncate">{{ c.name }}</span><span v-if="c.code" class="text-xs text-gray-500">{{ c.code }}</span>
                                        </label>
                                    </li>
                                    <li v-if="! committentiFiltrati(moduli[z.id]).length" class="px-3 py-2 text-sm text-gray-500">Nessun committente con queste parole.</li>
                                </ul>
                            </div>
                            <div>
                                <span :class="ETICHETTA">Utenti della zona ({{ moduli[z.id].user_ids.size }})</span>
                                <input v-model="moduli[z.id].cercaUtente" :class="CAMPO" class="mt-1" placeholder="Cerca un utente…" aria-label="Cerca un utente">
                                <ul class="mt-2 max-h-72 divide-y divide-gray-100 overflow-y-auto rounded-lg border border-gray-200">
                                    <li v-for="u in utentiFiltrati(moduli[z.id])" :key="u.id">
                                        <label class="flex min-h-11 cursor-pointer items-center gap-2 px-3 text-sm hover:bg-gray-50 md:min-h-9">
                                            <input type="checkbox" class="rounded border-gray-300" :checked="moduli[z.id].user_ids.has(u.id)" :data-test="`zona-utente-${u.id}`" @change="alterna(moduli[z.id].user_ids, u.id)">
                                            <span class="truncate">{{ u.name }}</span><span class="truncate text-xs text-gray-500">{{ u.ruolo ?? '' }}</span>
                                        </label>
                                    </li>
                                    <li v-if="! utentiFiltrati(moduli[z.id]).length" class="px-3 py-2 text-sm text-gray-500">Nessun utente con queste parole.</li>
                                </ul>
                                <p class="mt-1 text-xs text-gray-500">Chi gestisce le zone deve restare della sede centrale: assegnato a una zona, non aprirebbe più questa pagina.</p>
                            </div>
                        </div>
                    </section>
                </div>

                <section v-if="dati.zone.length" :class="CARTA" class="mt-4 p-4 text-sm" data-test="zone-riepilogo">
                    <h2 class="font-semibold text-gray-900">Sede centrale</h2>
                    <p class="mt-1 text-gray-700">Vedono tutto, senza zone: {{ utentiCentrali.length ? utentiCentrali.map((u) => u.name).join(', ') : 'nessuno' }}.</p>
                    <p v-if="committentiSenzaZona.length" class="mt-1 text-gray-700">Committenti fuori da ogni zona (li vede solo la sede centrale): {{ committentiSenzaZona.map((c) => c.name).join(', ') }}.</p>
                </section>
            </template>
        </div>
    </AppLayout>
</template>
