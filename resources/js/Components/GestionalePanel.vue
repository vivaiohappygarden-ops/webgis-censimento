<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';

const props = defineProps({
    asset: { type: Object, required: true },
});

const page = usePage();
const canSend = computed(() => (page.props.auth?.user?.permissions ?? []).includes('works.manage'));

const STATUS = {
    pending: { label: 'In coda', cls: 'bg-amber-100 text-amber-800' },
    sent: { label: 'Consegnato', cls: 'bg-green-100 text-green-800' },
    failed: { label: 'Non riuscito', cls: 'bg-red-100 text-red-800' },
};
const TYPE_LABELS = { intervento: 'Intervento da fare', preventivo: 'Da preventivare' };
const PRIORITIES = { bassa: 'Bassa', media: 'Media', alta: 'Alta', critica: 'Critica' };

const dispatches = ref([]);
const listError = ref('');
const modal = reactive({ open: false, busy: false, error: '', ok: '' });
const form = reactive({ request_type: 'intervento', title: '', description: '', priority: 'media', photo_ids: [] });

function formatDate(value) {
    if (! value) return '—';
    const d = new Date(value);
    return d.toLocaleDateString('it-IT', { timeZone: 'Europe/Rome' });
}

function firstError(err, fallback) {
    return Object.values(err.response?.data?.errors ?? {})[0]?.[0]
        ?? err.response?.data?.message ?? fallback;
}

// Un invio fermo in coda da più di dieci minuti si può sbloccare a mano
// (servizio delle code spento): il ritento è sicuro, non crea doppioni
function stuck(dispatch) {
    return dispatch.status === 'pending'
        && Date.now() - new Date(dispatch.updated_at ?? dispatch.created_at).getTime() > 10 * 60 * 1000;
}

async function load() {
    listError.value = '';
    // Il messaggio dell'ultimo invio non deve sopravvivere all'aggiornamento
    // dell'elenco: lo stato vero è quello della tabella
    modal.ok = '';
    try {
        const { data } = await axios.get('/api/v1/gestionale/dispatches', {
            params: { asset_id: props.asset.id },
        });
        dispatches.value = data.data;
    } catch {
        listError.value = 'Elenco degli invii non caricato: riprova.';
    }
}

function openModal() {
    Object.assign(form, {
        request_type: 'intervento',
        title: '',
        description: '',
        priority: 'media',
        photo_ids: [],
    });
    modal.error = '';
    modal.ok = '';
    photoLimit.value = '';
    modal.open = true;
}

// A invio partito la finestra non si chiude: l'esito (o l'errore) va letto
function closeModal() {
    if (! modal.busy) modal.open = false;
}

const photoLimit = ref('');

function togglePhoto(id) {
    const index = form.photo_ids.indexOf(id);
    if (index >= 0) {
        form.photo_ids.splice(index, 1);
        photoLimit.value = '';
    } else if (form.photo_ids.length < 5) {
        form.photo_ids.push(id);
        photoLimit.value = '';
    } else {
        // Un clic che non produce nulla va spiegato, o sembra un guasto
        photoLimit.value = 'Massimo 5 fotografie per scheda: togline una per sceglierne un\'altra.';
    }
}

async function send() {
    modal.busy = true;
    modal.error = '';
    try {
        const { data } = await axios.post(`/api/v1/assets/${props.asset.id}/gestionale`, {
            request_type: form.request_type,
            title: form.title.trim(),
            description: form.description.trim() || null,
            priority: form.priority,
            photo_ids: form.photo_ids,
        });
        modal.open = false;
        modal.ok = `Scheda ${data.data.external_ref} in coda di invio al gestionale.`;
        await load();
    } catch (err) {
        modal.error = firstError(err, 'Invio non riuscito');
    } finally {
        modal.busy = false;
    }
}

async function retry(dispatch) {
    listError.value = '';
    try {
        await axios.post(`/api/v1/gestionale/dispatches/${dispatch.id}/retry`);
        await load();
    } catch (err) {
        listError.value = firstError(err, 'Ritento non riuscito');
    }
}

const formValid = computed(() => form.title.trim().length > 0);

onMounted(load);
</script>

<template>
    <section class="rounded-xl border border-gray-200 bg-white p-6" data-test="gest-panel">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-sm font-semibold">Gestionale giardini</h2>
                <p class="text-xs text-gray-500">Segna un intervento da fare o da preventivare: la scheda arriva al gestionale WordPress</p>
            </div>
            <div class="flex items-center gap-2">
                <button class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50" data-test="gest-refresh" @click="load">Aggiorna</button>
                <button
                    v-if="canSend"
                    class="rounded-lg bg-green-700 px-3 py-1.5 text-xs font-medium text-white hover:bg-green-800"
                    data-test="gest-new"
                    @click="openModal"
                >Invia al gestionale</button>
            </div>
        </div>

        <p v-if="modal.ok" class="mt-3 rounded-lg bg-green-100 px-3 py-2 text-sm text-green-900" data-test="gest-ok">{{ modal.ok }}</p>
        <p v-if="listError" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ listError }}</p>

        <table v-if="dispatches.length" class="mt-3 w-full text-sm">
            <tbody class="divide-y divide-gray-50">
                <tr v-for="d in dispatches" :key="d.id" data-test="gest-row">
                    <td class="py-1.5 pr-2 font-medium">{{ d.external_ref }}</td>
                    <td class="py-1.5 pr-2 text-gray-600">{{ TYPE_LABELS[d.request_type] ?? d.request_type }}</td>
                    <td class="max-w-48 truncate py-1.5 pr-2" :title="d.title">{{ d.title }}</td>
                    <td class="py-1.5 pr-2 text-gray-600">{{ formatDate(d.created_at) }}</td>
                    <td class="py-1.5 pr-2">
                        <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="STATUS[d.status]?.cls" :data-test="`gest-state-${d.status}`">
                            {{ STATUS[d.status]?.label ?? d.status }}
                        </span>
                        <span v-if="d.is_duplicate" class="ml-1 text-xs text-gray-500">(già presente)</span>
                        <span v-else-if="stuck(d)" class="ml-1 text-xs text-amber-700">(fermo da un po')</span>
                    </td>
                    <td class="py-1.5 text-right">
                        <a v-if="d.remote_url" :href="d.remote_url" target="_blank" class="text-xs font-medium text-green-800 hover:underline">Apri nel gestionale</a>
                        <button
                            v-else-if="(d.status === 'failed' || stuck(d)) && canSend"
                            class="text-xs font-medium text-green-800 hover:underline"
                            data-test="gest-retry"
                            @click="retry(d)"
                        >Ritenta</button>
                        <span v-else-if="d.status === 'failed'" class="text-xs text-red-600" :title="d.last_error">errore</span>
                    </td>
                </tr>
            </tbody>
        </table>
        <p v-else class="mt-3 text-sm text-gray-400">Nessun invio al gestionale per questo elemento.</p>
        <p v-for="d in dispatches.filter((x) => x.status === 'failed' && x.last_error)" :key="`err-${d.id}`" class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700">
            {{ d.external_ref }}: {{ d.last_error }}
        </p>

        <Teleport to="body">
            <div v-if="modal.open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4" @click.self="closeModal">
                <div class="max-h-full w-full max-w-lg overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl" data-test="gest-modal">
                    <div class="flex items-start justify-between">
                        <h2 class="font-semibold">Invia al gestionale</h2>
                        <button class="text-gray-400 hover:text-gray-600 disabled:opacity-40" :disabled="modal.busy" @click="closeModal">✕</button>
                    </div>

                    <p v-if="modal.error" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" data-test="gest-error">{{ modal.error }}</p>

                    <div class="mt-4 space-y-3">
                        <div class="flex gap-4 text-sm">
                            <label class="flex items-center gap-1.5">
                                <input v-model="form.request_type" type="radio" value="intervento" data-test="gest-tipo-intervento">
                                Intervento da fare
                            </label>
                            <label class="flex items-center gap-1.5">
                                <input v-model="form.request_type" type="radio" value="preventivo" data-test="gest-tipo-preventivo">
                                Da preventivare
                            </label>
                        </div>
                        <label class="block text-xs">
                            <span class="text-gray-500">Titolo del problema *</span>
                            <input v-model="form.title" maxlength="200" data-test="gest-titolo" placeholder="es. Branca instabile" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm">
                        </label>
                        <label class="block text-xs">
                            <span class="text-gray-500">Descrizione</span>
                            <textarea v-model="form.description" rows="3" maxlength="2000" data-test="gest-descrizione" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm" />
                        </label>
                        <label class="block text-xs">
                            <span class="text-gray-500">Priorità</span>
                            <select v-model="form.priority" data-test="gest-priorita" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm">
                                <option v-for="(label, value) in PRIORITIES" :key="value" :value="value">{{ label }}</option>
                            </select>
                        </label>
                        <div v-if="asset.photos?.length" class="text-xs">
                            <span class="text-gray-500">Fotografie da allegare (al massimo 5)</span>
                            <div class="mt-1 grid grid-cols-4 gap-2">
                                <button
                                    v-for="photo in asset.photos"
                                    :key="photo.id"
                                    type="button"
                                    class="relative overflow-hidden rounded-lg border-2"
                                    :class="form.photo_ids.includes(photo.id) ? 'border-green-700' : 'border-transparent'"
                                    :data-test="`gest-foto-${photo.id}`"
                                    @click="togglePhoto(photo.id)"
                                >
                                    <img :src="photo.url" :alt="photo.original_filename" class="h-16 w-full object-cover" loading="lazy">
                                    <span v-if="form.photo_ids.includes(photo.id)" class="absolute inset-x-0 bottom-0 bg-green-700 text-[10px] font-medium text-white">scelta</span>
                                </button>
                            </div>
                            <p v-if="photoLimit" class="mt-1 text-amber-700" data-test="gest-foto-limite">{{ photoLimit }}</p>
                        </div>
                    </div>

                    <button
                        class="mt-4 w-full rounded-lg bg-green-700 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50"
                        :disabled="modal.busy || ! formValid"
                        data-test="gest-send"
                        @click="send"
                    >{{ modal.busy ? 'Invio…' : 'Metti in coda l\'invio' }}</button>
                </div>
            </div>
        </Teleport>
    </section>
</template>
