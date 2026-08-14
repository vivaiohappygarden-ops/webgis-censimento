<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';

const page = usePage();
const myId = computed(() => page.props.auth?.user?.id);

const ROLE_LABELS = {
    amministratore: 'Amministratore',
    tecnico: 'Tecnico',
    operatore: 'Operatore',
    cliente: 'Cliente (portale)',
};

const users = ref([]);
const clients = ref([]);
const loading = ref(false);
const pageError = ref('');

// La password provvisoria si vede una volta sola: va comunicata subito
const credentials = ref(null);

const creator = reactive({
    open: false, busy: false, error: '',
    form: { name: '', email: '', role: 'operatore', client_id: '' },
});

const editor = reactive({
    open: false, busy: false, error: '', user: null,
    form: { name: '', role: '', client_id: '' },
});

function firstError(err, fallback) {
    return Object.values(err.response?.data?.errors ?? {})[0]?.[0]
        ?? err.response?.data?.message ?? fallback;
}

function formatDate(value) {
    if (! value) return 'mai';
    const d = new Date(value);
    return `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}/${d.getFullYear()}`;
}

// L'anagrafica clienti è paginata (massimo 100 per pagina): si scorrono
// tutte le pagine, o i clienti oltre il centesimo non sarebbero collegabili
async function fetchAllClients() {
    const all = [];
    for (let p = 1; p <= 20; p++) {
        const { data } = await axios.get('/api/v1/clients', { params: { per_page: 100, page: p } });
        all.push(...(data.data ?? []));
        if (! data.next_page_url) break;
    }
    return all;
}

async function load() {
    loading.value = true;
    pageError.value = '';
    try {
        const [u, c] = await Promise.all([
            axios.get('/api/v1/users'),
            fetchAllClients(),
        ]);
        users.value = u.data.data;
        clients.value = c;
    } catch {
        pageError.value = 'Caricamento non riuscito: ricarica la pagina.';
    } finally {
        loading.value = false;
    }
}

// La modale riparte sempre pulita: niente errori o dati della volta prima
function openCreator() {
    creator.error = '';
    creator.form = { name: '', email: '', role: 'operatore', client_id: '' };
    creator.open = true;
}

async function createUser() {
    creator.busy = true;
    creator.error = '';
    try {
        const { data } = await axios.post('/api/v1/users', {
            name: creator.form.name.trim(),
            email: creator.form.email.trim(),
            role: creator.form.role,
            client_id: creator.form.role === 'cliente' ? creator.form.client_id : null,
        });
        creator.open = false;
        creator.form = { name: '', email: '', role: 'operatore', client_id: '' };
        credentials.value = {
            name: data.data.name,
            email: data.data.email,
            password: data.temporary_password,
            action: 'creato',
        };
        await load();
    } catch (err) {
        creator.error = firstError(err, 'Creazione non riuscita');
    } finally {
        creator.busy = false;
    }
}

function openEditor(user) {
    editor.user = user;
    editor.error = '';
    editor.form = {
        name: user.name,
        role: user.role ?? 'operatore',
        client_id: user.client?.id ?? '',
        notify_email: user.notify_email ?? true,
    };
    editor.open = true;
}

async function saveEditor() {
    editor.busy = true;
    editor.error = '';
    try {
        await axios.patch(`/api/v1/users/${editor.user.id}`, {
            name: editor.form.name.trim(),
            role: editor.form.role,
            client_id: editor.form.role === 'cliente' ? editor.form.client_id || null : null,
            notify_email: editor.form.notify_email,
        });
        editor.open = false;
        await load();
    } catch (err) {
        editor.error = firstError(err, 'Salvataggio non riuscito');
    } finally {
        editor.busy = false;
    }
}

async function toggleActive(user) {
    const verb = user.is_active ? 'Disattivare' : 'Riattivare';
    if (! window.confirm(`${verb} l'utente ${user.name}? ${user.is_active ? 'Non potrà più accedere finché non viene riattivato.' : ''}`)) return;
    pageError.value = '';
    try {
        await axios.patch(`/api/v1/users/${user.id}`, { is_active: ! user.is_active });
        await load();
    } catch (err) {
        pageError.value = firstError(err, 'Aggiornamento non riuscito');
    }
}

async function resetPassword(user) {
    if (! window.confirm(`Generare una nuova password provvisoria per ${user.name}? Quella attuale smetterà di funzionare.`)) return;
    pageError.value = '';
    try {
        const { data } = await axios.post(`/api/v1/users/${user.id}/reset-password`);
        credentials.value = {
            name: user.name,
            email: user.email,
            password: data.temporary_password,
            action: 'aggiornato',
        };
    } catch (err) {
        pageError.value = firstError(err, 'Reset non riuscito');
    }
}

// Collegamento al gestionale WordPress (impostazioni di organizzazione)
const gest = reactive({
    form: { endpoint: '', token: '' },
    tokenHint: null,
    configured: false,
    busy: false,
    message: '',
    messageOk: false,
});

async function loadGestionale() {
    try {
        const { data } = await axios.get('/api/v1/gestionale/settings');
        gest.form.endpoint = data.data.endpoint ?? '';
        gest.form.token = '';
        gest.tokenHint = data.data.token_hint;
        gest.configured = data.data.configured;
    } catch {
        // La scheda resta compilabile anche se la lettura fallisce
    }
}

async function saveGestionale() {
    gest.busy = true;
    gest.message = '';
    try {
        await axios.put('/api/v1/gestionale/settings', {
            endpoint: gest.form.endpoint.trim() || null,
            token: gest.form.token.trim() || null,
        });
        await loadGestionale();
        gest.message = gest.configured
            ? 'Collegamento salvato: usa "Prova collegamento" per verificarlo.'
            : 'Impostazioni salvate (collegamento non attivo: servono indirizzo e gettone).';
        gest.messageOk = true;
    } catch (err) {
        gest.message = firstError(err, 'Salvataggio non riuscito');
        gest.messageOk = false;
    } finally {
        gest.busy = false;
    }
}

async function testGestionale() {
    gest.busy = true;
    gest.message = '';
    try {
        const { data } = await axios.post('/api/v1/gestionale/test');
        gest.message = data.data.message;
        gest.messageOk = data.data.ok;
    } catch (err) {
        gest.message = firstError(err, 'Prova non riuscita');
        gest.messageOk = false;
    } finally {
        gest.busy = false;
    }
}

onMounted(() => {
    load();
    loadGestionale();
});
</script>

<template>
    <Head title="Utenti" />

    <AppLayout>
        <div class="p-6">
            <div class="mb-4 flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-semibold">Utenti</h1>
                    <p class="text-sm text-gray-500">Account della tua organizzazione: ruoli, accessi al portale clienti, password</p>
                </div>
                <button
                    class="rounded-lg bg-green-700 px-4 py-2 text-sm font-medium text-white hover:bg-green-800"
                    data-test="usr-new"
                    @click="openCreator"
                >Nuovo utente</button>
            </div>

            <p v-if="pageError" class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" data-test="usr-page-error">{{ pageError }}</p>

            <div v-if="credentials" class="mb-3 rounded-lg bg-green-100 px-4 py-3 text-sm text-green-900" data-test="usr-credentials">
                <p>
                    Account {{ credentials.action }} per <strong>{{ credentials.name }}</strong>.
                    Comunica subito queste credenziali: la password non sarà più visibile.
                </p>
                <p class="mt-1 font-medium">Email: {{ credentials.email }} · Password provvisoria: {{ credentials.password }}</p>
                <button class="mt-2 text-xs font-medium underline" @click="credentials = null">Ho preso nota, nascondi</button>
            </div>

            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wide text-gray-400">
                            <th class="px-4 py-2.5 font-medium">Nome</th>
                            <th class="px-4 py-2.5 font-medium">Email</th>
                            <th class="px-4 py-2.5 font-medium">Ruolo</th>
                            <th class="px-4 py-2.5 font-medium">Cliente collegato</th>
                            <th class="px-4 py-2.5 font-medium">Ultimo accesso</th>
                            <th class="px-4 py-2.5 font-medium">Stato</th>
                            <th class="px-4 py-2.5" />
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        <tr v-for="u in users" :key="u.id" data-test="usr-row" :class="u.is_active ? '' : 'opacity-60'">
                            <td class="px-4 py-2 font-medium">
                                {{ u.name }}
                                <span v-if="u.id === myId" class="ml-1 rounded-full bg-gray-100 px-2 py-0.5 text-[10px] text-gray-500">tu</span>
                            </td>
                            <td class="px-4 py-2 text-gray-600">{{ u.email }}</td>
                            <td class="px-4 py-2">{{ ROLE_LABELS[u.role] ?? u.role ?? '—' }}</td>
                            <td class="px-4 py-2 text-gray-600">{{ u.client?.name ?? '—' }}</td>
                            <td class="px-4 py-2 text-gray-600">{{ formatDate(u.last_login_at) }}</td>
                            <td class="px-4 py-2">
                                <span class="rounded-full px-2.5 py-0.5 text-xs font-medium" :class="u.is_active ? 'bg-green-100 text-green-800' : 'bg-gray-200 text-gray-500'">
                                    {{ u.is_active ? 'Attivo' : 'Disattivato' }}
                                </span>
                            </td>
                            <td class="px-4 py-2 text-right text-xs">
                                <button class="font-medium text-gray-700 hover:underline" data-test="usr-edit" @click="openEditor(u)">Modifica</button>
                                <button class="ml-3 font-medium text-gray-700 hover:underline" data-test="usr-reset" @click="resetPassword(u)">Nuova password</button>
                                <button
                                    v-if="u.id !== myId"
                                    class="ml-3 font-medium hover:underline"
                                    :class="u.is_active ? 'text-red-600' : 'text-green-700'"
                                    data-test="usr-toggle"
                                    @click="toggleActive(u)"
                                >{{ u.is_active ? 'Disattiva' : 'Riattiva' }}</button>
                            </td>
                        </tr>
                        <tr v-if="! users.length && ! loading && ! pageError">
                            <td colspan="7" class="px-4 py-8 text-center text-gray-400">Nessun utente.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Collegamento al gestionale WordPress -->
            <section class="mt-6 rounded-xl border border-gray-200 bg-white p-6" data-test="gest-settings">
                <h2 class="text-sm font-semibold">Collegamento al gestionale giardini (WordPress)</h2>
                <p class="mt-1 text-xs text-gray-500">
                    Dalla scheda di un elemento si può inviare al gestionale un intervento da fare o da
                    preventivare. Indirizzo e gettone li fornisce il gestionale all'attivazione; il gettone
                    resta solo sul server e qui se ne vede solo un accenno.
                </p>
                <div class="mt-3 grid gap-3 md:grid-cols-2">
                    <label class="block text-xs">
                        <span class="text-gray-500">Indirizzo dell'endpoint (https)</span>
                        <input v-model="gest.form.endpoint" data-test="gest-endpoint" placeholder="https://giardini.esempio.it/?rest_route=/yourgarden/v1/sopralluoghi" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm">
                    </label>
                    <label class="block text-xs">
                        <span class="text-gray-500">Gettone segreto {{ gest.tokenHint ? `(salvato: ${gest.tokenHint} - lascia vuoto per conservarlo)` : '' }}</span>
                        <input v-model="gest.form.token" type="password" data-test="gest-token" autocomplete="off" placeholder="ygw_…" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm">
                    </label>
                </div>
                <p v-if="gest.message" class="mt-3 rounded-lg px-3 py-2 text-sm" :class="gest.messageOk ? 'bg-green-100 text-green-900' : 'bg-red-50 text-red-700'" data-test="gest-msg">{{ gest.message }}</p>
                <div class="mt-3 flex items-center gap-2">
                    <button class="rounded-lg bg-green-700 px-4 py-2 text-sm font-medium text-white hover:bg-green-800 disabled:opacity-50" :disabled="gest.busy" data-test="gest-save" @click="saveGestionale">Salva collegamento</button>
                    <button class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50" :disabled="gest.busy || ! gest.configured" data-test="gest-test" @click="testGestionale">Prova collegamento</button>
                </div>
            </section>

            <!-- Creazione -->
            <Teleport to="body">
                <div v-if="creator.open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4" @click.self="creator.open = false">
                    <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
                        <div class="flex items-start justify-between">
                            <h2 class="font-semibold">Nuovo utente</h2>
                            <button class="text-gray-400 hover:text-gray-600" @click="creator.open = false">✕</button>
                        </div>
                        <div class="mt-4 space-y-3">
                            <label class="block text-xs">
                                <span class="text-gray-500">Nome e cognome *</span>
                                <input v-model="creator.form.name" data-test="usr-name" maxlength="150" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm">
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Email (sarà il nome utente) *</span>
                                <input v-model="creator.form.email" data-test="usr-email" type="email" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm">
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Ruolo</span>
                                <select v-model="creator.form.role" data-test="usr-role" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm">
                                    <option v-for="(label, value) in ROLE_LABELS" :key="value" :value="value">{{ label }}</option>
                                </select>
                            </label>
                            <label v-if="creator.form.role === 'cliente'" class="block text-xs">
                                <span class="text-gray-500">Cliente collegato *</span>
                                <select v-model="creator.form.client_id" data-test="usr-client" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm">
                                    <option value="" disabled>Seleziona…</option>
                                    <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option>
                                </select>
                            </label>
                            <p class="text-xs text-gray-500">
                                La password provvisoria viene generata automaticamente e mostrata una sola volta
                                dopo la creazione.
                            </p>
                        </div>
                        <p v-if="creator.error" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" data-test="usr-error">{{ creator.error }}</p>
                        <button
                            class="mt-4 w-full rounded-lg bg-green-700 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50"
                            :disabled="creator.busy || ! creator.form.name.trim() || ! creator.form.email.trim() || (creator.form.role === 'cliente' && ! creator.form.client_id)"
                            data-test="usr-save"
                            @click="createUser"
                        >{{ creator.busy ? 'Creazione…' : 'Crea utente' }}</button>
                    </div>
                </div>
            </Teleport>

            <!-- Modifica -->
            <Teleport to="body">
                <div v-if="editor.open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4" @click.self="editor.open = false">
                    <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
                        <div class="flex items-start justify-between">
                            <h2 class="font-semibold">Modifica utente</h2>
                            <button class="text-gray-400 hover:text-gray-600" @click="editor.open = false">✕</button>
                        </div>
                        <div class="mt-4 space-y-3">
                            <label class="block text-xs">
                                <span class="text-gray-500">Nome e cognome</span>
                                <input v-model="editor.form.name" maxlength="150" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm">
                            </label>
                            <label class="block text-xs">
                                <span class="text-gray-500">Ruolo</span>
                                <select v-model="editor.form.role" data-test="usr-edit-role" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm">
                                    <option v-for="(label, value) in ROLE_LABELS" :key="value" :value="value">{{ label }}</option>
                                </select>
                            </label>
                            <label v-if="editor.form.role === 'cliente'" class="block text-xs">
                                <span class="text-gray-500">Cliente collegato *</span>
                                <select v-model="editor.form.client_id" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm">
                                    <option value="" disabled>Seleziona…</option>
                                    <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option>
                                </select>
                            </label>
                            <label v-if="editor.form.role !== 'cliente'" class="flex items-center gap-1.5 text-xs text-gray-600">
                                <input v-model="editor.form.notify_email" type="checkbox" data-test="usr-notify">
                                Riceve il riepilogo email delle scadenze (solo amministratori e tecnici)
                            </label>
                        </div>
                        <p v-if="editor.error" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" data-test="usr-edit-error">{{ editor.error }}</p>
                        <button
                            class="mt-4 w-full rounded-lg bg-green-700 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50"
                            :disabled="editor.busy || ! editor.form.name.trim() || (editor.form.role === 'cliente' && ! editor.form.client_id)"
                            data-test="usr-edit-save"
                            @click="saveEditor"
                        >{{ editor.busy ? 'Salvataggio…' : 'Salva' }}</button>
                    </div>
                </div>
            </Teleport>
        </div>
    </AppLayout>
</template>
