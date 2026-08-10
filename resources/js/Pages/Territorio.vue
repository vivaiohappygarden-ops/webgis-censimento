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
    const { data } = await axios.get('/api/v1/sites', { params: { client_id: client.id, per_page: 100 } });
    sites.value = data.data;
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
                        >＋ Nuovo</button>
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
                        >＋ Nuova</button>
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
                        >＋ Nuova</button>
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
        </div>
    </AppLayout>
</template>
