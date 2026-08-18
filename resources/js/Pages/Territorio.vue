<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';

const page = usePage();
const canManage = computed(() => (page.props.auth?.user?.permissions ?? []).includes('clients.manage'));

const clients = ref([]);
const sites = ref([]);
const localities = ref([]);
const selectedClient = ref(null);
const selectedSite = ref(null);
const error = ref('');

const forms = reactive({
    client: { open: false, name: '', client_type: 'public', code: '' },
    site: { open: false, name: '', municipality: '', istat_code: '', province: '' },
    locality: { open: false, name: '', code: '' },
});

const firstError = (err) =>
    Object.values(err.response?.data?.errors ?? {})[0]?.[0] ?? err.response?.data?.message ?? 'Errore imprevisto';

async function loadClients() {
    const { data } = await axios.get('/api/v1/clients', { params: { per_page: 100 } });
    clients.value = data.data;
}

async function selectClient(client) {
    selectedClient.value = client;
    selectedSite.value = null;
    localities.value = [];
    caricaPortale(client);
    const { data } = await axios.get('/api/v1/sites', { params: { client_id: client.id, per_page: 100 } });
    sites.value = data.data;
}

// --- Portale pubblico del committente -------------------------------------

const portale = reactive({
    public_enabled: false,
    public_slug: '',
    label_prefix: '',
    display_name: '',
    color: '#14532d',
    contact_email: '',
    welcome_text: '',
    footer_text: '',
    salvato: '',
});
const stemmaInput = ref(null);

function caricaPortale(client) {
    const profilo = client.public_profile ?? {};
    Object.assign(portale, {
        public_enabled: !! client.public_enabled,
        public_slug: client.public_slug ?? '',
        label_prefix: client.label_prefix ?? '',
        display_name: profilo.display_name ?? '',
        color: profilo.color ?? '#14532d',
        contact_email: profilo.contact_email ?? '',
        welcome_text: profilo.welcome_text ?? '',
        footer_text: profilo.footer_text ?? '',
        salvato: '',
    });
}

async function salvaPortale() {
    error.value = '';
    portale.salvato = '';
    try {
        const { data } = await axios.patch(`/api/v1/clients/${selectedClient.value.id}`, {
            public_enabled: portale.public_enabled,
            public_slug: portale.public_slug || null,
            label_prefix: portale.label_prefix || null,
            public_profile: {
                display_name: portale.display_name || null,
                color: portale.color || null,
                contact_email: portale.contact_email || null,
                welcome_text: portale.welcome_text || null,
                footer_text: portale.footer_text || null,
            },
        });
        aggiornaClienteInElenco(data.data);
        portale.salvato = 'Impostazioni del portale salvate.';
    } catch (err) {
        error.value = firstError(err);
    }
}

function aggiornaClienteInElenco(aggiornato) {
    const indice = clients.value.findIndex((c) => c.id === aggiornato.id);
    if (indice >= 0) clients.value[indice] = { ...clients.value[indice], ...aggiornato };
    selectedClient.value = { ...selectedClient.value, ...aggiornato };
    caricaPortale(selectedClient.value);
}

async function caricaStemma(evento) {
    const file = evento.target.files?.[0];
    if (! file) return;
    error.value = '';
    const modulo = new FormData();
    modulo.append('stemma', file);
    try {
        const { data } = await axios.post(`/api/v1/clients/${selectedClient.value.id}/stemma`, modulo);
        aggiornaClienteInElenco(data.data);
        portale.salvato = 'Stemma caricato.';
    } catch (err) {
        error.value = firstError(err);
    } finally {
        if (stemmaInput.value) stemmaInput.value.value = '';
    }
}

async function rimuoviStemma() {
    error.value = '';
    try {
        const { data } = await axios.delete(`/api/v1/clients/${selectedClient.value.id}/stemma`);
        aggiornaClienteInElenco(data.data);
        portale.salvato = 'Stemma rimosso.';
    } catch (err) {
        error.value = firstError(err);
    }
}

async function selectSite(site) {
    selectedSite.value = site;
    const { data } = await axios.get('/api/v1/localities', { params: { site_id: site.id, per_page: 200 } });
    localities.value = data.data;
}

async function saveClient() {
    error.value = '';
    try {
        await axios.post('/api/v1/clients', {
            name: forms.client.name,
            client_type: forms.client.client_type,
            code: forms.client.code || null,
        });
        forms.client = { open: false, name: '', client_type: 'public', code: '' };
        await loadClients();
    } catch (err) {
        error.value = firstError(err);
    }
}

async function saveSite() {
    error.value = '';
    try {
        await axios.post('/api/v1/sites', {
            client_id: selectedClient.value.id,
            name: forms.site.name,
            municipality: forms.site.municipality || null,
            istat_code: forms.site.istat_code || null,
            province: forms.site.province || null,
        });
        forms.site = { open: false, name: '', municipality: '', istat_code: '', province: '' };
        await selectClient(selectedClient.value);
    } catch (err) {
        error.value = firstError(err);
    }
}

async function saveLocality() {
    error.value = '';
    try {
        await axios.post('/api/v1/localities', {
            site_id: selectedSite.value.id,
            name: forms.locality.name,
            code: forms.locality.code || null,
        });
        forms.locality = { open: false, name: '', code: '' };
        await selectSite(selectedSite.value);
    } catch (err) {
        error.value = firstError(err);
    }
}

async function removeItem(kind, id, refresh) {
    error.value = '';
    if (! window.confirm('Confermi l\'eliminazione?')) return;
    try {
        await axios.delete(`/api/v1/${kind}/${id}`);
        await refresh();
    } catch (err) {
        error.value = firstError(err);
    }
}

const CLIENT_TYPES = { public: 'Pubblico (PA)', private: 'Privato', condo: 'Condominio', other: 'Altro' };

onMounted(loadClients);
</script>

<template>
    <Head title="Territorio" />

    <AppLayout>
        <div class="p-6">
            <h1 class="text-xl font-semibold">Territorio</h1>
            <p class="mb-4 text-sm text-gray-500">Clienti → Sedi → Località. Le aree si disegnano dalla Mappa.</p>

            <p v-if="error" class="mb-3 rounded-lg bg-red-50 px-4 py-2 text-sm text-red-700">{{ error }}</p>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <!-- Clienti -->
                <section class="rounded-xl border border-gray-200 bg-white">
                    <header class="flex items-center justify-between border-b border-gray-100 px-4 py-3">
                        <h2 class="text-sm font-semibold">Clienti ({{ clients.length }})</h2>
                        <button
                            v-if="canManage"
                            class="rounded-lg bg-green-700 px-2.5 py-1 text-xs font-medium text-white hover:bg-green-800"
                            @click="forms.client.open = ! forms.client.open"
                        >+ Nuovo</button>
                    </header>

                    <form v-if="forms.client.open" class="space-y-2 border-b border-gray-100 bg-green-50/40 p-3" @submit.prevent="saveClient">
                        <input v-model="forms.client.name" required placeholder="Ragione sociale *" class="w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
                        <div class="flex gap-2">
                            <select v-model="forms.client.client_type" class="flex-1 rounded-lg border border-gray-300 px-2 py-1.5 text-sm">
                                <option v-for="(label, value) in CLIENT_TYPES" :key="value" :value="value">{{ label }}</option>
                            </select>
                            <input v-model="forms.client.code" placeholder="Codice" class="w-24 rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
                        </div>
                        <button type="submit" class="w-full rounded-lg bg-green-700 py-1.5 text-sm font-medium text-white hover:bg-green-800">Salva cliente</button>
                    </form>

                    <ul class="divide-y divide-gray-50">
                        <li v-for="c in clients" :key="c.id">
                            <button
                                class="flex w-full items-center justify-between px-4 py-2.5 text-left text-sm hover:bg-green-50/50"
                                :class="selectedClient?.id === c.id ? 'bg-green-50 font-medium' : ''"
                                @click="selectClient(c)"
                            >
                                <span>
                                    {{ c.name }}
                                    <span class="ml-1 rounded bg-gray-100 px-1.5 text-[10px] uppercase text-gray-500">{{ CLIENT_TYPES[c.client_type] }}</span>
                                </span>
                                <span class="text-xs text-gray-400">{{ c.sites_count }} sedi</span>
                            </button>
                        </li>
                        <li v-if="! clients.length" class="px-4 py-6 text-center text-sm text-gray-400">Nessun cliente.</li>
                    </ul>
                </section>

                <!-- Sedi -->
                <section class="rounded-xl border border-gray-200 bg-white">
                    <header class="flex items-center justify-between border-b border-gray-100 px-4 py-3">
                        <h2 class="text-sm font-semibold">
                            Sedi <span v-if="selectedClient" class="text-gray-400">di {{ selectedClient.name }}</span>
                        </h2>
                        <button
                            v-if="canManage && selectedClient"
                            class="rounded-lg bg-green-700 px-2.5 py-1 text-xs font-medium text-white hover:bg-green-800"
                            @click="forms.site.open = ! forms.site.open"
                        >+ Nuova</button>
                    </header>

                    <form v-if="forms.site.open && selectedClient" class="space-y-2 border-b border-gray-100 bg-green-50/40 p-3" @submit.prevent="saveSite">
                        <input v-model="forms.site.name" required placeholder="Nome sede *" class="w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
                        <div class="flex gap-2">
                            <input v-model="forms.site.municipality" placeholder="Comune" class="flex-1 rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
                            <input v-model="forms.site.province" maxlength="2" placeholder="PR" class="w-14 rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm uppercase">
                            <input v-model="forms.site.istat_code" maxlength="6" placeholder="ISTAT" class="w-20 rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
                        </div>
                        <button type="submit" class="w-full rounded-lg bg-green-700 py-1.5 text-sm font-medium text-white hover:bg-green-800">Salva sede</button>
                    </form>

                    <ul class="divide-y divide-gray-50">
                        <li v-for="s in sites" :key="s.id">
                            <button
                                class="flex w-full items-center justify-between px-4 py-2.5 text-left text-sm hover:bg-green-50/50"
                                :class="selectedSite?.id === s.id ? 'bg-green-50 font-medium' : ''"
                                @click="selectSite(s)"
                            >
                                <span>{{ s.name }} <span v-if="s.municipality" class="text-gray-400">— {{ s.municipality }}</span></span>
                                <span class="text-xs text-gray-400">{{ s.localities_count }} località</span>
                            </button>
                        </li>
                        <li v-if="selectedClient && ! sites.length" class="px-4 py-6 text-center text-sm text-gray-400">Nessuna sede.</li>
                        <li v-if="! selectedClient" class="px-4 py-6 text-center text-sm text-gray-400">Seleziona un cliente.</li>
                    </ul>
                </section>

                <!-- Località -->
                <section class="rounded-xl border border-gray-200 bg-white">
                    <header class="flex items-center justify-between border-b border-gray-100 px-4 py-3">
                        <h2 class="text-sm font-semibold">
                            Località <span v-if="selectedSite" class="text-gray-400">di {{ selectedSite.name }}</span>
                        </h2>
                        <button
                            v-if="canManage && selectedSite"
                            class="rounded-lg bg-green-700 px-2.5 py-1 text-xs font-medium text-white hover:bg-green-800"
                            @click="forms.locality.open = ! forms.locality.open"
                        >+ Nuova</button>
                    </header>

                    <form v-if="forms.locality.open && selectedSite" class="space-y-2 border-b border-gray-100 bg-green-50/40 p-3" @submit.prevent="saveLocality">
                        <div class="flex gap-2">
                            <input v-model="forms.locality.name" required placeholder="Nome località *" class="flex-1 rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
                            <input v-model="forms.locality.code" placeholder="Codice" class="w-20 rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
                        </div>
                        <button type="submit" class="w-full rounded-lg bg-green-700 py-1.5 text-sm font-medium text-white hover:bg-green-800">Salva località</button>
                    </form>

                    <ul class="divide-y divide-gray-50">
                        <li
                            v-for="l in localities"
                            :key="l.id"
                            class="flex items-center justify-between px-4 py-2.5 text-sm"
                        >
                            <span>{{ l.name }} <span v-if="l.code" class="text-gray-400">({{ l.code }})</span></span>
                            <span class="flex items-center gap-3">
                                <span class="text-xs text-gray-400">{{ l.areas_count }} aree</span>
                                <button
                                    v-if="canManage && l.areas_count === 0"
                                    class="text-xs text-red-500 hover:underline"
                                    @click="removeItem('localities', l.id, () => selectSite(selectedSite))"
                                >elimina</button>
                            </span>
                        </li>
                        <li v-if="selectedSite && ! localities.length" class="px-4 py-6 text-center text-sm text-gray-400">Nessuna località.</li>
                        <li v-if="! selectedSite" class="px-4 py-6 text-center text-sm text-gray-400">Seleziona una sede.</li>
                    </ul>
                </section>
            </div>

            <!-- Portale pubblico del committente -->
            <section v-if="selectedClient && canManage" class="mt-4 rounded-xl border border-gray-200 bg-white">
                <header class="border-b border-gray-100 px-4 py-3">
                    <h2 class="text-sm font-semibold">Portale pubblico di {{ selectedClient.name }}</h2>
                    <p class="mt-0.5 text-xs text-gray-500">
                        Sito consultabile dai cittadini, con lo stemma e i colori dell'ente. Si accende committente per committente.
                    </p>
                </header>

                <form class="space-y-4 p-4" @submit.prevent="salvaPortale">
                    <label class="flex items-start gap-2 text-sm">
                        <input v-model="portale.public_enabled" type="checkbox" class="mt-0.5 rounded border-gray-300">
                        <span>
                            Portale pubblico attivo
                            <span class="block text-xs text-gray-500">Da spento, l'indirizzo risponde "pagina non trovata".</span>
                        </span>
                    </label>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <label class="block text-sm">
                            <span class="mb-1 block text-xs font-medium text-gray-600">Indirizzo pubblico</span>
                            <input
                                v-model="portale.public_slug"
                                placeholder="mentana"
                                class="w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm lowercase"
                            >
                            <span class="mt-1 block break-all text-xs text-gray-500">
                                {{ selectedClient.portal_url || 'Si genera dal nome quando accendi il portale.' }}
                            </span>
                        </label>

                        <label class="block text-sm">
                            <span class="mb-1 block text-xs font-medium text-gray-600">Prefisso etichette</span>
                            <input
                                v-model="portale.label_prefix"
                                maxlength="6"
                                placeholder="MEN"
                                class="w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm uppercase"
                            >
                            <span class="mt-1 block text-xs text-gray-500">
                                I nuovi elementi prendono MEN-0001, MEN-0002 e così via.
                            </span>
                        </label>

                        <label class="block text-sm">
                            <span class="mb-1 block text-xs font-medium text-gray-600">Nome mostrato in pubblico</span>
                            <input
                                v-model="portale.display_name"
                                placeholder="Comune di Mentana"
                                class="w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm"
                            >
                        </label>
                    </div>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <label class="block text-sm">
                            <span class="mb-1 block text-xs font-medium text-gray-600">Colore dell'intestazione</span>
                            <input v-model="portale.color" type="color" class="h-9 w-full rounded-lg border border-gray-300">
                        </label>

                        <label class="block text-sm sm:col-span-2">
                            <span class="mb-1 block text-xs font-medium text-gray-600">Email per le segnalazioni dei cittadini</span>
                            <input
                                v-model="portale.contact_email"
                                type="email"
                                placeholder="verde@comune.esempio.it"
                                class="w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm"
                            >
                        </label>
                    </div>

                    <label class="block text-sm">
                        <span class="mb-1 block text-xs font-medium text-gray-600">Testo di benvenuto</span>
                        <textarea
                            v-model="portale.welcome_text"
                            rows="3"
                            class="w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm"
                            placeholder="Presentazione del censimento del verde, a cura dell'ente."
                        />
                    </label>

                    <label class="block text-sm">
                        <span class="mb-1 block text-xs font-medium text-gray-600">Riga in fondo alla pagina</span>
                        <input
                            v-model="portale.footer_text"
                            class="w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm"
                            placeholder="Servizio Ambiente - Comune di Mentana"
                        >
                    </label>

                    <div class="flex flex-wrap items-center gap-3 border-t border-gray-100 pt-3">
                        <span class="text-xs font-medium text-gray-600">Stemma</span>
                        <img
                            v-if="selectedClient.has_logo"
                            :src="`${selectedClient.portal_url}/stemma`"
                            alt="Stemma del committente"
                            class="h-10 w-auto rounded border border-gray-200 bg-gray-50"
                        >
                        <span v-else class="text-xs text-gray-500">Nessuno stemma caricato.</span>
                        <input
                            ref="stemmaInput"
                            type="file"
                            accept="image/png,image/jpeg,image/webp"
                            class="w-full text-xs sm:w-auto"
                            @change="caricaStemma"
                        >
                        <button
                            v-if="selectedClient.has_logo"
                            type="button"
                            class="text-xs text-red-500 hover:underline"
                            @click="rimuoviStemma"
                        >rimuovi stemma</button>
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <button type="submit" class="rounded-lg bg-green-700 px-3 py-1.5 text-sm font-medium text-white hover:bg-green-800">
                            Salva impostazioni del portale
                        </button>
                        <a
                            v-if="selectedClient.portal_url && selectedClient.public_enabled"
                            :href="selectedClient.portal_url"
                            target="_blank"
                            rel="noopener"
                            class="text-sm text-green-700 hover:underline"
                        >Apri il portale →</a>
                        <span v-if="portale.salvato" class="text-sm text-green-700">{{ portale.salvato }}</span>
                    </div>
                </form>
            </section>
        </div>
    </AppLayout>
</template>
