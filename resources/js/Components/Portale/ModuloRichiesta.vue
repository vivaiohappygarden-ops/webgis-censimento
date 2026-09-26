<script setup>
import { reactive, ref } from 'vue';
import axios from 'axios';
import { BOTTONE, CARTA } from '@/nuovo/stile';

/*
 * Il modulo con cui l'ufficio del Comune segnala un problema: diventa una
 * segnalazione con i suoi tempi (canale "portale del committente"). Le foto
 * vengono ricodificate dal server, che toglie posizione e dati del telefono.
 */
const props = defineProps({
    aree: { type: Array, default: () => [] },
    areaIniziale: { type: String, default: '' },
});
const emit = defineEmits(['inviata', 'annulla']);

const form = reactive({ description: '', area_id: props.areaIniziale, severity: 'medium', busy: false, error: '', sent: false });
const fotoInput = ref(null);

async function invia() {
    form.busy = true;
    form.error = '';
    form.sent = false;
    try {
        const body = new FormData();
        body.append('description', form.description.trim());
        if (form.area_id) body.append('area_id', form.area_id);
        body.append('severity', form.severity);
        const files = [...(fotoInput.value?.files ?? [])];
        if (files.length > 3) {
            form.error = 'Si possono allegare al massimo 3 foto.';
            form.busy = false;

            return;
        }
        files.forEach((f) => body.append('photos[]', f));
        const { data } = await axios.post('/api/v1/portal/requests', body);
        form.description = '';
        form.area_id = '';
        form.severity = 'medium';
        if (fotoInput.value) fotoInput.value.value = '';
        form.sent = true;
        emit('inviata', data.data);
    } catch (err) {
        form.error = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
            ?? err.response?.data?.message ?? `Invio non riuscito (${err.response?.status ?? 'rete'}): riprovare.`;
    } finally {
        form.busy = false;
    }
}
</script>

<template>
    <section :class="CARTA" class="p-4" data-test="portal-request-form">
        <h2 class="text-base font-bold text-gray-900">Nuova richiesta</h2>
        <p class="mt-1 text-[13px] text-gray-500">
            Un ramo pericolante, una pianta secca, un'area da sistemare: descrivetelo qui, con qualche foto se
            possibile. Arriva subito a chi se ne occupa e se ne segue lo stato in questa pagina.
        </p>
        <div class="mt-3 grid gap-3 md:grid-cols-2">
            <label class="block text-sm md:col-span-2">
                <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Descrizione del problema</span>
                <textarea
                    v-model="form.description"
                    rows="3"
                    maxlength="2000"
                    required
                    data-test="req-description"
                    class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-green-700 focus:outline-none focus:ring-2 focus:ring-green-700/30"
                    placeholder="Che cosa avete notato, e dove"
                ></textarea>
            </label>
            <label class="block text-sm">
                <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Area interessata</span>
                <select v-model="form.area_id" data-test="req-area" class="mt-1 min-h-11 w-full rounded-lg border border-gray-300 bg-white px-2.5 text-sm md:min-h-9">
                    <option value="">Non specificata</option>
                    <option v-for="a in aree" :key="a.id" :value="a.id">{{ a.name }}</option>
                </select>
            </label>
            <label class="block text-sm">
                <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Urgenza</span>
                <select v-model="form.severity" class="mt-1 min-h-11 w-full rounded-lg border border-gray-300 bg-white px-2.5 text-sm md:min-h-9">
                    <option value="low">Bassa: quando capita</option>
                    <option value="medium">Normale</option>
                    <option value="high">Alta: c'è un pericolo</option>
                </select>
            </label>
            <label class="block text-sm md:col-span-2">
                <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Fotografie (fino a 3, massimo 8 MB l'una)</span>
                <input ref="fotoInput" type="file" accept="image/jpeg,image/png,image/webp" multiple data-test="req-photos" class="mt-1 block w-full text-sm text-gray-700 file:mr-3 file:min-h-11 file:rounded-lg file:border file:border-gray-300 file:bg-white file:px-3 file:text-sm file:font-semibold file:text-gray-900 md:file:min-h-9">
            </label>
        </div>
        <p v-if="form.error" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800" data-test="req-error">{{ form.error }}</p>
        <p v-if="form.sent" class="mt-3 rounded-lg bg-green-50 px-3 py-2 text-sm text-green-900" data-test="req-sent">Richiesta inviata: è nell'elenco qui sotto con il suo codice.</p>
        <div class="mt-3 flex flex-wrap gap-2">
            <button type="button" :class="BOTTONE" :disabled="form.busy || ! form.description.trim()" data-test="req-send" @click="invia">{{ form.busy ? 'Invio in corso…' : 'Invia la richiesta' }}</button>
            <button type="button" class="min-h-11 px-2 text-sm font-medium text-gray-600 underline-offset-2 hover:underline md:min-h-9" @click="emit('annulla')">Chiudi</button>
        </div>
    </section>
</template>
