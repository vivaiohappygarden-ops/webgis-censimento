<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import * as maplibregl from 'maplibre-gl';
import AppLayout from '@/Layouts/AppLayout.vue';
import AvvisoErrore from '@/Components/AvvisoErrore.vue';
import { usaCaricamento } from '@/caricamento';
import { corrisponde } from '@/ricerca';
import { workStatusLabel } from '@/workStatus';

/*
 * Territorio come albero unico (decisione committente 25/08/2026, dalla bozza
 * approvata): a sinistra Committente -> Sede -> Località con ricerca e filtro
 * per committente, a destra la scheda di ciò che si sceglie, organizzata a
 * schede interne. Il portale pubblico e i vincoli restano sotto il
 * committente, perché sono suoi; le aree stanno sotto la località.
 */

const page = usePage();
const canManage = computed(() => (page.props.auth?.user?.permissions ?? []).includes('clients.manage'));
const canDeleteAree = computed(() => (page.props.auth?.user?.permissions ?? []).includes('areas.delete'));

const clients = ref([]);
// Figli caricati al bisogno: sedi per committente, località per sede
const alberoSedi = reactive({});
const alberoLocalita = reactive({});
const apertoCliente = reactive({});
const apertaSede = reactive({});

// Un caricamento andato male non deve lasciare l'albero vuoto in silenzio
const { avviso, riprovaInCorso, carica, riprova } = usaCaricamento();

const error = ref('');
const firstError = (err) =>
    Object.values(err.response?.data?.errors ?? {})[0]?.[0] ?? err.response?.data?.message ?? 'Errore imprevisto';

const CLIENT_TYPES = { public: 'Pubblico (PA)', private: 'Privato', condo: 'Condominio', other: 'Altro' };
const mq = (n) => new Intl.NumberFormat('it-IT', { maximumFractionDigits: 0 }).format(n ?? 0);

// --- Ricerca e filtro per committente ---------------------------------------
// "Se sto lavorando su Guidonia non voglio vedere gli altri Comuni": il filtro
// restringe l'albero a un solo committente; la ricerca a parole trova
// committenti, sedi e località in un colpo solo
const cerca = ref('');
const filtroCommittente = ref('');
let tuttoCaricato = false;

async function caricaTutto() {
    // La ricerca deve trovare anche le località mai aperte: al primo uso si
    // carica l'intero albero. Il segnale di "fatto" scatta solo alla fine e
    // solo se i committenti erano già in pagina: un errore o una partenza a
    // vuoto non devono lasciare la ricerca cieca per sempre
    if (tuttoCaricato || ! clients.value.length) return;
    for (const c of clients.value) {
        await caricaSedi(c.id);
        for (const s of alberoSedi[c.id] ?? []) {
            await caricaLocalita(s.id);
        }
    }
    tuttoCaricato = true;
}

// clients è fra le sorgenti: se si scrive nella ricerca mentre l'elenco sta
// ancora arrivando (o dopo un nuovo committente), il caricamento riparte
watch([cerca, filtroCommittente, clients], ([q, f]) => {
    if (q || f) carica(caricaTutto);
    if (f) apertoCliente[f] = true;
});

const alberoVisibile = computed(() => {
    let lista = clients.value;
    if (filtroCommittente.value) {
        lista = lista.filter((c) => c.id === filtroCommittente.value);
    }
    if (! cerca.value.trim()) {
        return lista.map((c) => ({ cliente: c, evidenzia: false }));
    }

    return lista
        .map((cliente) => {
            const clienteCorrisponde = corrisponde([cliente.name, cliente.code, cliente.vat_number, cliente.fiscal_code], cerca.value);
            const sediCorrispondono = (alberoSedi[cliente.id] ?? []).some((s) =>
                corrisponde([s.name, s.municipality], cerca.value)
                || (alberoLocalita[s.id] ?? []).some((l) => corrisponde([l.name, l.code], cerca.value)));

            return clienteCorrisponde || sediCorrispondono ? { cliente, evidenzia: true } : null;
        })
        .filter(Boolean);
});

function sediVisibili(cliente) {
    const sedi = alberoSedi[cliente.id] ?? [];
    if (! cerca.value.trim()) return sedi;

    // Se il committente corrisponde da solo, si mostrano tutte le sue sedi
    if (corrisponde([cliente.name, cliente.code, cliente.vat_number, cliente.fiscal_code], cerca.value)) return sedi;

    return sedi.filter((s) => corrisponde([s.name, s.municipality], cerca.value)
        || (alberoLocalita[s.id] ?? []).some((l) => corrisponde([l.name, l.code], cerca.value)));
}

function localitaVisibili(cliente, sede) {
    const localita = alberoLocalita[sede.id] ?? [];
    if (! cerca.value.trim()) return localita;
    if (corrisponde([cliente.name, cliente.code, cliente.vat_number, cliente.fiscal_code], cerca.value)) return localita;
    if (corrisponde([sede.name, sede.municipality], cerca.value)) return localita;

    return localita.filter((l) => corrisponde([l.name, l.code], cerca.value));
}

// Con la ricerca attiva l'albero si apre da solo sui rami trovati
const alberoInRicerca = computed(() => cerca.value.trim() !== '');

function clienteAperto(c) {
    return alberoInRicerca.value || apertoCliente[c.id];
}

function sedeAperta(s) {
    return alberoInRicerca.value || apertaSede[s.id];
}

// --- Caricamento dell'albero ------------------------------------------------

async function loadClients() {
    const tutti = [];
    for (let p = 1; p <= 20; p++) {
        const { data } = await axios.get('/api/v1/clients', { params: { per_page: 100, page: p } });
        tutti.push(...data.data);
        if (! data.next_page_url) break;
    }
    clients.value = tutti;
}

async function caricaSedi(clientId) {
    if (alberoSedi[clientId]) return;
    const { data } = await axios.get('/api/v1/sites', { params: { client_id: clientId, per_page: 100 } });
    alberoSedi[clientId] = data.data;
}

async function caricaLocalita(siteId) {
    if (alberoLocalita[siteId]) return;
    // Tutte le pagine: una sede può avere più di cento località
    let pagina = 1;
    let righe = [];
    for (;;) {
        const { data } = await axios.get('/api/v1/localities', {
            params: { site_id: siteId, per_page: 100, page: pagina },
        });
        righe = righe.concat(data.data);
        if (! data.next_page_url) break;
        pagina += 1;
    }
    alberoLocalita[siteId] = righe;
}

async function commutaCliente(c) {
    apertoCliente[c.id] = ! apertoCliente[c.id];
    if (apertoCliente[c.id]) await caricaSedi(c.id);
}

async function commutaSede(s) {
    apertaSede[s.id] = ! apertaSede[s.id];
    if (apertaSede[s.id]) await caricaLocalita(s.id);
}

// --- Selezione e pannello di destra -----------------------------------------

const selezione = ref(null); // { tipo: 'committente'|'sede'|'localita', cliente, sede, localita }
const schedaCliente = ref('sedi');   // scheda interna del committente
const schedaLocalitaAttiva = ref('aree'); // scheda interna della località

const selectedClient = computed(() => selezione.value?.cliente ?? null);

// Le selezioni si lanciano dal template dentro carica(): un errore di rete
// mostra l'avviso con il numero e il pulsante Riprova, mai il silenzio
async function selezionaCliente(c) {
    error.value = '';
    selezione.value = { tipo: 'committente', cliente: c };
    schedaCliente.value = 'sedi';
    apertoCliente[c.id] = true;
    caricaPortale(c);
    // I vincoli del committente precedente non devono restare a video se il
    // caricamento dei nuovi fallisce
    vincoli.value = [];
    await Promise.all([caricaSedi(c.id), caricaVincoli(c)]);
}

async function selezionaSede(cliente, sede) {
    error.value = '';
    selezione.value = { tipo: 'sede', cliente, sede };
    apertoCliente[cliente.id] = true;
    apertaSede[sede.id] = true;
    await caricaLocalita(sede.id);
}

async function selezionaLocalita(cliente, sede, localita) {
    error.value = '';
    selezione.value = { tipo: 'localita', cliente, sede, localita };
    schedaLocalitaAttiva.value = 'aree';
    scheda.value = null;
    await Promise.all([ricaricaScheda(), caricaAreeLocalita(localita.id)]);
}

// --- Scheda della località --------------------------------------------------

const scheda = ref(null);
const schedaInCaricamento = ref(false);
const classificazioni = ref({});
const documentoLocalitaInput = ref(null);

const localitaScelta = computed(() => selezione.value?.tipo === 'localita' ? selezione.value.localita : null);

async function ricaricaScheda() {
    if (! localitaScelta.value) return;
    // La risposta vale solo se nel frattempo non si è scelta un'altra voce:
    // senza questa guardia, due clic ravvicinati mostrerebbero i dati di una
    // località sotto l'intestazione di un'altra
    const id = localitaScelta.value.id;
    schedaInCaricamento.value = true;
    try {
        const { data } = await axios.get(`/api/v1/localities/${id}/scheda`);
        if (localitaScelta.value?.id !== id) return;
        scheda.value = data.data;
        classificazioni.value = data.meta.classificazioni;
    } finally {
        if (localitaScelta.value?.id === id) schedaInCaricamento.value = false;
    }
}

async function salvaClassificazione(valore) {
    try {
        await axios.patch(`/api/v1/localities/${localitaScelta.value.id}`, { istat_class: valore || null });
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
        await axios.post(`/api/v1/localities/${localitaScelta.value.id}/documenti`, modulo);
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
        await axios.delete(`/api/v1/localities/${localitaScelta.value.id}/documenti/${d.id}`);
        await ricaricaScheda();
    } catch (err) {
        error.value = firstError(err);
    }
}

// --- Aree della località ----------------------------------------------------
// Le aree si disegnano dalla Mappa; qui si elencano e, da vuote, si eliminano.
// Un'area con elementi censiti (o impianti) non si elimina: il server rifiuta
// e spiega, così non si orfanizza mai un censimento.

const aree = ref([]);
const areeInCaricamento = ref(false);

async function caricaAreeLocalita(localityId) {
    areeInCaricamento.value = true;
    aree.value = [];
    try {
        let pagina = 1;
        let righe = [];
        for (;;) {
            const { data } = await axios.get('/api/v1/areas', {
                params: { locality_id: localityId, per_page: 100, page: pagina },
            });
            righe = righe.concat(data.data);
            if (! data.next_page_url) break;
            pagina += 1;
        }
        // Nel frattempo si è scelta un'altra località: queste aree non sono
        // più sue, e il pulsante elimina agirebbe sull'area sbagliata
        if (localitaScelta.value?.id !== localityId) return;
        aree.value = righe;
        await nextTick();
        disegnaAnteprima();
    } finally {
        if (localitaScelta.value?.id === localityId) areeInCaricamento.value = false;
    }
}

async function eliminaArea(a) {
    if (! window.confirm(`Eliminare l'area "${a.name}"?`)) return;
    error.value = '';
    // Località e sede si fissano PRIMA della richiesta: se durante l'attesa
    // si clicca altrove, la selezione cambia e i riferimenti sparirebbero
    const localita = localitaScelta.value;
    const sede = selezione.value?.sede;
    try {
        await axios.delete(`/api/v1/areas/${a.id}`);
        if (localitaScelta.value?.id === localita?.id) {
            await Promise.all([caricaAreeLocalita(localita.id), ricaricaScheda()]);
        }
        // Il conteggio "N aree" nell'albero deve aggiornarsi comunque
        if (sede) {
            delete alberoLocalita[sede.id];
            await caricaLocalita(sede.id);
            const aggiornata = (alberoLocalita[sede.id] ?? []).find((l) => l.id === localita?.id);
            if (aggiornata && localitaScelta.value?.id === localita?.id) {
                selezione.value = { ...selezione.value, localita: aggiornata };
            }
        }
    } catch (err) {
        error.value = firstError(err);
    }
}

const STATO_AREA = { active: 'Attiva', planned: 'Prevista', dismissed: 'Dismessa' };

// Anteprima geografica: le aree della località come stanno sulla Mappa
const anteprimaEl = ref(null);
let anteprimaMappa = null;

function disegnaAnteprima() {
    anteprimaMappa?.remove();
    anteprimaMappa = null;
    const geometrie = aree.value.filter((a) => a.geom_geojson);
    if (! anteprimaEl.value || ! geometrie.length) return;

    anteprimaMappa = new maplibregl.Map({
        container: anteprimaEl.value,
        style: {
            version: 8,
            sources: {
                osm: {
                    type: 'raster',
                    tiles: ['https://tile.openstreetmap.org/{z}/{x}/{y}.png'],
                    tileSize: 256, maxzoom: 19,
                    attribution: '© OpenStreetMap contributors',
                },
            },
            layers: [
                { id: 'sfondo', type: 'background', paint: { 'background-color': '#e8ede9' } },
                { id: 'osm', type: 'raster', source: 'osm' },
            ],
        },
        center: [12.5, 42], zoom: 5,
        interactive: false, attributionControl: { compact: true },
    });

    anteprimaMappa.on('load', () => {
        anteprimaMappa.addSource('aree', {
            type: 'geojson',
            data: {
                type: 'FeatureCollection',
                features: geometrie.map((a) => ({ type: 'Feature', geometry: a.geom_geojson, properties: {} })),
            },
        });
        anteprimaMappa.addLayer({ id: 'aree-fill', type: 'fill', source: 'aree', paint: { 'fill-color': '#16a34a', 'fill-opacity': 0.18 } });
        anteprimaMappa.addLayer({ id: 'aree-line', type: 'line', source: 'aree', paint: { 'line-color': '#15803d', 'line-width': 1.5 } });

        const bounds = new maplibregl.LngLatBounds();
        const estendi = (coords) => {
            if (typeof coords[0] === 'number') bounds.extend(coords);
            else coords.forEach(estendi);
        };
        geometrie.forEach((a) => estendi(a.geom_geojson.coordinates));
        anteprimaMappa.fitBounds(bounds, { padding: 24, maxZoom: 16, duration: 0 });
    });
}

// L'anteprima vive solo nella scheda Aree: cambiandola il contenitore sparisce
watch(schedaLocalitaAttiva, async (attiva) => {
    if (attiva === 'aree' && localitaScelta.value) {
        await nextTick();
        disegnaAnteprima();
    }
});

onBeforeUnmount(() => anteprimaMappa?.remove());

// --- Moduli di creazione ----------------------------------------------------

const forms = reactive({
    client: { open: false, name: '', client_type: 'public', code: '' },
    site: { open: false, name: '', municipality: '', istat_code: '', province: '' },
    locality: { open: false, name: '', code: '' },
});

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

// Dopo una creazione o eliminazione i conteggi ("N sedi", "N località")
// vanno riallineati anche negli oggetti tenuti dalla selezione, che sono
// copie: senza, l'intestazione direbbe "2 sedi" e l'albero "3 sedi"
async function aggiornaContatori(clienteId, sedeId = null) {
    await loadClients();
    if (clienteId) {
        delete alberoSedi[clienteId];
        await caricaSedi(clienteId);
    }
    if (selezione.value?.cliente) {
        const c = clients.value.find((x) => x.id === selezione.value.cliente.id);
        if (c) selezione.value = { ...selezione.value, cliente: { ...selezione.value.cliente, ...c } };
    }
    if (sedeId && selezione.value?.sede) {
        const s = (alberoSedi[clienteId] ?? []).find((x) => x.id === selezione.value.sede.id);
        if (s) selezione.value = { ...selezione.value, sede: { ...selezione.value.sede, ...s } };
    }
}

async function saveSite() {
    error.value = '';
    try {
        await axios.post('/api/v1/sites', {
            client_id: selezione.value.cliente.id,
            name: forms.site.name,
            municipality: forms.site.municipality || null,
            istat_code: forms.site.istat_code || null,
            province: forms.site.province || null,
        });
        forms.site = { open: false, name: '', municipality: '', istat_code: '', province: '' };
        await aggiornaContatori(selezione.value.cliente.id);
    } catch (err) {
        error.value = firstError(err);
    }
}

async function saveLocality() {
    error.value = '';
    try {
        await axios.post('/api/v1/localities', {
            site_id: selezione.value.sede.id,
            name: forms.locality.name,
            code: forms.locality.code || null,
        });
        forms.locality = { open: false, name: '', code: '' };
        delete alberoLocalita[selezione.value.sede.id];
        await caricaLocalita(selezione.value.sede.id);
        await aggiornaContatori(selezione.value.cliente.id, selezione.value.sede.id);
    } catch (err) {
        error.value = firstError(err);
    }
}

async function eliminaLocalita(l) {
    error.value = '';
    if (! window.confirm(`Eliminare la località "${l.name}"?`)) return;
    const sedeId = selezione.value?.sede?.id ?? l.site_id;
    const clienteId = selezione.value?.cliente?.id;
    try {
        await axios.delete(`/api/v1/localities/${l.id}`);
        delete alberoLocalita[sedeId];
        if (selezione.value?.tipo === 'localita' && selezione.value.localita.id === l.id) {
            selezione.value = { tipo: 'sede', cliente: selezione.value.cliente, sede: selezione.value.sede };
        }
        await caricaLocalita(sedeId);
        if (clienteId) await aggiornaContatori(clienteId, sedeId);
    } catch (err) {
        error.value = firstError(err);
    }
}

// --- Portale pubblico del committente ---------------------------------------

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

function normalizzaNome() {
    portale.public_slug = (portale.public_slug || '')
        .normalize('NFD').replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9-]+/g, '-')
        .replace(/-{2,}/g, '-')
        .slice(0, 40);
}

function rifinisciNome() {
    portale.public_slug = (portale.public_slug || '').replace(/^-+|-+$/g, '');
}

function normalizzaPrefisso() {
    portale.label_prefix = (portale.label_prefix || '')
        .normalize('NFD').replace(/[̀-ͯ]/g, '')
        .toUpperCase()
        .replace(/[^A-Z0-9]+/g, '')
        .slice(0, 6);
}

const nomeTroppoLungo = computed(() => /^(citta|comune)-?(di|del|della|delle|dei|degli)?-?[a-z0-9]/.test(portale.public_slug || ''));

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
    if (selezione.value?.cliente?.id === aggiornato.id) {
        selezione.value = { ...selezione.value, cliente: { ...selezione.value.cliente, ...aggiornato } };
        caricaPortale(selezione.value.cliente);
    }
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

// --- Vincoli del territorio -------------------------------------------------

const vincoli = ref([]);
const vincoloForm = reactive({ open: false, code: '', name: '', authority: '', description: '' });
const vincoloBusy = ref('');

async function caricaVincoli(cliente = null) {
    const id = (cliente ?? selectedClient.value)?.id;
    if (! id) return;
    const { data } = await axios.get('/api/v1/constraints', {
        params: { client_id: id, per_page: 100 },
    });
    // La risposta vale solo se il committente scelto è ancora lui
    if (selectedClient.value?.id !== id) return;
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

onMounted(() => carica(loadClients));
</script>

<template>
    <Head title="Territorio" />

    <AppLayout>
        <div class="p-6">
            <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold">Territorio</h1>
                    <p class="text-sm text-gray-500">Committenti, sedi, località e aree in un albero unico. Le aree si disegnano dalla Mappa.</p>
                </div>
                <div class="flex w-full flex-wrap items-center gap-2 sm:w-auto">
                    <select
                        v-model="filtroCommittente"
                        data-test="territorio-filtro-committente"
                        class="w-full rounded-lg border border-gray-300 px-2 py-2 text-sm sm:w-56"
                    >
                        <option value="">Tutti i committenti</option>
                        <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                    <input
                        v-model="cerca"
                        type="search"
                        placeholder="Cerca committente, sede, località…"
                        data-test="cerca-territorio"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm sm:w-80"
                    >
                </div>
            </div>

            <p v-if="error" class="mb-3 rounded-lg bg-red-50 px-4 py-2 text-sm text-red-700">{{ error }}</p>

            <AvvisoErrore :messaggio="avviso" :in-corso="riprovaInCorso" @riprova="riprova" />

            <div class="flex flex-col gap-4 lg:flex-row lg:items-start">

                <!-- Albero del territorio -->
                <section class="w-full shrink-0 rounded-xl border border-gray-200 bg-white lg:w-[350px]">
                    <header class="flex items-center justify-between border-b border-gray-100 px-4 py-3">
                        <h2 class="text-sm font-semibold">Albero del territorio</h2>
                        <button
                            v-if="canManage"
                            class="rounded-lg bg-green-700 px-2.5 py-1 text-xs font-medium text-white hover:bg-green-800"
                            data-test="nuovo-committente"
                            @click="forms.client.open = ! forms.client.open"
                        >+ Committente</button>
                    </header>

                    <form v-if="forms.client.open" class="space-y-2 border-b border-gray-100 bg-green-50/40 p-3" @submit.prevent="saveClient">
                        <input v-model="forms.client.name" required placeholder="Ragione sociale *" class="w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
                        <div class="flex gap-2">
                            <select v-model="forms.client.client_type" class="flex-1 rounded-lg border border-gray-300 px-2 py-1.5 text-sm">
                                <option v-for="(label, value) in CLIENT_TYPES" :key="value" :value="value">{{ label }}</option>
                            </select>
                            <input v-model="forms.client.code" placeholder="Codice" class="w-24 rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
                        </div>
                        <button type="submit" class="w-full rounded-lg bg-green-700 py-1.5 text-sm font-medium text-white hover:bg-green-800">Salva committente</button>
                    </form>

                    <div class="max-h-[65vh] overflow-y-auto p-2 text-sm" data-test="albero-territorio">
                        <template v-for="riga in alberoVisibile" :key="riga.cliente.id">
                            <div
                                class="flex items-center gap-1.5 rounded-lg px-2 py-1.5 hover:bg-gray-50"
                                :class="selezione?.tipo === 'committente' && selezione.cliente.id === riga.cliente.id ? 'bg-green-50 hover:bg-green-50' : ''"
                            >
                                <button class="p-0.5 text-gray-400 hover:text-gray-700" :title="clienteAperto(riga.cliente) ? 'Chiudi' : 'Apri'" @click="carica(() => commutaCliente(riga.cliente))">
                                    <span class="inline-block font-mono text-xs">{{ clienteAperto(riga.cliente) ? '−' : '+' }}</span>
                                </button>
                                <button
                                    class="flex min-w-0 flex-1 items-center gap-2 text-left"
                                    data-test="albero-committente"
                                    @click="carica(() => selezionaCliente(riga.cliente))"
                                >
                                    <span class="truncate font-semibold" :class="selezione?.cliente?.id === riga.cliente.id ? 'text-green-800' : ''">{{ riga.cliente.name }}</span>
                                    <span class="rounded bg-gray-100 px-1.5 text-[10px] uppercase text-gray-500">{{ CLIENT_TYPES[riga.cliente.client_type] }}</span>
                                    <span class="ml-auto whitespace-nowrap text-xs text-gray-400">{{ riga.cliente.sites_count }} sedi</span>
                                </button>
                            </div>

                            <template v-if="clienteAperto(riga.cliente)">
                                <div v-if="! alberoSedi[riga.cliente.id]" class="py-1 pl-8 text-xs text-gray-400">Caricamento…</div>
                                <template v-for="s in sediVisibili(riga.cliente)" :key="s.id">
                                    <div
                                        class="flex items-center gap-1.5 rounded-lg py-1 pl-6 pr-2 hover:bg-gray-50"
                                        :class="selezione?.tipo === 'sede' && selezione.sede.id === s.id ? 'bg-green-50 hover:bg-green-50' : ''"
                                    >
                                        <button class="p-0.5 text-gray-400 hover:text-gray-700" @click="carica(() => commutaSede(s))">
                                            <span class="inline-block font-mono text-xs">{{ sedeAperta(s) ? '−' : '+' }}</span>
                                        </button>
                                        <button class="flex min-w-0 flex-1 items-center gap-2 text-left" data-test="albero-sede" @click="carica(() => selezionaSede(riga.cliente, s))">
                                            <span class="truncate font-medium">{{ s.name }}</span>
                                            <span v-if="s.municipality" class="truncate text-xs text-gray-400">{{ s.municipality }}</span>
                                            <span class="ml-auto whitespace-nowrap text-xs text-gray-400">{{ s.localities_count }} località</span>
                                        </button>
                                    </div>

                                    <template v-if="sedeAperta(s)">
                                        <div v-if="! alberoLocalita[s.id]" class="py-1 pl-14 text-xs text-gray-400">Caricamento…</div>
                                        <button
                                            v-for="l in localitaVisibili(riga.cliente, s)"
                                            :key="l.id"
                                            class="flex w-full items-center gap-2 rounded-lg py-1.5 pl-12 pr-2 text-left hover:bg-gray-50"
                                            :class="selezione?.tipo === 'localita' && selezione.localita.id === l.id ? 'bg-green-50 text-green-800 hover:bg-green-50' : ''"
                                            data-test="albero-localita"
                                            @click="carica(() => selezionaLocalita(riga.cliente, s, l))"
                                        >
                                            <span class="truncate font-medium">{{ l.name }}</span>
                                            <span v-if="l.code" class="font-mono text-xs text-gray-400">{{ l.code }}</span>
                                            <span class="ml-auto whitespace-nowrap text-xs" :class="selezione?.localita?.id === l.id ? 'text-green-700' : 'text-gray-400'">{{ l.areas_count }} aree</span>
                                        </button>
                                    </template>
                                </template>
                            </template>
                        </template>

                        <p v-if="! alberoVisibile.length" class="px-3 py-6 text-center text-sm text-gray-400">
                            {{ clients.length ? 'Niente da mostrare con questa ricerca.' : 'Nessun committente.' }}
                        </p>
                    </div>

                    <footer class="border-t border-gray-100 px-4 py-2.5 text-xs text-gray-400">
                        {{ alberoVisibile.length }} committenti<span v-if="alberoVisibile.length !== clients.length"> su {{ clients.length }}</span>
                    </footer>
                </section>

                <!-- Scheda di ciò che è selezionato -->
                <section class="min-w-0 flex-1 rounded-xl border border-gray-200 bg-white">

                    <!-- Niente selezionato -->
                    <div v-if="! selezione" class="px-6 py-16 text-center text-sm text-gray-400">
                        Scegli un committente, una sede o una località dall'albero qui accanto.
                    </div>

                    <!-- ============ COMMITTENTE ============ -->
                    <div v-else-if="selezione.tipo === 'committente'">
                        <div class="flex flex-wrap items-start justify-between gap-3 px-5 pt-4">
                            <div>
                                <div class="flex items-baseline gap-2">
                                    <h2 class="text-lg font-bold">{{ selezione.cliente.name }}</h2>
                                    <span class="rounded bg-gray-100 px-1.5 text-[10px] uppercase text-gray-500">{{ CLIENT_TYPES[selezione.cliente.client_type] }}</span>
                                </div>
                                <p class="text-xs text-gray-500">{{ selezione.cliente.sites_count }} sedi</p>
                            </div>
                            <div v-if="selezione.cliente.portal_url && selezione.cliente.public_enabled">
                                <a :href="selezione.cliente.portal_url" target="_blank" rel="noopener" class="rounded-lg border border-green-700 px-3 py-1.5 text-sm font-medium text-green-700 hover:bg-green-50">Apri il portale</a>
                            </div>
                        </div>

                        <div class="mt-3 flex gap-1 overflow-x-auto border-b border-gray-200 px-5 text-sm font-medium">
                            <button class="whitespace-nowrap px-3 py-2" :class="schedaCliente === 'sedi' ? 'border-b-2 border-green-700 text-green-800' : 'text-gray-500 hover:text-gray-800'" @click="schedaCliente = 'sedi'">Sedi</button>
                            <button v-if="canManage" class="whitespace-nowrap px-3 py-2" :class="schedaCliente === 'portale' ? 'border-b-2 border-green-700 text-green-800' : 'text-gray-500 hover:text-gray-800'" data-test="scheda-portale" @click="schedaCliente = 'portale'">Portale pubblico</button>
                            <button v-if="canManage" class="whitespace-nowrap px-3 py-2" :class="schedaCliente === 'vincoli' ? 'border-b-2 border-green-700 text-green-800' : 'text-gray-500 hover:text-gray-800'" data-test="scheda-vincoli" @click="schedaCliente = 'vincoli'">Vincoli ({{ vincoli.length }})</button>
                        </div>

                        <!-- Sedi del committente -->
                        <div v-if="schedaCliente === 'sedi'" class="p-5">
                            <div class="mb-2 flex items-center justify-between">
                                <h3 class="text-sm font-semibold">Sedi</h3>
                                <button
                                    v-if="canManage"
                                    class="rounded-lg bg-green-700 px-2.5 py-1 text-xs font-medium text-white hover:bg-green-800"
                                    @click="forms.site.open = ! forms.site.open"
                                >+ Nuova sede</button>
                            </div>

                            <form v-if="forms.site.open" class="mb-3 space-y-2 rounded-lg bg-green-50/40 p-3" @submit.prevent="saveSite">
                                <input v-model="forms.site.name" required placeholder="Nome sede *" class="w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
                                <div class="flex flex-wrap gap-2">
                                    <input v-model="forms.site.municipality" placeholder="Comune" class="w-full flex-1 rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm sm:w-auto">
                                    <input v-model="forms.site.province" maxlength="2" placeholder="PR" class="w-14 rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm uppercase">
                                    <input v-model="forms.site.istat_code" maxlength="6" placeholder="ISTAT" class="w-20 rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
                                </div>
                                <button type="submit" class="w-full rounded-lg bg-green-700 py-1.5 text-sm font-medium text-white hover:bg-green-800">Salva sede</button>
                            </form>

                            <ul class="divide-y divide-gray-50">
                                <li v-for="s in alberoSedi[selezione.cliente.id] ?? []" :key="s.id">
                                    <button class="flex w-full items-center justify-between py-2 text-left text-sm hover:bg-gray-50" @click="carica(() => selezionaSede(selezione.cliente, s))">
                                        <span>{{ s.name }} <span v-if="s.municipality" class="text-gray-400">— {{ s.municipality }}</span></span>
                                        <span class="text-xs text-gray-400">{{ s.localities_count }} località</span>
                                    </button>
                                </li>
                                <li v-if="! (alberoSedi[selezione.cliente.id] ?? []).length" class="py-6 text-center text-sm text-gray-400">Nessuna sede.</li>
                            </ul>
                        </div>

                        <!-- Portale pubblico -->
                        <div v-else-if="schedaCliente === 'portale' && canManage">
                            <form class="space-y-4 p-5" @submit.prevent="salvaPortale">
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
                                        v-if="selezione.cliente.has_logo"
                                        :src="`/comune/${selezione.cliente.public_slug}/stemma`"
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
                                        v-if="selezione.cliente.has_logo"
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
                                        v-if="selezione.cliente.portal_url && selezione.cliente.public_enabled"
                                        :href="selezione.cliente.portal_url"
                                        target="_blank"
                                        rel="noopener"
                                        class="text-sm text-green-700 hover:underline"
                                    >Apri il portale →</a>
                                    <a
                                        v-if="dominioPortali && selezione.cliente.public_slug && selezione.cliente.public_enabled"
                                        :href="`/comune/${selezione.cliente.public_slug}`"
                                        target="_blank"
                                        rel="noopener"
                                        class="text-xs text-gray-500 hover:underline"
                                        title="Stesso portale, raggiunto da qui: utile finché il DNS del Comune non è pronto"
                                    >indirizzo di collaudo</a>
                                    <span v-if="portale.salvato" class="text-sm text-green-700">{{ portale.salvato }}</span>
                                </div>
                            </form>
                        </div>

                        <!-- Vincoli del committente -->
                        <div v-else-if="schedaCliente === 'vincoli' && canManage">
                            <div class="flex flex-wrap items-center justify-between gap-2 px-5 py-3">
                                <p class="text-xs text-gray-500">
                                    Vincoli paesaggistici, archeologici o storici. Compaiono nella scheda pubblica degli
                                    elementi a cui sono collegati, con il documento scaricabile.
                                </p>
                                <button
                                    class="rounded-lg bg-green-700 px-2.5 py-1 text-xs font-medium text-white hover:bg-green-800"
                                    @click="vincoloForm.open = ! vincoloForm.open"
                                >+ Nuovo vincolo</button>
                            </div>

                            <form v-if="vincoloForm.open" class="space-y-2 border-y border-gray-100 bg-green-50/40 p-3" @submit.prevent="salvaVincolo">
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
                                            <td class="whitespace-nowrap px-4 py-2 text-right">
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
                        </div>
                    </div>

                    <!-- ============ SEDE ============ -->
                    <div v-else-if="selezione.tipo === 'sede'" class="p-5">
                        <p class="text-xs text-gray-500">{{ selezione.cliente.name }}</p>
                        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                            <h2 class="text-lg font-bold">{{ selezione.sede.name }} <span v-if="selezione.sede.municipality" class="text-sm font-normal text-gray-400">— {{ selezione.sede.municipality }}</span></h2>
                            <button
                                v-if="canManage"
                                class="rounded-lg bg-green-700 px-2.5 py-1 text-xs font-medium text-white hover:bg-green-800"
                                @click="forms.locality.open = ! forms.locality.open"
                            >+ Nuova località</button>
                        </div>

                        <form v-if="forms.locality.open" class="mb-3 space-y-2 rounded-lg bg-green-50/40 p-3" @submit.prevent="saveLocality">
                            <div class="flex gap-2">
                                <input v-model="forms.locality.name" required placeholder="Nome località *" class="flex-1 rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
                                <input v-model="forms.locality.code" placeholder="Codice" class="w-20 rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
                            </div>
                            <button type="submit" class="w-full rounded-lg bg-green-700 py-1.5 text-sm font-medium text-white hover:bg-green-800">Salva località</button>
                        </form>

                        <ul class="divide-y divide-gray-50">
                            <li v-for="l in alberoLocalita[selezione.sede.id] ?? []" :key="l.id" class="flex items-center justify-between py-2 text-sm">
                                <button class="min-w-0 flex-1 text-left hover:underline" @click="carica(() => selezionaLocalita(selezione.cliente, selezione.sede, l))">
                                    {{ l.name }} <span v-if="l.code" class="text-gray-400">({{ l.code }})</span>
                                </button>
                                <span class="flex items-center gap-3">
                                    <span class="text-xs text-gray-400">{{ l.areas_count }} aree</span>
                                    <button
                                        v-if="canManage && l.areas_count === 0"
                                        class="text-xs text-red-500 hover:underline"
                                        @click="eliminaLocalita(l)"
                                    >elimina</button>
                                </span>
                            </li>
                            <li v-if="! (alberoLocalita[selezione.sede.id] ?? []).length" class="py-6 text-center text-sm text-gray-400">Nessuna località.</li>
                        </ul>
                    </div>

                    <!-- ============ LOCALITÀ ============ -->
                    <div v-else-if="selezione.tipo === 'localita'" data-test="scheda-localita">
                        <div class="flex flex-wrap items-start justify-between gap-3 px-5 pt-4">
                            <div>
                                <p class="text-xs text-gray-500">{{ selezione.cliente.name }} → {{ selezione.sede.name }}</p>
                                <div class="flex items-baseline gap-2">
                                    <h2 class="text-lg font-bold">{{ selezione.localita.name }}</h2>
                                    <span v-if="selezione.localita.code" class="font-mono text-sm text-gray-500">{{ selezione.localita.code }}</span>
                                </div>
                            </div>
                            <button
                                v-if="canManage && selezione.localita.areas_count === 0"
                                class="text-xs text-red-500 hover:underline"
                                @click="eliminaLocalita(selezione.localita)"
                            >elimina località</button>
                        </div>

                        <!-- I numeri della località -->
                        <div v-if="scheda" class="grid grid-cols-2 gap-3 px-5 py-3 sm:grid-cols-4">
                            <div class="rounded-lg border border-gray-200 px-3 py-2">
                                <div class="text-lg font-semibold">{{ scheda.superfici.aree }}</div>
                                <div class="text-xs text-gray-500">Aree ({{ scheda.superfici.aree_attive }} attive)</div>
                            </div>
                            <div class="rounded-lg border border-gray-200 px-3 py-2">
                                <div class="text-lg font-semibold">{{ scheda.per_tipo.reduce((t, r) => t + Number(r.quanti), 0) }}</div>
                                <div class="text-xs text-gray-500">Elementi censiti</div>
                            </div>
                            <div class="rounded-lg border border-gray-200 px-3 py-2">
                                <div class="text-lg font-semibold">{{ mq(scheda.superfici.gestita_mq) }} m²</div>
                                <div class="text-xs text-gray-500">Superficie gestita</div>
                            </div>
                            <div class="rounded-lg border border-gray-200 px-3 py-2">
                                <div class="flex items-center gap-1.5 text-sm font-semibold">
                                    <span class="inline-block h-2 w-2 rounded-full" :class="selezione.cliente.public_enabled ? 'bg-green-600' : 'bg-gray-300'"></span>
                                    {{ selezione.cliente.public_enabled ? 'Attivo' : 'Spento' }}
                                </div>
                                <div class="text-xs text-gray-500">Portale pubblico</div>
                            </div>
                        </div>
                        <p v-else-if="schedaInCaricamento" class="px-5 py-3 text-sm text-gray-400">Caricamento…</p>

                        <div class="flex gap-1 overflow-x-auto border-b border-gray-200 px-5 text-sm font-medium">
                            <button class="whitespace-nowrap px-3 py-2" :class="schedaLocalitaAttiva === 'aree' ? 'border-b-2 border-green-700 text-green-800' : 'text-gray-500 hover:text-gray-800'" data-test="scheda-aree" @click="schedaLocalitaAttiva = 'aree'">Aree</button>
                            <button class="whitespace-nowrap px-3 py-2" :class="schedaLocalitaAttiva === 'scheda' ? 'border-b-2 border-green-700 text-green-800' : 'text-gray-500 hover:text-gray-800'" data-test="apri-scheda-localita" @click="schedaLocalitaAttiva = 'scheda'">Scheda località</button>
                            <button class="whitespace-nowrap px-3 py-2" :class="schedaLocalitaAttiva === 'documenti' ? 'border-b-2 border-green-700 text-green-800' : 'text-gray-500 hover:text-gray-800'" data-test="scheda-documenti" @click="schedaLocalitaAttiva = 'documenti'">Documenti ({{ scheda?.documenti.length ?? 0 }})</button>
                        </div>

                        <!-- Aree -->
                        <div v-if="schedaLocalitaAttiva === 'aree'" class="flex flex-col gap-4 p-5 lg:flex-row">
                            <div class="min-w-0 flex-1">
                                <div class="overflow-x-auto">
                                    <table class="min-w-full text-sm">
                                        <thead class="text-left text-xs uppercase text-gray-500">
                                            <tr>
                                                <th class="py-1.5 pr-3 font-semibold">Area</th>
                                                <th class="py-1.5 pr-3 font-semibold">Stato</th>
                                                <th class="py-1.5 pr-3 text-right font-semibold">Elementi</th>
                                                <th class="py-1.5 pr-3 text-right font-semibold">Superficie</th>
                                                <th class="py-1.5"></th>
                                            </tr>
                                        </thead>
                                        <tbody data-test="elenco-aree" class="divide-y divide-gray-50">
                                            <tr v-for="a in aree" :key="a.id">
                                                <td class="py-2 pr-3">
                                                    <span class="font-medium">{{ a.name }}</span>
                                                    <span v-if="a.code" class="ml-1 font-mono text-xs text-gray-400">{{ a.code }}</span>
                                                </td>
                                                <td class="py-2 pr-3">
                                                    <span
                                                        class="rounded-full px-2 py-0.5 text-[11px] font-semibold"
                                                        :class="a.status === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'"
                                                    >{{ STATO_AREA[a.status] ?? a.status }}</span>
                                                </td>
                                                <td class="py-2 pr-3 text-right">{{ a.assets_count }}</td>
                                                <td class="py-2 pr-3 text-right">{{ a.computed_area_sqm ? `${mq(a.computed_area_sqm)} m²` : '—' }}</td>
                                                <td class="py-2 text-right">
                                                    <button
                                                        v-if="canDeleteAree"
                                                        class="text-xs text-red-500 hover:underline"
                                                        :title="a.assets_count > 0 ? 'Si può eliminare solo un\'area senza elementi censiti' : 'Elimina l\'area'"
                                                        data-test="elimina-area"
                                                        @click="eliminaArea(a)"
                                                    >elimina</button>
                                                </td>
                                            </tr>
                                            <tr v-if="! areeInCaricamento && ! aree.length">
                                                <td colspan="5" class="py-6 text-center text-sm text-gray-400">Nessun'area: si disegnano dalla Mappa.</td>
                                            </tr>
                                            <tr v-if="areeInCaricamento">
                                                <td colspan="5" class="py-6 text-center text-sm text-gray-400">Caricamento…</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <p class="mt-2 text-xs text-gray-500">
                                    Un'area con elementi non si elimina: prima si spostano (dalla scheda dell'elemento, campo Area) o si eliminano.
                                    Le aree si disegnano dalla <a href="/mappa" class="text-green-700 hover:underline">Mappa</a>.
                                </p>
                            </div>

                            <!-- Anteprima geografica -->
                            <div v-if="aree.some((a) => a.geom_geojson)" class="w-full shrink-0 lg:w-72">
                                <div ref="anteprimaEl" class="h-56 overflow-hidden rounded-lg border border-gray-200" data-test="anteprima-aree"></div>
                                <p class="mt-1 text-xs text-gray-500">Le aree della località, com'è sulla Mappa.</p>
                            </div>
                        </div>

                        <!-- Scheda località -->
                        <div v-else-if="schedaLocalitaAttiva === 'scheda'" class="space-y-4 p-5">
                            <p v-if="schedaInCaricamento" class="text-sm text-gray-400">Caricamento…</p>
                            <template v-else-if="scheda">
                                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    <div class="rounded-lg border border-gray-200 px-3 py-2">
                                        <div class="text-lg font-semibold">{{ mq(scheda.superfici.totale_mq) }} m²</div>
                                        <div class="text-xs text-gray-500">Superficie totale</div>
                                    </div>
                                    <div class="rounded-lg border border-gray-200 px-3 py-2">
                                        <div class="text-lg font-semibold">{{ mq(scheda.superfici.gestita_mq) }} m²</div>
                                        <div class="text-xs text-gray-500">Superficie gestita</div>
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
                            </template>
                        </div>

                        <!-- Documenti -->
                        <div v-else-if="schedaLocalitaAttiva === 'documenti'" class="p-5">
                            <ul v-if="scheda?.documenti.length" class="divide-y divide-gray-100 text-sm">
                                <li v-for="d in scheda.documenti" :key="d.id" class="flex items-center justify-between gap-2 py-1.5">
                                    <a :href="`/api/v1/documents/${d.id}/file`" target="_blank" class="truncate text-green-700 hover:underline">{{ d.title }}</a>
                                    <button v-if="canManage" class="text-xs text-red-500 hover:underline" @click="eliminaDocumentoLocalita(d)">elimina</button>
                                </li>
                            </ul>
                            <p v-else class="text-sm text-gray-400">Nessun documento allegato.</p>
                            <input
                                v-if="canManage"
                                ref="documentoLocalitaInput"
                                type="file"
                                accept="application/pdf"
                                data-test="scheda-documento"
                                class="mt-3 w-full text-xs"
                                @change="caricaDocumentoLocalita"
                            >
                            <p v-if="canManage" class="mt-1 text-xs text-gray-400">Solo PDF, per esempio il piano di gestione.</p>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
