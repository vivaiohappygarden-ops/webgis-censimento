<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import { avvisoCaricamento } from '@/avvisi';

const page = usePage();
const permissions = computed(() => page.props.auth?.user?.permissions ?? []);
const canManage = computed(() => permissions.value.includes('works.manage'));

const STATE = {
    expired: { label: 'Scaduto', cls: 'bg-red-100 text-red-800' },
    due_soon: { label: 'In scadenza', cls: 'bg-amber-100 text-amber-800' },
    valid: { label: 'Valido', cls: 'bg-green-100 text-green-800' },
    no_expiry: { label: 'Senza scadenza', cls: 'bg-gray-200 text-gray-600' },
};

// Suggerimenti per il campo titolo: i documenti più comuni del settore
const COMMON_TITLES = [
    'Patentino fitosanitario (utilizzatore professionale)',
    'Abilitazione trattore agricolo',
    'Corso primo soccorso',
    'Corso antincendio',
    'Corso lavori in quota',
    'Uso della motosega',
    'Taratura irroratrice',
    'Assicurazione RC professionale',
];

const certificates = ref([]);
const total = ref(0);
const personnel = ref([]);
const loading = ref(false);
const pageError = ref('');
const filters = reactive({ user_id: '', onlyDue: false });

const drawer = reactive({ open: false, mode: 'new', id: null, version: null, busy: false, error: '' });
const form = reactive({});
let formSnapshot = '';
// Persona collegata al certificato aperto: se non è più tra il personale
// (ruolo cambiato), la tendina la mostra comunque invece di apparire vuota
const loadedPerson = ref(null);
const missingLoadedPerson = computed(() => loadedPerson.value
    && form.user_id === loadedPerson.value.id
    && ! personnel.value.some((p) => p.id === loadedPerson.value.id)
    ? loadedPerson.value : null);

function blankForm() {
    return {
        user_id: '', holder_name: '', title: '', number: '',
        issued_by: '', issued_on: '', expires_on: '', notes: '',
    };
}

function firstError(err, fallback) {
    return Object.values(err.response?.data?.errors ?? {})[0]?.[0]
        ?? err.response?.data?.message ?? fallback;
}

function dateOnly(value) {
    return value ? String(value).slice(0, 10) : '';
}

function formatDate(value) {
    if (! value) return '—';
    const [y, m, d] = dateOnly(value).split('-');
    return `${d}/${m}/${y}`;
}

function stateLine(c) {
    if (c.state === 'due_soon') {
        return c.days_left === 0 ? 'scade oggi'
            : `tra ${c.days_left} ${c.days_left === 1 ? 'giorno' : 'giorni'}`;
    }
    if (c.state === 'expired') {
        const days = Math.abs(c.days_left);
        return `da ${days} ${days === 1 ? 'giorno' : 'giorni'}`;
    }
    return '';
}

const visible = computed(() => filters.onlyDue
    ? certificates.value.filter((c) => c.state === 'expired' || c.state === 'due_soon')
    : certificates.value);

let loadSeq = 0;

async function load() {
    const seq = ++loadSeq;
    loading.value = true;
    pageError.value = '';
    try {
        const { data } = await axios.get('/api/v1/certificates', {
            params: { user_id: filters.user_id || undefined },
        });
        if (seq !== loadSeq) return;
        certificates.value = data.data;
        total.value = data.total ?? data.data.length;
    } catch (err) {
        if (seq !== loadSeq) return;
        pageError.value = avvisoCaricamento(err);
    } finally {
        if (seq === loadSeq) loading.value = false;
    }
}

async function loadPersonnel() {
    if (! canManage.value) return;
    try {
        const { data } = await axios.get('/api/v1/personnel');
        personnel.value = data.data;
    } catch {
        personnel.value = [];
    }
}

// Scegliendo una persona dell'app il nome mostrato si allinea da solo
// (resta modificabile: la riga sopravvive anche a un utente rimosso)
function onPersonChange() {
    const person = personnel.value.find((p) => p.id === form.user_id);
    if (person) form.holder_name = person.name;
}

function closeDrawer() {
    if (canManage.value && JSON.stringify(form) !== formSnapshot
        && ! window.confirm('Chiudere senza salvare le modifiche? Andranno perse.')) {
        return;
    }
    drawer.open = false;
}

function openCreator() {
    Object.assign(form, blankForm());
    loadedPerson.value = null;
    drawer.mode = 'new';
    drawer.id = null;
    drawer.version = null;
    drawer.error = '';
    formSnapshot = JSON.stringify(form);
    drawer.open = true;
}

function openDetail(certificate) {
    loadedPerson.value = certificate.user ?? null;
    Object.assign(form, blankForm(), {
        user_id: certificate.user_id ?? '',
        holder_name: certificate.holder_name,
        title: certificate.title,
        number: certificate.number ?? '',
        issued_by: certificate.issued_by ?? '',
        issued_on: dateOnly(certificate.issued_on),
        expires_on: dateOnly(certificate.expires_on),
        notes: certificate.notes ?? '',
    });
    drawer.mode = 'edit';
    drawer.id = certificate.id;
    drawer.version = certificate.version;
    drawer.error = '';
    formSnapshot = JSON.stringify(form);
    drawer.open = true;
}

function payload() {
    return {
        user_id: form.user_id || null,
        holder_name: form.holder_name.trim(),
        title: form.title.trim(),
        number: form.number.trim() || null,
        issued_by: form.issued_by.trim() || null,
        issued_on: form.issued_on || null,
        expires_on: form.expires_on || null,
        notes: form.notes.trim() || null,
    };
}

async function save() {
    drawer.busy = true;
    drawer.error = '';
    try {
        if (drawer.mode === 'new') {
            await axios.post('/api/v1/certificates', payload());
        } else {
            await axios.patch(`/api/v1/certificates/${drawer.id}`, {
                ...payload(), version: drawer.version,
            });
        }
        drawer.open = false;
        await load();
    } catch (err) {
        drawer.error = err.response?.status === 409
            ? 'Un altro utente ha modificato questo certificato: chiudi e riapri il dettaglio.'
            : firstError(err, 'Salvataggio non riuscito');
    } finally {
        drawer.busy = false;
    }
}

async function destroyCertificate() {
    if (! window.confirm('Eliminare questo certificato dallo scadenzario?')) return;
    drawer.busy = true;
    drawer.error = '';
    try {
        await axios.delete(`/api/v1/certificates/${drawer.id}`);
        drawer.open = false;
        await load();
    } catch (err) {
        drawer.error = firstError(err, 'Eliminazione non riuscita');
    } finally {
        drawer.busy = false;
    }
}

const formValid = computed(() => form.holder_name?.trim() && form.title?.trim());

onMounted(() => {
    load();
    loadPersonnel();
});
</script>

<template>
    <Head title="Patentini" />
    <AppLayout>
        <div class="mx-auto max-w-6xl px-4 py-6">
            <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold">Patentini e certificati</h1>
                    <p class="text-sm text-gray-500">Scadenzario di abilitazioni, corsi e documenti: prima ciò che è scaduto o sta per scadere</p>
                </div>
                <button
                    v-if="canManage"
                    class="rounded-lg bg-green-700 px-4 py-2 text-sm font-medium text-white hover:bg-green-800"
                    data-test="cert-new"
                    @click="openCreator"
                >Nuovo certificato</button>
            </div>

            <div class="mb-3 flex flex-wrap items-center gap-3">
                <select v-if="canManage" v-model="filters.user_id" data-test="cert-filter-person" class="rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm" @change="load">
                    <option value="">Tutti gli intestatari</option>
                    <option v-for="p in personnel" :key="p.id" :value="p.id">{{ p.name }}</option>
                </select>
                <label class="flex items-center gap-1.5 text-sm text-gray-600">
                    <input v-model="filters.onlyDue" type="checkbox" data-test="cert-only-due">
                    Solo scaduti o in scadenza (60 giorni)
                </label>
            </div>

            <p v-if="pageError" class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ pageError }}</p>
            <p v-if="total > certificates.length" class="mb-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">
                Elenco parziale: caricati i {{ certificates.length }} certificati con scadenza più vicina
                su {{ total }}.<template v-if="canManage"> Filtra per intestatario per vedere gli altri.</template>
            </p>

            <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wide text-gray-400">
                            <th class="px-4 py-2.5 font-medium">Intestatario</th>
                            <th class="px-4 py-2.5 font-medium">Certificato</th>
                            <th class="px-4 py-2.5 font-medium">Numero</th>
                            <th class="px-4 py-2.5 font-medium">Rilasciato da</th>
                            <th class="px-4 py-2.5 font-medium">Scadenza</th>
                            <th class="px-4 py-2.5 font-medium">Stato</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        <tr
                            v-for="c in visible"
                            :key="c.id"
                            class="cursor-pointer hover:bg-green-50/40"
                            data-test="cert-row"
                            @click="openDetail(c)"
                        >
                            <td class="px-4 py-2 font-medium">{{ c.holder_name }}</td>
                            <td class="px-4 py-2">{{ c.title }}</td>
                            <td class="px-4 py-2 text-gray-600">{{ c.number ?? '—' }}</td>
                            <td class="px-4 py-2 text-gray-600">{{ c.issued_by ?? '—' }}</td>
                            <td class="px-4 py-2">{{ formatDate(c.expires_on) }}</td>
                            <td class="px-4 py-2">
                                <span class="rounded-full px-2.5 py-0.5 text-xs font-medium" :class="STATE[c.state]?.cls" :data-test="`cert-state-${c.state}`">
                                    {{ STATE[c.state]?.label }}
                                </span>
                                <span v-if="stateLine(c)" class="ml-1.5 text-xs text-gray-500">{{ stateLine(c) }}</span>
                            </td>
                        </tr>
                        <tr v-if="! visible.length && ! loading && ! pageError">
                            <td colspan="6" class="px-4 py-8 text-center text-gray-400">
                                {{ filters.onlyDue ? 'Niente in scadenza: tutto in regola.' : 'Nessun certificato registrato.' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Teleport to="body">
                <div v-if="drawer.open" class="fixed inset-0 z-50 flex justify-end bg-black/30" @click.self="closeDrawer">
                    <div class="h-full w-full max-w-lg overflow-y-auto bg-white p-6 shadow-2xl" data-test="cert-drawer">
                        <div class="flex items-start justify-between">
                            <h2 class="font-semibold">{{ drawer.mode === 'new' ? 'Nuovo certificato' : form.title }}</h2>
                            <button class="text-gray-400 hover:text-gray-600" @click="closeDrawer">✕</button>
                        </div>

                        <p v-if="drawer.error" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" data-test="cert-error">{{ drawer.error }}</p>

                        <div class="mt-4 grid grid-cols-2 gap-3">
                            <label v-if="canManage" class="col-span-2 block text-xs">
                                <span class="text-gray-500">Persona dell'app (facoltativa)</span>
                                <select v-model="form.user_id" data-test="cert-person" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm" @change="onPersonChange">
                                    <option value="">— (documento aziendale o persona esterna)</option>
                                    <option v-if="missingLoadedPerson" :value="missingLoadedPerson.id">{{ missingLoadedPerson.name }} (non più tra il personale)</option>
                                    <option v-for="p in personnel" :key="p.id" :value="p.id">{{ p.name }}</option>
                                </select>
                            </label>
                            <label class="col-span-2 block text-xs">
                                <span class="text-gray-500">Intestatario *</span>
                                <input v-model="form.holder_name" maxlength="200" data-test="cert-holder" placeholder="es. Andrea Rossi, oppure: Azienda (irroratrice)" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm" :disabled="! canManage">
                            </label>
                            <label class="col-span-2 block text-xs">
                                <span class="text-gray-500">Certificato / abilitazione *</span>
                                <input v-model="form.title" maxlength="200" list="cert-titles" data-test="cert-title" placeholder="es. Patentino fitosanitario" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm" :disabled="! canManage">
                                <datalist id="cert-titles">
                                    <option v-for="t in COMMON_TITLES" :key="t" :value="t" />
                                </datalist>
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Numero</span>
                                <input v-model="form.number" maxlength="100" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm" :disabled="! canManage">
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Rilasciato da</span>
                                <input v-model="form.issued_by" maxlength="200" placeholder="es. Regione Lombardia" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm" :disabled="! canManage">
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Data di rilascio</span>
                                <input v-model="form.issued_on" type="date" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm" :disabled="! canManage">
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Data di scadenza</span>
                                <input v-model="form.expires_on" type="date" data-test="cert-expiry" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm" :disabled="! canManage">
                            </label>
                            <label class="col-span-2 block text-xs">
                                <span class="text-gray-500">Note (es. rinnovo già prenotato)</span>
                                <textarea v-model="form.notes" rows="3" maxlength="2000" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm" :disabled="! canManage" />
                            </label>
                        </div>

                        <div v-if="canManage" class="mt-5 flex items-center justify-between">
                            <button
                                v-if="drawer.mode === 'edit'"
                                class="text-sm font-medium text-red-600 hover:underline disabled:opacity-50"
                                :disabled="drawer.busy"
                                data-test="cert-delete"
                                @click="destroyCertificate"
                            >Elimina</button>
                            <span v-else />
                            <button
                                class="rounded-lg bg-green-700 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50"
                                :disabled="drawer.busy || ! formValid"
                                data-test="cert-save"
                                @click="save"
                            >{{ drawer.busy ? 'Salvataggio…' : (drawer.mode === 'new' ? 'Registra certificato' : 'Salva le modifiche') }}</button>
                        </div>
                    </div>
                </div>
            </Teleport>
        </div>
    </AppLayout>
</template>
