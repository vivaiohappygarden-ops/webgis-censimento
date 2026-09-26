<script setup>
import { computed, reactive, ref, watch } from 'vue';
import axios from 'axios';
import ScegliCommittente from '@/Components/ScegliCommittente.vue';
import ScegliVoce from '@/Components/ScegliVoce.vue';
import { BOTTONE, BOTTONE_SECONDARIO, plurale } from '@/nuovo/stile';

/*
 * Il modulo del nuovo ordine di lavoro: gli stessi campi e la stessa
 * chiamata della pagina precedente (POST work-orders, l'ordine nasce in
 * bozza). Arrivando da Patrimonio con degli elementi scelti, si agganciano
 * all'ordine appena creato, uno per uno.
 */
const props = defineProps({
    elementi: { type: Array, default: () => [] },
    teams: { type: Array, default: () => [] },
    personnel: { type: Array, default: () => [] },
    workTypes: { type: Array, default: () => [] },
    clients: { type: Array, default: () => [] },
    areas: { type: Array, default: () => [] },
    priceLists: { type: Array, default: () => [] },
});
const emit = defineEmits(['close', 'created']);

const PRIORITA = { low: 'Bassa', normal: 'Normale', high: 'Alta', urgent: 'Urgente' };

const form = reactive({
    title: '', description: '', client_id: '', area_id: '', work_type_id: '',
    priority: 'normal', planned_start: '', planned_end: '', team_id: '', assigned_to: '', price_list_id: '',
});
const busy = ref(false);
const errore = ref('');

// Un'impresa esterna appartiene a un committente: nella tendina compare solo
// scegliendo quel committente (le squadre interne per tutti)
const squadreAmmesse = computed(() => props.teams.filter((t) => ! t.is_external || (t.client_id && t.client_id === form.client_id)));
const etichettaSquadra = (t) => (t.is_external ? `${t.name} — impresa${t.client ? ` di ${t.client.name}` : ''}` : t.name);
watch(() => form.client_id, () => {
    if (form.team_id && ! squadreAmmesse.value.some((t) => t.id === form.team_id)) form.team_id = '';
});

async function crea() {
    busy.value = true;
    errore.value = '';
    try {
        const payload = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '' && v !== null));
        const { data } = await axios.post('/api/v1/work-orders', payload);
        const agganci = await Promise.allSettled(props.elementi.map((assetId) =>
            axios.post(`/api/v1/work-orders/${data.data.id}/assets`, { asset_id: assetId })));
        const nonAgganciati = agganci.filter((a) => a.status === 'rejected').length;
        emit('created', { ordine: data.data, nonAgganciati });
    } catch (err) {
        errore.value = Object.values(err.response?.data?.errors ?? {})[0]?.[0] ?? err.response?.data?.message ?? 'Errore nella creazione';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Teleport to="body">
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-black/30 p-0 sm:items-center sm:p-4" @click.self="emit('close')">
            <form class="max-h-full w-full max-w-2xl overflow-y-auto rounded-t-2xl bg-white p-4 shadow-2xl sm:rounded-2xl sm:p-6" data-test="nuovo-ordine" @submit.prevent="crea">
                <div class="flex items-start justify-between gap-3">
                    <h2 class="text-lg font-bold text-gray-900">Nuovo ordine di lavoro</h2>
                    <button type="button" class="min-h-11 min-w-11 text-gray-500 hover:text-gray-800" aria-label="Chiudi" @click="emit('close')">✕</button>
                </div>
                <p v-if="props.elementi.length" class="mt-1 rounded-lg bg-green-50 px-3 py-2 text-sm text-green-900" data-test="wo-elementi-da-collegare">
                    {{ props.elementi.length }} {{ plurale(props.elementi.length, 'elemento scelto verrà collegato', 'elementi scelti verranno collegati') }} all'ordine appena creato.
                </p>

                <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2">
                    <label class="col-span-full block text-xs">
                        <span class="text-gray-600">Titolo *</span>
                        <input v-model="form.title" data-test="wo-title" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm" placeholder="es. Potatura filare via Roma" required>
                    </label>
                    <label class="block text-xs">
                        <span class="text-gray-600">Lavorazione</span>
                        <select v-model="form.work_type_id" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-2 text-sm">
                            <option value="">—</option>
                            <option v-for="w in props.workTypes" :key="w.id" :value="w.id">{{ w.name }} ({{ w.unit }})</option>
                        </select>
                    </label>
                    <label class="block text-xs">
                        <span class="text-gray-600">Priorità</span>
                        <select v-model="form.priority" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-2 text-sm">
                            <option v-for="(label, value) in PRIORITA" :key="value" :value="value">{{ label }}</option>
                        </select>
                    </label>
                    <label class="block text-xs">
                        <span class="text-gray-600">Committente</span>
                        <ScegliCommittente v-model="form.client_id" class="mt-1 w-full" campo-classe="px-2 py-2 text-sm" :committenti="props.clients" tutti="—" />
                    </label>
                    <label class="block text-xs">
                        <span class="text-gray-600">Area</span>
                        <ScegliVoce v-model="form.area_id" class="mt-1 w-full" campo-classe="px-2 py-2 text-sm" :voci="props.areas" :campi-ricerca="['name', 'code']" tutti="—" vuoto="Nessuna area trovata." />
                    </label>
                    <label class="block text-xs">
                        <span class="text-gray-600">Inizio previsto</span>
                        <input v-model="form.planned_start" type="date" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm">
                    </label>
                    <label class="block text-xs">
                        <span class="text-gray-600">Fine prevista</span>
                        <input v-model="form.planned_end" type="date" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm">
                    </label>
                    <label class="block text-xs">
                        <span class="text-gray-600">Squadra</span>
                        <select v-model="form.team_id" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-2 text-sm">
                            <option value="">—</option>
                            <option v-for="t in squadreAmmesse" :key="t.id" :value="t.id">{{ etichettaSquadra(t) }}</option>
                        </select>
                    </label>
                    <label class="block text-xs">
                        <span class="text-gray-600">Responsabile</span>
                        <select v-model="form.assigned_to" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-2 text-sm">
                            <option value="">—</option>
                            <option v-for="p in props.personnel" :key="p.id" :value="p.id">{{ p.name }}</option>
                        </select>
                    </label>
                    <label class="block text-xs">
                        <span class="text-gray-600">Listino prezzi</span>
                        <select v-model="form.price_list_id" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-2 text-sm">
                            <option value="">—</option>
                            <option v-for="l in props.priceLists" :key="l.id" :value="l.id">{{ l.code }} — {{ l.name }}</option>
                        </select>
                    </label>
                    <label class="col-span-full block text-xs">
                        <span class="text-gray-600">Descrizione e note per la squadra</span>
                        <textarea v-model="form.description" rows="2" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm" />
                    </label>
                </div>

                <p v-if="errore" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" data-test="wo-errore">{{ errore }}</p>

                <div class="mt-4 flex flex-wrap gap-2">
                    <button type="submit" :class="BOTTONE" :disabled="busy || ! form.title.trim()" data-test="wo-save">{{ busy ? 'Creazione…' : 'Crea l\'ordine (in bozza)' }}</button>
                    <button type="button" :class="BOTTONE_SECONDARIO" @click="emit('close')">Annulla</button>
                </div>
            </form>
        </div>
    </Teleport>
</template>
