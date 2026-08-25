<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import AvvisoErrore from '@/Components/AvvisoErrore.vue';
import { usaCaricamento } from '@/caricamento';
import { corrisponde } from '@/ricerca';
import { workStatusLabel } from '@/workStatus';

const page = usePage();
const canManage = computed(() => (page.props.auth?.user?.permissions ?? []).includes('clients.manage'));

const clients = ref([]);
const sites = ref([]);
const localities = ref([]);

// Un caricamento andato male non deve lasciare le tre colonne vuote in
// silenzio: si vede l'avviso con il pulsante per riprovare
const { avviso, riprovaInCorso, carica, riprova } = usaCaricamento();

// Con qualche decina di committenti scorrere l'elenco non basta piu': si
// cerca a parole, come nel resto del programma. Il filtro lavora sull'elenco
// gia' in pagina, senza chiedere niente al server
const cercaCliente = ref('');
const clientiVisibili = computed(
    () => clients.value.filter(
        (c) => corrisponde([c.name, c.code, c.vat_number, c.fiscal_code], cercaCliente.value),
    ),
);

// --- Scheda della localita' -------------------------------------------------
const scheda = ref(null);
const schedaLocalita = ref(null);
const schedaInCaricamento = ref(false);
const classificazioni = ref({});
const documentoLocalitaInput = ref(null);

// apriScheda commuta: per aggiornare i dati si usa ricaricaScheda,
// altrimenti chiamandola due volte la scheda si chiuderebbe e riaprirebbe
async function apriScheda(l) {
    if (schedaLocalita.value?.id === l.id) {
        schedaLocalita.value = null;
        scheda.value = null;

        return;
    }
    schedaLocalita.value = l;
    await ricaricaScheda();
}

async function ricaricaScheda() {
    if (! schedaLocalita.value) return;
    scheda.value = null;
    schedaInCaricamento.value = true;
    try {
        const { data } = await axios.get(`/api/v1/localities/${schedaLocalita.value.id}/scheda`);
        scheda.value = data.data;
        classificazioni.value = data.meta.classificazioni;
    } catch (err) {
        error.value = firstError(err);
        schedaLocalita.value = null;
    } finally {
        schedaInCaricamento.value = false;
    }
}

async function salvaClassificazione(valore) {
    try {
        await axios.patch(`/api/v1/localities/${schedaLocalita.value.id}`, { istat_class: valore || null });
        await ricaricaScheda();
    } catch (err) {
        error.value = firstError(err);
    }
}

async function caricaDocumentoLocalita(evento) {
    const file = evento.target.files?.[0];
    if (! file) return;
    const modulo = new FormData();
    modulo.append('documento', file);
    try {
        await axios.post(`/api/v1/localities/${schedaLocalita.value.id}/documenti`, modulo);
        await ricaricaScheda();
    } catch (err) {
        error.value = firstError(err);
    } finally {
        if (documentoLocalitaInput.value) documentoLocalitaInput.value.value = '';
    }
}

async function eliminaDocumentoLocalita(d) {
    if (! window.confirm(`Eliminare il documento "${d.title}"?`)) return;
    try {
        await axios.delete(`/api/v1/localities/${schedaLocalita.value.id}/documenti/${d.id}`);
        await ricaricaScheda();
    } catch (err) {
        error.value = firstError(err);
    }
}

const mq = (n) => new Intl.NumberFormat('it-IT', { maximumFractionDigits: 0 }).format(n ?? 0);
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
    // Tutte le pagine, non solo la prima: con piu' di cento committenti la
    // ricerca in pagina non troverebbe quelli oltre il centesimo
    const tutti = [];
    for (let p = 1; p <= 20; p++) {
        const { data } = await axios.get('/api/v1/clients', { params: { per_page: 100, page: p } });
        tutti.push(...data.data);
        if (! data.next_page_url) break;
    }
    clients.value = tutti;
}

async function selectClient(client) {
    selectedClient.value = client;
    selectedSite.value = null;
    localities.value = [];
    caricaPortale(client);
    await caricaVincoli();
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
    show_co2: false,
    legal_owner: '',
    privacy_text: '',
    accessibility_url: '',
    salvato: '',
});
const stemmaInput = ref(null);

// Dominio dei portali (PORTAL_BASE_HOST sul server). Finché non è collegato,
// gli indirizzi restano quelli di collaudo con /comune/<nome> nel percorso.
const dominioPortali = computed(() => page.props.portale?.base_host ?? '');

// I due campi hanno l'aspetto minuscolo/maiuscolo per foglio di stile, ma
// quello che conta è il valore salvato: si normalizza mentre si scrive,
// altrimenti "Guidonia Montecelio" verrebbe rifiutato al salvataggio
function normalizzaNome() {
    portale.public_slug = (portale.public_slug || '')
        .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9-]+/g, '-')
        .replace(/-{2,}/g, '-')
        .slice(0, 40);
}

// Il trattino iniziale o finale non è ammesso, ma toglierlo mentre si scrive
// impedirebbe di digitarlo: si toglie quando si lascia il campo
function rifinisciNome() {
    portale.public_slug = (portale.public_slug || '').replace(/^-+|-+$/g, '');
}

function normalizzaPrefisso() {
    portale.label_prefix = (portale.label_prefix || '')
        .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
        .toUpperCase()
        .replace(/[^A-Z0-9]+/g, '')
        .slice(0, 6);
}

// "comunediguidonia" si legge peggio di "guidonia": è solo un consiglio,
// non un errore, perché qualche ente potrebbe volerlo davvero
const nomeTroppoLungo = computed(() => /^(citta|comune)-?(di|del|della|delle|dei|degli)?-?[a-z0-9]/.test(portale.public_slug || ''));

// Indirizzo che avrà il portale con il nome scritto in questo momento nel
// campo: si aggiorna mentre si scrive, prima ancora di salvare
const indirizzoAnteprima = computed(() => {
    const nome = (portale.public_slug || '').trim().toLowerCase();
    if (! nome) {
        return '';
    }

    return dominioPortali.value
        ? `https://${nome}.${dominioPortali.value}`
        : `${window.location.origin}/comune/${nome}`;
});

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
        show_co2: !! profilo.show_co2,
        legal_owner: profilo.legal_owner ?? '',
        privacy_text: profilo.privacy_text ?? '',
        accessibility_url: profilo.accessibility_url ?? '',
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
                show_co2: portale.show_co2,
                legal_owner: portale.legal_owner || null,
                privacy_text: portale.privacy_text || null,
                accessibility_url: portale.accessibility_url || null,
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

// --- Vincoli del territorio ------------------------------------------------

const vincoli = ref([]);
const vincoloForm = reactive({ open: false, code: '', name: '', authority: '', description: '' });
const vincoloBusy = ref('');

async function caricaVincoli() {
    if (! selectedClient.value) return;
    const { data } = await axios.get('/api/v1/constraints', {
        params: { client_id: selectedClient.value.id, per_page: 100 },
    });
    vincoli.value = data.data;
}

async function salvaVincolo() {
    error.value = '';
    try {
        await axios.post('/api/v1/constraints', {
            client_id: selectedClient.value.id,
            code: vincoloForm.code,
            name: vincoloForm.name || null,
            authority: vincoloForm.authority || null,
            description: vincoloForm.description || null,
        });
        Object.assign(vincoloForm, { open: false, code: '', name: '', authority: '', description: '' });
        await caricaVincoli();
    } catch (err) {
        error.value = firstError(err);
    }
}

async function caricaDocumento(vincolo, evento) {
    const file = evento.target.files?.[0];
    evento.target.value = '';
    if (! file) return;
    error.value = '';
    vincoloBusy.value = vincolo.id;
    const modulo = new FormData();
    modulo.append('documento', file);
    try {
        await axios.post(`/api/v1/constraints/${vincolo.id}/documento`, modulo);
        await caricaVincoli();
    } catch (err) {
        error.value = firstError(err);
    } finally {
        vincoloBusy.value = '';
    }
}

async function ricalcolaVincolo(vincolo) {
    error.value = '';
    vincoloBusy.value = vincolo.id;
    try {
        const { data } = await axios.post(`/api/v1/constraints/${vincolo.id}/ricalcola`);
        await caricaVincoli();
        portale.salvato = `Vincolo collegato a ${data.data.collegati} elementi.`;
    } catch (err) {
        error.value = firstError(err);
    } finally {
        vincoloBusy.value = '';
    }
}

async function eliminaVincolo(vincolo) {
    if (! window.confirm(`Eliminare il vincolo ${vincolo.code}?`)) return;
    error.value = '';
    try {
        await axios.delete(`/api/v1/constraints/${vincolo.id}`);
        await caricaVincoli();
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

// --- Aree di una localita' --------------------------------------------------
// Le aree si disegnano dalla Mappa; qui si elencano e, da vuote, si
// eliminano. Un'area con elementi censiti (o impianti) non si elimina: il
// server rifiuta e spiega, cosi' non si orfanizza mai un censimento.
const canDeleteAree = computed(() => (page.props.auth?.user?.permissions ?? []).includes('areas.delete'));
const areeAperte = ref('');
const aree = ref([]);
const areeInCaricamento = ref(false);

async function commutaAree(l) {
    if (areeAperte.value === l.id) {
        areeAperte.value = '';
        return;
    }
    areeAperte.value = l.id;
    await caricaAree();
}

async function caricaAree() {
    areeInCaricamento.value = true;
    aree.value = [];
    try {
        // Tutte le pagine: il tetto del server e' 100 per richiesta
        let pagina = 1;
        let righe = [];
        for (;;) {
            const { data } = await axios.get('/api/v1/areas', {
                params: { locality_id: areeAperte.value, per_page: 100, page: pagina },
            });
            righe = righe.concat(data.data);
            if (! data.next_page_url) break;
            pagina += 1;
        }
        aree.value = righe;
    } catch (err) {
        error.value = firstError(err);
    } finally {
        areeInCaricamento.value = false;
    }
}

async function eliminaArea(a) {
    if (! window.confirm(`Eliminare l'area "${a.name}"?`)) return;
    error.value = '';
    try {
        await axios.delete(`/api/v1/areas/${a.id}`);
        await caricaAree();
        // Il conteggio "N aree" della localita' deve aggiornarsi
        await selectSite(selectedSite.value);
    } catch (err) {
        error.value = firstError(err);
    }
}

const CLIENT_TYPES = { public: 'Pubblico (PA)', private: 'Privato', condo: 'Condominio', other: 'Altro' };

onMounted(() => carica(loadClients));
</script>

<template>
    <Head title="Territorio" />

    <AppLayout>
        <div class="p-6">
            <h1 class="text-xl font-semibold">Territorio</h1>
            <p class="mb-4 text-sm text-gray-500">Clienti → Sedi → Località. Le aree si disegnano dalla Mappa.</p>

            <p v-if="error" class="mb-3 rounded-lg bg-red-50 px-4 py-2 text-sm text-red-700">{{ error }}</p>

            <AvvisoErrore :messaggio="avviso" :in-corso="riprovaInCorso" @riprova="riprova" />

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <!-- Clienti -->
                <section class="rounded-xl border border-gray-200 bg-white">
                    <header class="flex items-center justify-between border-b border-gray-100 px-4 py-3">
                        <h2 class="text-sm font-semibold">
                            Clienti ({{ clientiVisibili.length }}<span v-if="clientiVisibili.length !== clients.length"> di {{ clients.length }}</span>)
                        </h2>
                        <button
                            v-if="canManage"
                            class="rounded-lg bg-green-700 px-2.5 py-1 text-xs font-medium text-white hover:bg-green-800"
                            @click="forms.client.open = ! forms.client.open"
                        >+ Nuovo</button>
                    </header>

                    <div v-if="clients.length > 5" class="border-b border-gray-100 px-3 py-2">
                        <input
                            v-model="cercaCliente"
                            type="search"
                            placeholder="Cerca per nome o codice…"
                            data-test="cerca-cliente"
                            class="w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm"
                        >
                    </div>

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
                        <li v-for="c in clientiVisibili" :key="c.id">
                            <button
                                class="flex w-full items-center justify-between px-4 py-2.5 text-left text-sm hover:bg-green-50/50"
                                :class="selectedClient?.id === c.id ? 'bg-green-50 font-medium' : ''"
                                @click="carica(() => selectClient(c))"
                            >
                                <span>
                                    {{ c.name }}
                                    <span class="ml-1 rounded bg-gray-100 px-1.5 text-[10px] uppercase text-gray-500">{{ CLIENT_TYPES[c.client_type] }}</span>
                                </span>
                                <span class="text-xs text-gray-400">{{ c.sites_count }} sedi</span>
                            </button>
                        </li>
                        <li v-if="! clientiVisibili.length" class="px-4 py-6 text-center text-sm text-gray-400">
                            {{ clients.length ? 'Nessun cliente trovato.' : 'Nessun cliente.' }}
                        </li>
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
                                @click="carica(() => selectSite(s))"
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
                            class="px-4 py-2.5 text-sm"
                        >
                            <div class="flex items-center justify-between">
                                <span>{{ l.name }} <span v-if="l.code" class="text-gray-400">({{ l.code }})</span></span>
                                <span class="flex items-center gap-3">
                                    <button
                                        v-if="l.areas_count > 0"
                                        class="text-xs text-gray-500 hover:underline"
                                        data-test="apri-aree"
                                        @click="commutaAree(l)"
                                    >{{ areeAperte === l.id ? 'nascondi le aree' : `${l.areas_count} aree` }}</button>
                                    <span v-else class="text-xs text-gray-400">0 aree</span>
                                    <button
                                        class="text-xs font-medium text-green-700 hover:underline"
                                        data-test="apri-scheda-localita"
                                        @click="apriScheda(l)"
                                    >{{ schedaLocalita?.id === l.id ? 'chiudi scheda' : 'scheda' }}</button>
                                    <button
                                        v-if="canManage && l.areas_count === 0"
                                        class="text-xs text-red-500 hover:underline"
                                        @click="removeItem('localities', l.id, () => selectSite(selectedSite))"
                                    >elimina</button>
                                </span>
                            </div>

                            <!-- Le aree della localita': si disegnano dalla Mappa, da vuote si eliminano qui -->
                            <ul v-if="areeAperte === l.id" data-test="elenco-aree" class="mt-2 space-y-1 border-l-2 border-gray-100 pl-3">
                                <li v-if="areeInCaricamento" class="text-xs text-gray-400">Caricamento…</li>
                                <li
                                    v-for="a in aree"
                                    :key="a.id"
                                    class="flex flex-wrap items-center justify-between gap-2 text-xs"
                                >
                                    <span>
                                        {{ a.name }} <span v-if="a.code" class="text-gray-400">({{ a.code }})</span>
                                        <span v-if="a.status !== 'active'" class="ml-1 rounded bg-gray-100 px-1 uppercase text-[10px] text-gray-500">{{ a.status === 'planned' ? 'prevista' : 'dismessa' }}</span>
                                    </span>
                                    <span class="flex items-center gap-3">
                                        <span class="text-gray-400">{{ a.assets_count }} elementi</span>
                                        <button
                                            v-if="canDeleteAree"
                                            class="text-red-500 hover:underline"
                                            :title="a.assets_count > 0 ? 'Si può eliminare solo un\'area senza elementi censiti' : 'Elimina l\'area'"
                                            data-test="elimina-area"
                                            @click="eliminaArea(a)"
                                        >elimina</button>
                                    </span>
                                </li>
                                <li v-if="! areeInCaricamento && ! aree.length" class="text-xs text-gray-400">Nessun'area.</li>
                            </ul>
                        </li>
                        <li v-if="selectedSite && ! localities.length" class="px-4 py-6 text-center text-sm text-gray-400">Nessuna località.</li>
                        <li v-if="! selectedSite" class="px-4 py-6 text-center text-sm text-gray-400">Seleziona una sede.</li>
                    </ul>

                </section>
            </div>

            <!-- Scheda della località -->
                    <section v-if="schedaLocalita" data-test="scheda-localita" class="mt-4 rounded-xl border border-gray-200 bg-white p-4">
                        <h3 class="text-sm font-semibold">{{ schedaLocalita.name }}</h3>
                        <p v-if="schedaInCaricamento" class="mt-2 text-sm text-gray-400">Caricamento…</p>

                        <div v-else-if="scheda" class="mt-3 space-y-4">
                            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                <div class="rounded-lg border border-gray-200 bg-white px-3 py-2">
                                    <div class="text-lg font-semibold">{{ mq(scheda.superfici.totale_mq) }} m²</div>
                                    <div class="text-xs text-gray-500">Superficie totale</div>
                                </div>
                                <div class="rounded-lg border border-gray-200 bg-white px-3 py-2">
                                    <div class="text-lg font-semibold">{{ mq(scheda.superfici.gestita_mq) }} m²</div>
                                    <div class="text-xs text-gray-500">Superficie gestita</div>
                                </div>
                                <div class="rounded-lg border border-gray-200 bg-white px-3 py-2">
                                    <div class="text-lg font-semibold">{{ scheda.superfici.aree }}</div>
                                    <div class="text-xs text-gray-500">Aree ({{ scheda.superfici.aree_attive }} attive)</div>
                                </div>
                                <div class="rounded-lg border border-gray-200 bg-white px-3 py-2">
                                    <div class="text-lg font-semibold">{{ scheda.per_tipo.reduce((t, r) => t + Number(r.quanti), 0) }}</div>
                                    <div class="text-xs text-gray-500">Elementi censiti</div>
                                </div>
                            </div>
                            <p class="text-xs text-gray-500">
                                Superficie totale:
                                {{ scheda.superfici.totale_da_perimetro
                                    ? 'dal perimetro disegnato della località.'
                                    : 'somma delle aree, perché il perimetro della località non è stato disegnato.' }}
                                Superficie gestita: somma delle sole aree attive, quella che conta per i lavori.
                            </p>

                            <label class="block text-sm">
                                <span class="mb-1 block text-xs font-medium text-gray-600">Classificazione (tipologia di verde urbano ISTAT)</span>
                                <select
                                    :value="scheda.localita.istat_class || ''"
                                    :disabled="! canManage"
                                    data-test="scheda-istat"
                                    class="w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm sm:w-96"
                                    @change="salvaClassificazione($event.target.value)"
                                >
                                    <option value="">Non classificata</option>
                                    <option v-for="(etichetta, chiave) in classificazioni" :key="chiave" :value="chiave">{{ etichetta }}</option>
                                </select>
                            </label>

                            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                                <div>
                                    <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Piante presenti</h4>
                                    <table v-if="scheda.piante.length" class="mt-1 w-full text-sm">
                                        <tbody class="divide-y divide-gray-100">
                                            <tr v-for="p in scheda.piante" :key="p.scientifico">
                                                <td class="py-1.5 italic">{{ p.scientifico }}</td>
                                                <td class="py-1.5 text-gray-500">{{ p.comune || '—' }}</td>
                                                <td class="py-1.5 text-right font-medium">{{ p.quanti }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    <p v-else class="mt-1 text-sm text-gray-400">Nessuna pianta censita.</p>
                                </div>

                                <div>
                                    <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Elementi per tipo</h4>
                                    <table v-if="scheda.per_tipo.length" class="mt-1 w-full text-sm">
                                        <tbody class="divide-y divide-gray-100">
                                            <tr v-for="t in scheda.per_tipo" :key="t.code">
                                                <td class="py-1.5"><span class="text-xs text-gray-400">{{ t.code }}</span> {{ t.name }}</td>
                                                <td class="py-1.5 text-right font-medium">{{ t.quanti }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    <p v-else class="mt-1 text-sm text-gray-400">Nessun elemento censito.</p>
                                </div>

                                <div>
                                    <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Chi ci lavora</h4>
                                    <ul v-if="scheda.imprese.length" class="mt-1 divide-y divide-gray-100 text-sm">
                                        <li v-for="i in scheda.imprese" :key="i.nome" class="flex justify-between py-1.5">
                                            <span>{{ i.nome }}</span>
                                            <span class="text-gray-500">{{ i.lavori }} lavori</span>
                                        </li>
                                    </ul>
                                    <p v-else class="mt-1 text-sm text-gray-400">Nessun lavoro assegnato.</p>
                                </div>

                                <div>
                                    <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Documenti</h4>
                                    <ul v-if="scheda.documenti.length" class="mt-1 divide-y divide-gray-100 text-sm">
                                        <li v-for="d in scheda.documenti" :key="d.id" class="flex items-center justify-between gap-2 py-1.5">
                                            <a :href="`/api/v1/documents/${d.id}/file`" target="_blank" class="truncate text-green-700 hover:underline">{{ d.title }}</a>
                                            <button v-if="canManage" class="text-xs text-red-500 hover:underline" @click="eliminaDocumentoLocalita(d)">elimina</button>
                                        </li>
                                    </ul>
                                    <p v-else class="mt-1 text-sm text-gray-400">Nessun documento allegato.</p>
                                    <input
                                        v-if="canManage"
                                        ref="documentoLocalitaInput"
                                        type="file"
                                        accept="application/pdf"
                                        data-test="scheda-documento"
                                        class="mt-2 w-full text-xs"
                                        @change="caricaDocumentoLocalita"
                                    >
                                    <p v-if="canManage" class="mt-1 text-xs text-gray-400">Solo PDF, per esempio il piano di gestione.</p>
                                </div>
                            </div>

                            <div v-if="scheda.lavori.length">
                                <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Ultimi lavori</h4>
                                <ul class="mt-1 divide-y divide-gray-100 text-sm">
                                    <li v-for="w in scheda.lavori" :key="w.id" class="flex flex-wrap items-center gap-2 py-1.5">
                                        <span class="font-medium">{{ w.code }}</span>
                                        <span class="truncate">{{ w.title }}</span>
                                        <span class="ml-auto text-xs text-gray-500">{{ workStatusLabel(w.status) }}</span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </section>

            <!-- Portale pubblico del committente -->
            <section v-if="selectedClient && canManage" class="mt-4 rounded-xl border border-gray-200 bg-white">
                <header class="border-b border-gray-100 px-4 py-3">
                    <h2 class="text-sm font-semibold">Portale pubblico di {{ selectedClient.name }}</h2>
                    <p class="mt-0.5 text-xs text-gray-500">
                        Sito consultabile dai cittadini, con lo stemma e i colori dell'ente. Si accende committente per committente.
                    </p>
                </header>

                <form class="space-y-4 p-4" @submit.prevent="salvaPortale">
                    <div
                        v-if="! dominioPortali"
                        data-test="portale-dominio-mancante"
                        class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-900"
                    >
                        <span class="font-medium">Dominio dei portali non ancora collegato.</span>
                        Per ora ogni Comune si raggiunge con un indirizzo di collaudo, con il nome
                        dentro il percorso. Per avere indirizzi come <span class="font-medium">mentana.tuodominio.it</span>,
                        una volta comprato il dominio va lanciato sul server:
                        <code class="mt-1 block break-all rounded bg-white px-2 py-1">bash /var/www/webgis/deploy/set-portal-domain.sh tuodominio.it</code>
                    </div>

                    <label class="flex items-start gap-2 text-sm">
                        <input v-model="portale.public_enabled" type="checkbox" class="mt-0.5 rounded border-gray-300">
                        <span>
                            Portale pubblico attivo
                            <span class="block text-xs text-gray-500">Da spento, l'indirizzo risponde "pagina non trovata".</span>
                        </span>
                    </label>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <label class="block text-sm">
                            <span class="mb-1 block text-xs font-medium text-gray-600">Nome nell'indirizzo</span>
                            <input
                                v-model="portale.public_slug"
                                placeholder="mentana"
                                class="w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm lowercase"
                                @input="normalizzaNome"
                                @blur="rifinisciNome"
                            >
                            <span data-test="portale-indirizzo" class="mt-1 block break-all text-xs text-gray-500">
                                {{ indirizzoAnteprima || 'Si genera dal nome del committente quando accendi il portale.' }}
                            </span>
                            <span v-if="nomeTroppoLungo" data-test="portale-nome-lungo" class="mt-1 block text-xs text-amber-700">
                                Di solito basta il nome del Comune, senza "comune di".
                            </span>
                        </label>

                        <label class="block text-sm">
                            <span class="mb-1 block text-xs font-medium text-gray-600">Prefisso etichette</span>
                            <input
                                v-model="portale.label_prefix"
                                maxlength="6"
                                placeholder="MEN"
                                class="w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm uppercase"
                                @input="normalizzaPrefisso"
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

                    <label class="flex items-start gap-2 border-t border-gray-100 pt-3 text-sm">
                        <input v-model="portale.show_co2" type="checkbox" class="mt-0.5 rounded border-gray-300">
                        <span>
                            Mostra la stima dell'anidride carbonica
                            <span class="block text-xs text-gray-500">
                                Calcolata dal diametro del tronco con un modello dichiarato sulla pagina.
                                Tienila spenta finché il tecnico non ha verificato coefficienti e fonti.
                            </span>
                        </span>
                    </label>

                    <div class="border-t border-gray-100 pt-3">
                        <p class="mb-2 text-xs font-medium text-gray-600">
                            Privacy e note legali
                            <span class="block font-normal text-gray-500">
                                Servono prima di rendere pubblico il portale di un ente. La parte tecnica
                                (niente cookie, niente statistiche, foto ripulite) è già scritta nella pagina.
                            </span>
                        </p>
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <label class="block text-sm">
                                <span class="mb-1 block text-xs text-gray-600">Titolare del trattamento</span>
                                <input
                                    v-model="portale.legal_owner"
                                    placeholder="Comune di Mentana - Piazza ... - PEC ..."
                                    class="w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm"
                                >
                            </label>
                            <label class="block text-sm">
                                <span class="mb-1 block text-xs text-gray-600">Indirizzo della dichiarazione di accessibilità</span>
                                <input
                                    v-model="portale.accessibility_url"
                                    type="url"
                                    placeholder="https://form.agid.gov.it/view/..."
                                    class="w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm"
                                >
                            </label>
                        </div>
                        <label class="mt-3 block text-sm">
                            <span class="mb-1 block text-xs text-gray-600">Informativa dell'ente (facoltativa)</span>
                            <textarea
                                v-model="portale.privacy_text"
                                rows="3"
                                class="w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm"
                                placeholder="Testo fornito dall'ente, che si aggiunge alla parte tecnica già presente."
                            />
                        </label>
                    </div>

                    <div class="flex flex-wrap items-center gap-3 border-t border-gray-100 pt-3">
                        <span class="text-xs font-medium text-gray-600">Stemma</span>
                        <img
                            v-if="selectedClient.has_logo"
                            :src="`/comune/${selectedClient.public_slug}/stemma`"
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
                        <a
                            v-if="dominioPortali && selectedClient.public_slug && selectedClient.public_enabled"
                            :href="`/comune/${selectedClient.public_slug}`"
                            target="_blank"
                            rel="noopener"
                            class="text-xs text-gray-500 hover:underline"
                            title="Stesso portale, raggiunto da qui: utile finché il DNS del Comune non è pronto"
                        >indirizzo di collaudo</a>
                        <span v-if="portale.salvato" class="text-sm text-green-700">{{ portale.salvato }}</span>
                    </div>
                </form>
            </section>
            <!-- Vincoli del territorio -->
            <section v-if="selectedClient && canManage" class="mt-4 rounded-xl border border-gray-200 bg-white">
                <header class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-4 py-3">
                    <div>
                        <h2 class="text-sm font-semibold">Vincoli su {{ selectedClient.name }} ({{ vincoli.length }})</h2>
                        <p class="mt-0.5 text-xs text-gray-500">
                            Vincoli paesaggistici, archeologici o storici. Compaiono nella scheda pubblica degli
                            elementi a cui sono collegati, con il documento scaricabile.
                        </p>
                    </div>
                    <button
                        class="rounded-lg bg-green-700 px-2.5 py-1 text-xs font-medium text-white hover:bg-green-800"
                        @click="vincoloForm.open = ! vincoloForm.open"
                    >+ Nuovo vincolo</button>
                </header>

                <form v-if="vincoloForm.open" class="space-y-2 border-b border-gray-100 bg-green-50/40 p-3" @submit.prevent="salvaVincolo">
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                        <input v-model="vincoloForm.code" required placeholder="Codice breve * (es. art.28 PTPR)" class="w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
                        <input v-model="vincoloForm.name" placeholder="Descrizione breve (es. Aree urbanizzate)" class="w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
                        <input v-model="vincoloForm.authority" placeholder="Ente che lo impone" class="w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
                    </div>
                    <textarea v-model="vincoloForm.description" rows="2" placeholder="Note estese (facoltative)" class="w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm" />
                    <button type="submit" class="rounded-lg bg-green-700 px-3 py-1.5 text-sm font-medium text-white hover:bg-green-800">Salva vincolo</button>
                </form>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                            <tr>
                                <th class="px-4 py-2">Codice</th>
                                <th class="px-4 py-2">Descrizione</th>
                                <th class="px-4 py-2">Ente</th>
                                <th class="px-4 py-2">Elementi</th>
                                <th class="px-4 py-2">Documento</th>
                                <th class="px-4 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <tr v-for="v in vincoli" :key="v.id">
                                <td class="px-4 py-2 font-medium">{{ v.code }}</td>
                                <td class="px-4 py-2">{{ v.name || '—' }}</td>
                                <td class="px-4 py-2 text-gray-500">{{ v.authority || '—' }}</td>
                                <td class="px-4 py-2 text-gray-500">{{ v.assets_count }}</td>
                                <td class="px-4 py-2">
                                    <span v-if="v.document" class="text-xs text-gray-600">{{ v.document.title }}</span>
                                    <label class="ml-2 cursor-pointer text-xs text-green-700 hover:underline">
                                        {{ v.document ? 'sostituisci' : 'carica PDF' }}
                                        <input type="file" accept="application/pdf" class="hidden" :disabled="vincoloBusy === v.id" @change="caricaDocumento(v, $event)">
                                    </label>
                                </td>
                                <td class="px-4 py-2 text-right whitespace-nowrap">
                                    <button
                                        v-if="v.ha_perimetro"
                                        class="text-xs text-green-700 hover:underline disabled:opacity-50"
                                        :disabled="vincoloBusy === v.id"
                                        @click="ricalcolaVincolo(v)"
                                    >ricalcola</button>
                                    <button class="ml-3 text-xs text-red-500 hover:underline" @click="eliminaVincolo(v)">elimina</button>
                                </td>
                            </tr>
                            <tr v-if="! vincoli.length">
                                <td colspan="6" class="px-4 py-6 text-center text-sm text-gray-400">
                                    Nessun vincolo registrato per questo committente.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
