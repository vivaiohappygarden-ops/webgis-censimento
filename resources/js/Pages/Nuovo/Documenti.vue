<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import AvvisoErrore from '@/Components/AvvisoErrore.vue';
import Icona from '@/Components/Nuovo/Icona.vue';
import ScegliCommittente from '@/Components/ScegliCommittente.vue';
import TestataSezione from '@/Components/Nuovo/TestataSezione.vue';
import { SCHEDE_DOCUMENTI } from '@/nuovo/sezioni';
import { usaCaricamento } from '@/caricamento';
import { fetchPdf } from '@/pdf';
import { BOTTONE, BOTTONE_PICCOLO, BOTTONE_SECONDARIO, CARTA, CHIP, ETICHETTA, plurale } from '@/nuovo/stile';

/*
 * Documenti (bozza A, schermata 08): tutto cio' che si stampa, si firma o si
 * consegna in un elenco solo (perizie, verbali, preventivi, SAL,
 * esportazioni), con le schede per tipo e le scorciatoie "da validare" e "con
 * marca temporale"; a fianco i documenti da produrre (bilancio arboreo,
 * relazione annuale, registro fitosanitari) e la carta delle marche temporali:
 * stato del servizio, credenziali dell'organizzazione (chi gestisce gli
 * utenti), e da ogni riga chiusa il pulsante che appone la marca. Il PDF
 * marcato che si scarica e' la copia conservata, non una ristampa.
 */
const page = usePage();
const permessi = computed(() => page.props.auth?.user?.permissions ?? []);
const can = (p) => permessi.value.includes(p);

const { avviso, riprovaInCorso, carica, riprova } = usaCaricamento();
const righe = ref([]);
const conteggi = ref(null);
const anni = ref([]);
const clients = ref([]);
const caricamento = ref(false);
const parametri = new URLSearchParams(window.location.search);
const filtri = reactive({ tipo: parametri.get('tipo') ?? 'tutti', q: '', clientId: '', anno: '', daValidare: parametri.get('stato') === 'da_validare', marcati: parametri.get('stato') === 'marcati' });

const SCHEDE_TIPO = [
    ['tutti', 'Tutti', null], ['perizia', 'Perizie', 'perizia'], ['verbale', 'Verbali', 'verbale'],
    ['contabili', 'Preventivi e SAL', 'preventivo,sal'], ['esportazione', 'Esportazioni', 'esportazione'], ['marca', 'Registri marcati', 'marca'],
];
const ETICHETTA_TIPO = { perizia: 'Perizia', verbale: 'Verbale', preventivo: 'Preventivo', sal: 'SAL', esportazione: 'Esportazione', marca: 'Registro marcato' };
const conteggioScheda = (chiave) => {
    if (! conteggi.value) return null;
    if (chiave === 'tutti') return conteggi.value.tutti;
    if (chiave === 'contabili') return (conteggi.value.preventivo ?? 0) + (conteggi.value.sal ?? 0);

    return conteggi.value[chiave] ?? 0;
};

async function caricaDocumenti() {
    caricamento.value = true;
    try {
        const scheda = SCHEDE_TIPO.find(([k]) => k === filtri.tipo);
        const { data } = await axios.get('/api/v1/documenti', { params: {
            tipo: scheda?.[2] ?? undefined, q: filtri.q || undefined, client_id: filtri.clientId || undefined,
            anno: filtri.anno || undefined, stato: filtri.daValidare ? 'da_validare' : (filtri.marcati ? 'marcati' : undefined),
        } });
        righe.value = data.data;
        conteggi.value = data.conteggi;
        anni.value = data.anni;
    } finally {
        caricamento.value = false;
    }
}

async function caricaCommittenti() {
    if (! can('clients.view')) return;
    const tutti = [];
    for (let p = 1; p <= 20; p++) {
        const { data } = await axios.get('/api/v1/clients', { params: { per_page: 100, page: p } });
        tutti.push(...data.data);
        if (! data.next_page_url) break;
    }
    clients.value = tutti;
}

let attesa = null;
watch(() => [filtri.tipo, filtri.q, filtri.clientId, filtri.anno, filtri.daValidare, filtri.marcati], () => {
    clearTimeout(attesa);
    attesa = setTimeout(() => carica(caricaDocumenti), 300);
});
onMounted(() => carica(() => Promise.all([caricaDocumenti(), caricaCommittenti(), caricaMarche()])));

// --- Marche temporali ---------------------------------------------------------
const marche = reactive({ stato: null, righe: [], busy: '', errore: '', esito: '', verifica: null, verificaBusy: false });
// Si puo' apporre una marca: servono le credenziali e un pacchetto non esaurito
const marcheDisponibili = computed(() => Boolean(marche.stato?.attiva) && ! marche.stato?.esaurito);
async function caricaMarche() {
    const { data } = await axios.get('/api/v1/documenti/marche');
    marche.stato = data.stato;
    marche.righe = data.data;
}

function messaggioErrore(err, azione) {
    const corpo = err?.response?.data;
    const motivo = corpo ? (Object.values(corpo.errors ?? {})[0]?.[0] ?? corpo.message) : null;
    if (motivo) return motivo;

    return err?.response ? `${azione} non riuscita (errore ${err.response.status}): aggiorna la pagina e riprova.` : `${azione} non riuscita: problema di rete.`;
}

/** Appone la marca a un documento chiuso (riga dell'elenco) o a un registro da produrre. */
async function applicaMarca(chiave, tipo, id = null, parametri = null, titolo = '') {
    if (! window.confirm(`Apporre la marca temporale a "${titolo}"?\n\nSi consuma una marca del lotto: il PDF prodotto ora viene conservato con la data certa della TSA.`)) return;
    marche.busy = chiave;
    marche.errore = '';
    marche.esito = '';
    try {
        const { data } = await axios.post('/api/v1/documenti/marche', { tipo, id: id ?? undefined, parametri: parametri ?? undefined });
        marche.esito = `Marca apposta: ${data.data.generato_il_locale} (ora italiana), n. ${data.data.seriale ?? '—'}, ${data.data.tsa ?? data.data.servizio}.`;
        await Promise.all([caricaDocumenti(), caricaMarche()]);
    } catch (err) {
        marche.errore = messaggioErrore(err, 'Apposizione della marca');
    } finally {
        marche.busy = '';
    }
}

async function verificaMarca(marca) {
    marche.verificaBusy = true;
    marche.verifica = null;
    marche.errore = '';
    try {
        const { data } = await axios.get(marca.verifica);
        marche.verifica = { ...data.data, titolo: marca.titolo };
    } catch (err) {
        marche.errore = messaggioErrore(err, 'Verifica della marca');
    } finally {
        marche.verificaBusy = false;
    }
}

/** Scarica un file dell'API (il gettone .tsr) con il suo nome, passando dalla sessione. */
async function scaricaFile(url, nome) {
    marche.errore = '';
    try {
        const { data } = await axios.get(url, { responseType: 'blob' });
        const blobUrl = URL.createObjectURL(data);
        const a = document.createElement('a');
        a.href = blobUrl;
        a.download = nome;
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(blobUrl), 60000);
    } catch (err) {
        marche.errore = messaggioErrore(err, 'Scarico del gettone');
    }
}

const nomeGettone = (marca) => `${(marca.nome_file ?? 'documento').replace(/\.pdf$/i, '')}.tsr`;

// Credenziali dell'organizzazione (chi gestisce gli utenti)
const credenziali = reactive({ aperte: false, caricate: false, busy: false, errore: '', esito: '', url: '', utente: '', password: '', policy: '', quota: '', haPassword: false, quotaPredefinita: 10 });
async function apriCredenziali() {
    credenziali.aperte = ! credenziali.aperte;
    if (! credenziali.aperte || credenziali.caricate) return;
    try {
        const { data } = await axios.get('/api/v1/documenti/marche/configurazione');
        credenziali.url = data.data.url ?? '';
        credenziali.utente = data.data.utente ?? '';
        credenziali.policy = data.data.policy ?? '';
        credenziali.quota = data.data.quota_giorno ?? '';
        credenziali.haPassword = data.data.ha_password;
        credenziali.quotaPredefinita = data.data.quota_predefinita;
        credenziali.caricate = true;
    } catch (err) {
        credenziali.errore = messaggioErrore(err, 'Lettura delle credenziali');
    }
}
async function salvaCredenziali() {
    credenziali.busy = true;
    credenziali.errore = '';
    credenziali.esito = '';
    try {
        const { data } = await axios.put('/api/v1/documenti/marche/configurazione', {
            url: credenziali.url, utente: credenziali.utente, password: credenziali.password || undefined,
            policy: credenziali.policy || null, quota_giorno: credenziali.quota === '' ? null : Number(credenziali.quota),
        });
        credenziali.password = '';
        credenziali.haPassword = data.data.ha_password;
        credenziali.esito = 'Credenziali salvate: le marche di questa organizzazione partono con questo account.';
        marche.stato = data.data.stato;
    } catch (err) {
        credenziali.errore = messaggioErrore(err, 'Salvataggio delle credenziali');
    } finally {
        credenziali.busy = false;
    }
}
async function togliCredenziali() {
    if (! window.confirm('Togliere le credenziali di questa organizzazione? Le marche restano spente finché non se ne inseriscono di nuove; quelle già apposte restano.')) return;
    credenziali.busy = true;
    credenziali.errore = '';
    try {
        const { data } = await axios.delete('/api/v1/documenti/marche/configurazione');
        credenziali.utente = '';
        credenziali.password = '';
        credenziali.policy = '';
        credenziali.quota = '';
        credenziali.haPassword = false;
        credenziali.esito = 'Credenziali tolte.';
        marche.stato = data.data.stato;
    } catch (err) {
        credenziali.errore = messaggioErrore(err, 'Rimozione delle credenziali');
    } finally {
        credenziali.busy = false;
    }
}
const CAMPO = 'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm min-h-11 md:min-h-[38px] focus:border-green-700 focus:outline-none focus:ring-1 focus:ring-green-700';

function formatData(v) {
    if (! v) return '—';
    const [a, m, g] = String(v).slice(0, 10).split('-');

    return `${g}/${m}/${a}`;
}

// --- PDF a richiesta ---------------------------------------------------------
const stampa = reactive({ busy: '', errore: '' });
async function scarica(chiave, url) {
    stampa.busy = chiave;
    stampa.errore = '';
    const { error } = await fetchPdf(url);
    if (error) stampa.errore = error;
    stampa.busy = '';
}

const annoCorrente = new Date().getFullYear();
const produci = reactive({ bilancioClient: '', bilancioAnno: annoCorrente, relazioneClient: '', relazioneAnno: annoCorrente, fitoAnno: annoCorrente });
const urlBilancio = computed(() => `/api/v1/vta/bilancio/pdf?from=${produci.bilancioAnno}-01-01&to=${produci.bilancioAnno}-12-31${produci.bilancioClient ? `&client_id=${produci.bilancioClient}` : ''}`);
const urlRelazione = computed(() => (produci.relazioneClient ? `/api/v1/reports/relazione-annuale/pdf?client_id=${produci.relazioneClient}&anno=${produci.relazioneAnno}` : null));
const urlFito = computed(() => `/api/v1/phyto-treatments/register-pdf?year=${produci.fitoAnno}`);
const anniProducibili = computed(() => Array.from({ length: 6 }, (_, i) => annoCorrente - i));
</script>

<template>
    <Head title="Documenti" />

    <AppLayout>
        <div class="mx-auto flex max-w-[1640px] flex-col gap-4 p-4 md:p-6 lg:px-7">
            <TestataSezione titolo="Documenti" attiva="documenti" :schede="SCHEDE_DOCUMENTI">
                <details class="relative">
                    <summary :class="BOTTONE" class="cursor-pointer list-none"><Icona nome="nuovo" :size="16" />Nuovo documento</summary>
                    <div :class="CARTA" class="absolute right-0 z-20 mt-1 w-72 p-2 text-sm shadow-lg" data-test="menu-nuovo-documento">
                        <Link v-if="can('assets.view')" href="/vta" class="flex min-h-11 items-center rounded-lg px-3 hover:bg-gray-50 md:min-h-9">Perizia di stabilità (dallo scadenzario VTA)</Link>
                        <Link v-if="can('works.view')" href="/lavori?vista=preventivi" class="flex min-h-11 items-center rounded-lg px-3 hover:bg-gray-50 md:min-h-9">Preventivo</Link>
                        <Link v-if="can('works.manage')" href="/lavori?vista=sal" class="flex min-h-11 items-center rounded-lg px-3 hover:bg-gray-50 md:min-h-9">Stato di avanzamento (SAL)</Link>
                        <Link v-if="can('works.view')" href="/ispezioni" class="flex min-h-11 items-center rounded-lg px-3 hover:bg-gray-50 md:min-h-9">Verbale di ispezione</Link>
                        <Link v-if="can('works.view')" href="/fitosanitari" class="flex min-h-11 items-center rounded-lg px-3 hover:bg-gray-50 md:min-h-9">Trattamento fitosanitario (registro)</Link>
                        <Link v-if="can('works.view')" href="/patentini" class="flex min-h-11 items-center rounded-lg px-3 hover:bg-gray-50 md:min-h-9">Patentino o certificato</Link>
                    </div>
                </details>
                <Link v-if="can('assets.create')" href="/censimento?importa=1" :class="BOTTONE_SECONDARIO">Importa da file</Link>
                <details class="relative">
                    <summary :class="BOTTONE_SECONDARIO" class="cursor-pointer list-none">Altro</summary>
                    <div :class="CARTA" class="absolute right-0 z-20 mt-1 w-72 p-2 text-sm shadow-lg">
                        <Link v-if="can('assets.view')" href="/patrimonio" class="flex min-h-11 items-center rounded-lg px-3 hover:bg-gray-50 md:min-h-9">Esportazioni CSV, Excel e CAM (da Patrimonio)</Link>
                        <Link v-if="can('assets.view')" href="/vta" class="flex min-h-11 items-center rounded-lg px-3 hover:bg-gray-50 md:min-h-9">Registro delle valutazioni VTA</Link>
                        <Link v-if="can('works.view')" href="/statistiche" class="flex min-h-11 items-center rounded-lg px-3 hover:bg-gray-50 md:min-h-9">Statistiche</Link>
                    </div>
                </details>
            </TestataSezione>

            <AvvisoErrore :messaggio="avviso" :in-corso="riprovaInCorso" @riprova="riprova" />

            <div class="flex flex-col gap-2">
                <div class="flex flex-wrap gap-2 md:inline-flex md:gap-0 md:self-start md:overflow-hidden md:rounded-lg md:border md:border-gray-300 md:bg-white" role="group" aria-label="Tipo di documento">
                    <button
                        v-for="[chiave, etichetta] in SCHEDE_TIPO"
                        :key="chiave"
                        type="button"
                        class="inline-flex min-h-11 items-center rounded-lg border px-3.5 text-sm font-semibold transition md:min-h-9 md:rounded-none md:border-0 md:border-r md:border-gray-200 md:last:border-r-0"
                        :class="filtri.tipo === chiave ? 'border-green-700 bg-green-700 text-white' : 'border-gray-300 text-gray-700 hover:bg-gray-50'"
                        :aria-pressed="filtri.tipo === chiave"
                        :data-test="`documenti-tipo-${chiave}`"
                        @click="filtri.tipo = chiave"
                    >{{ etichetta }}<template v-if="conteggioScheda(chiave) !== null"> · {{ conteggioScheda(chiave) }}</template></button>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <label class="relative min-w-0 flex-1 basis-64"><span class="sr-only">Cerca</span><input v-model="filtri.q" type="search" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm placeholder:text-gray-400 focus:border-green-700 focus:outline-none focus:ring-1 focus:ring-green-700" placeholder="Cerca: titolo, codice, elemento, committente" data-test="documenti-ricerca"></label>
                    <ScegliCommittente v-if="can('clients.view')" v-model="filtri.clientId" class="w-full sm:w-56" :committenti="clients" tutti="Committente: tutti" />
                    <select v-model="filtri.anno" class="w-full rounded-lg border border-gray-300 bg-white px-2.5 py-2 text-sm sm:w-auto" aria-label="Anno" data-test="documenti-anno">
                        <option value="">Anno: tutti</option><option v-for="a in anni" :key="a" :value="a">{{ a }}</option>
                    </select>
                    <button
                        v-if="conteggi"
                        type="button"
                        class="inline-flex min-h-11 items-center gap-1.5 rounded-sm border px-3 text-[13px] font-semibold transition md:min-h-9"
                        :class="filtri.daValidare ? 'border-red-800 bg-red-800 text-white' : 'border-gray-300 bg-white text-gray-700 hover:border-red-300'"
                        :aria-pressed="filtri.daValidare"
                        data-test="documenti-da-validare"
                        @click="filtri.daValidare = ! filtri.daValidare"
                    >Perizie da validare <span :class="filtri.daValidare ? '' : 'text-red-800'">{{ conteggi.da_validare }}</span></button>
                    <button
                        v-if="conteggi"
                        type="button"
                        class="inline-flex min-h-11 items-center gap-1.5 rounded-sm border px-3 text-[13px] font-semibold transition md:min-h-9"
                        :class="filtri.marcati ? 'border-green-800 bg-green-800 text-white' : 'border-gray-300 bg-white text-gray-700 hover:border-green-300'"
                        :aria-pressed="filtri.marcati"
                        data-test="documenti-marcati"
                        @click="filtri.marcati = ! filtri.marcati"
                    >Con marca temporale <span :class="filtri.marcati ? '' : 'text-green-800'">{{ conteggi.marcati }}</span></button>
                </div>
            </div>

            <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_380px] lg:items-start">
                <section :class="CARTA" class="min-w-0" data-test="documenti-elenco">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead><tr class="border-b border-gray-200 text-left text-xs font-semibold uppercase tracking-wide text-gray-500"><th class="px-4 py-2.5">Tipo</th><th class="px-3 py-2.5">Documento</th><th class="px-3 py-2.5">Committente</th><th class="px-3 py-2.5">Data</th><th class="px-3 py-2.5">Stato</th><th class="px-3 py-2.5">Impronta</th><th class="px-3 py-2.5"></th></tr></thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="r in righe" :key="`${r.tipo}-${r.id}`" data-test="documenti-riga">
                                    <td class="whitespace-nowrap px-4 py-2.5"><span :class="CHIP.neutra">{{ ETICHETTA_TIPO[r.tipo] ?? r.tipo }}</span></td>
                                    <td class="min-w-[16rem] px-3 py-2.5 font-semibold text-gray-900">{{ r.titolo }}<div v-if="r.utente" class="text-xs font-normal text-gray-500">da {{ r.utente }}</div></td>
                                    <td class="px-3 py-2.5 text-gray-700">{{ r.committente ?? '—' }}</td>
                                    <td class="whitespace-nowrap px-3 py-2.5 text-gray-700">{{ formatData(r.data) }}</td>
                                    <td class="whitespace-nowrap px-3 py-2.5">
                                        <span :class="r.da_validare ? CHIP.attenzione : CHIP.neutra">{{ r.stato }}</span>
                                        <div v-if="r.marca" class="mt-1 text-xs text-gray-600" :title="`Marca n. ${r.marca.seriale ?? '—'} · ${r.marca.tsa ?? r.marca.servizio}`" data-test="documenti-marca">Marca del {{ r.marca.generato_il_locale }}</div>
                                    </td>
                                    <td class="px-3 py-2.5 font-mono text-xs text-gray-500" :title="r.impronta ?? ''">{{ r.impronta ? `SHA-256 ${r.impronta.slice(0, 10)}…` : '—' }}</td>
                                    <td class="whitespace-nowrap px-3 py-2.5 text-right">
                                        <Link v-if="r.href" :href="r.href" :class="BOTTONE_PICCOLO">Apri</Link>
                                        <button v-if="r.pdf && r.tipo !== 'marca'" type="button" :class="BOTTONE_PICCOLO" class="ml-1" :disabled="stampa.busy === `${r.tipo}-${r.id}`" @click="scarica(`${r.tipo}-${r.id}`, r.pdf)">PDF</button>
                                        <template v-if="r.marca">
                                            <button type="button" :class="BOTTONE_PICCOLO" class="ml-1" :disabled="stampa.busy === `marca-${r.marca.id}`" data-test="marca-pdf" @click="scarica(`marca-${r.marca.id}`, r.marca.pdf)">PDF marcato</button>
                                            <button type="button" :class="BOTTONE_PICCOLO" class="ml-1" data-test="marca-tsr" @click="scaricaFile(r.marca.tsr, nomeGettone(r.marca))">Gettone .tsr</button>
                                            <button type="button" :class="BOTTONE_PICCOLO" class="ml-1" :disabled="marche.verificaBusy" data-test="marca-verifica" @click="verificaMarca(r.marca)">Verifica</button>
                                        </template>
                                        <button v-else-if="r.marcabile && marcheDisponibili" type="button" :class="BOTTONE_PICCOLO" class="ml-1" :disabled="marche.busy === `${r.tipo}-${r.id}`" data-test="marca-applica" @click="applicaMarca(`${r.tipo}-${r.id}`, r.tipo, r.id, null, r.titolo)">Marca temporale</button>
                                    </td>
                                </tr>
                                <tr v-if="! righe.length && ! caricamento"><td colspan="7" class="px-4 py-8 text-center text-sm text-gray-500" data-test="documenti-vuoto">Nessun documento con questi filtri.</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-if="stampa.errore" class="border-t border-gray-100 px-4 py-2 text-sm text-red-700">{{ stampa.errore }}</p>
                    <p v-if="marche.errore" class="border-t border-gray-100 px-4 py-2 text-sm text-red-700" data-test="marca-errore">{{ marche.errore }}</p>
                    <p v-if="marche.esito" class="border-t border-gray-100 px-4 py-2 text-sm text-green-800" data-test="marca-esito">{{ marche.esito }}</p>
                    <div v-if="marche.verifica" class="border-t border-gray-100 px-4 py-3 text-sm" data-test="marca-verifica-esito">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <h3 class="font-bold text-gray-900">Verifica della marca · {{ marche.verifica.titolo }}</h3>
                            <button type="button" :class="BOTTONE_PICCOLO" @click="marche.verifica = null">Chiudi</button>
                        </div>
                        <dl class="mt-2 grid gap-x-6 gap-y-1 sm:grid-cols-[auto_1fr]">
                            <dt class="text-gray-500">Esito</dt><dd :class="marche.verifica.valida ? 'font-semibold text-green-800' : 'font-semibold text-red-800'">{{ marche.verifica.valida ? 'Documento e marca coerenti' : 'Qualcosa non torna: vedi sotto' }}</dd>
                            <dt class="text-gray-500">File conservato</dt><dd>{{ marche.verifica.file_presente ? (marche.verifica.impronta_coincide ? 'presente, impronta SHA-256 uguale a quella marcata' : 'presente ma diverso da quello marcato') : 'mancante sul server' }}</dd>
                            <dt class="text-gray-500">Gettone</dt><dd>{{ marche.verifica.marca_coerente ? 'riferito a questa impronta' : 'non riferito a questo file' }} · marca del {{ marche.verifica.generato_il_locale }} (ora italiana), n. {{ marche.verifica.seriale ?? '—' }}<template v-if="marche.verifica.tsa"> · {{ marche.verifica.tsa }}</template></dd>
                            <dt class="text-gray-500">Firma della TSA</dt><dd>{{ marche.verifica.firma === 'verificata' ? 'verificata' : (marche.verifica.firma === 'non_valida' ? 'NON valida' : 'non controllata qui') }}<span v-if="marche.verifica.firma_dettaglio" class="text-gray-500"> · {{ marche.verifica.firma_dettaglio }}</span></dd>
                            <dt v-if="marche.verifica.firmatario" class="text-gray-500">Certificato</dt><dd v-if="marche.verifica.firmatario">{{ marche.verifica.firmatario.nome }}<template v-if="marche.verifica.firmatario.organizzazione"> ({{ marche.verifica.firmatario.organizzazione }})</template><template v-if="marche.verifica.firmatario.emittente"> · emesso da {{ marche.verifica.firmatario.emittente }}</template><template v-if="marche.verifica.firmatario.scade_il"> · scade il {{ marche.verifica.firmatario.scade_il }}</template></dd>
                        </dl>
                    </div>
                    <p class="border-t border-gray-100 px-4 py-2.5 text-[13px] text-gray-500">{{ righe.length }} {{ plurale(righe.length, 'documento', 'documenti') }} · le perizie validate portano la propria impronta SHA-256; il PDF marcato è la copia conservata al momento della marca.</p>
                </section>

                <aside class="flex min-w-0 flex-col gap-4">
                    <section :class="CARTA" class="p-4" data-test="documenti-da-produrre">
                        <h2 class="text-[15px] font-semibold uppercase tracking-[0.05em] text-gray-900">Da produrre</h2>
                        <div v-if="can('assets.view')" class="mt-3">
                            <div :class="ETICHETTA">Bilancio arboreo (L. 10/2013)</div>
                            <div class="mt-1 flex flex-wrap items-center gap-2">
                                <select v-model="produci.bilancioClient" class="rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-sm" aria-label="Committente del bilancio"><option value="">Tutti i committenti</option><option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option></select>
                                <select v-model="produci.bilancioAnno" class="rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-sm" aria-label="Anno del bilancio"><option v-for="a in anniProducibili" :key="a" :value="a">{{ a }}</option></select>
                                <button type="button" :class="BOTTONE_PICCOLO" :disabled="stampa.busy === 'bilancio'" data-test="genera-bilancio" @click="scarica('bilancio', urlBilancio)">Genera</button>
                                <button v-if="marcheDisponibili" type="button" :class="BOTTONE_PICCOLO" :disabled="marche.busy === 'bilancio'" data-test="marca-bilancio" @click="applicaMarca('bilancio', 'bilancio_arboreo', null, { anno: produci.bilancioAnno, client_id: produci.bilancioClient || null }, `Bilancio arboreo ${produci.bilancioAnno}`)">Genera e marca</button>
                            </div>
                        </div>
                        <div v-if="can('works.view') && clients.length" class="mt-3">
                            <div :class="ETICHETTA">Relazione annuale del verde</div>
                            <div class="mt-1 flex flex-wrap items-center gap-2">
                                <select v-model="produci.relazioneClient" class="rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-sm" aria-label="Committente della relazione"><option value="">Committente…</option><option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option></select>
                                <select v-model="produci.relazioneAnno" class="rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-sm" aria-label="Anno della relazione"><option v-for="a in anniProducibili" :key="a" :value="a">{{ a }}</option></select>
                                <button type="button" :class="BOTTONE_PICCOLO" :disabled="! urlRelazione || stampa.busy === 'relazione'" @click="scarica('relazione', urlRelazione)">Genera</button>
                                <button v-if="marcheDisponibili" type="button" :class="BOTTONE_PICCOLO" :disabled="! urlRelazione || marche.busy === 'relazione'" data-test="marca-relazione" @click="applicaMarca('relazione', 'relazione_annuale', null, { anno: produci.relazioneAnno, client_id: produci.relazioneClient }, `Relazione annuale ${produci.relazioneAnno}`)">Genera e marca</button>
                            </div>
                        </div>
                        <div v-if="can('works.view')" class="mt-3">
                            <div :class="ETICHETTA">Registro dei trattamenti fitosanitari</div>
                            <div class="mt-1 flex flex-wrap items-center gap-2">
                                <select v-model="produci.fitoAnno" class="rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-sm" aria-label="Anno del registro"><option v-for="a in anniProducibili" :key="a" :value="a">{{ a }}</option></select>
                                <button type="button" :class="BOTTONE_PICCOLO" :disabled="stampa.busy === 'fito'" @click="scarica('fito', urlFito)">Stampa</button>
                                <button v-if="marcheDisponibili" type="button" :class="BOTTONE_PICCOLO" :disabled="marche.busy === 'fito'" data-test="marca-fito" @click="applicaMarca('fito', 'registro_fitosanitari', null, { anno: produci.fitoAnno }, `Registro dei trattamenti fitosanitari ${produci.fitoAnno}`)">Stampa e marca</button>
                            </div>
                        </div>
                        <p class="mt-3 text-[13px] text-gray-500">Sopra la firma di ogni documento c'è la riga "Luogo, data": il luogo si imposta una volta sola in Impostazioni.</p>
                    </section>

                    <section id="marche" :class="CARTA" class="p-4" data-test="documenti-marche">
                        <h2 class="text-[15px] font-semibold uppercase tracking-[0.05em] text-gray-900">Marche temporali</h2>
                        <p class="mt-1 text-sm text-gray-700">La marca temporale certifica che un documento esisteva, così com'è, a un istante certo: la rilascia un servizio accreditato (TSA) e vale come prova. Si appone alle perizie validate, ai verbali di ispezione chiusi e ai registri; il programma conserva il PDF esatto e il gettone della marca.</p>
                        <template v-if="marche.stato">
                            <dl v-if="marche.stato.attiva" class="mt-3 grid gap-x-4 gap-y-1 text-sm sm:grid-cols-[auto_1fr]" data-test="marche-stato">
                                <dt class="text-gray-500">Servizio</dt><dd class="text-gray-900">{{ marche.stato.servizio }} · account {{ marche.stato.utente }} <span class="text-gray-500">(credenziali di questa organizzazione)</span></dd>
                                <dt class="text-gray-500">Oggi</dt><dd class="text-gray-900">{{ marche.stato.usate_oggi }} {{ plurale(marche.stato.usate_oggi, 'marca apposta', 'marche apposte') }}<template v-if="marche.stato.quota_giorno > 0"> su {{ marche.stato.quota_giorno }} al giorno</template></dd>
                                <dt class="text-gray-500">Pacchetto</dt>
                                <dd class="text-gray-900" data-test="marche-pacchetto">
                                    <template v-if="marche.stato.pacchetto !== null">{{ marche.stato.totale }} {{ plurale(marche.stato.totale, 'usata', 'usate') }} su {{ marche.stato.pacchetto }}<template v-if="! marche.stato.esaurito">, ne {{ marche.stato.restanti === 1 ? 'resta 1' : `restano ${marche.stato.restanti}` }}</template><span v-else class="text-amber-900">: esaurito, per rinnovarlo rivolgetevi alla nostra assistenza</span></template>
                                    <template v-else>{{ marche.stato.totale }} {{ plurale(marche.stato.totale, 'marca apposta', 'marche apposte') }} in tutto<template v-if="marche.righe.length"> · l'ultima il {{ marche.righe[0].generato_il_locale }}</template></template>
                                </dd>
                                <dt class="text-gray-500">Firma</dt><dd class="text-gray-900">{{ marche.stato.verifica_firma ? 'la verifica controlla anche la firma della TSA (certificati presenti sul server)' : 'la verifica controlla impronta e gettone; la firma si controlla fuori dal programma con il file .tsr' }}</dd>
                            </dl>
                            <p v-else class="mt-3 text-sm text-amber-900" data-test="marche-spente">Le marche temporali di questa organizzazione non sono ancora attive. Per attivarle serve un pacchetto di marche, che potete richiedere alla nostra assistenza: ogni organizzazione ha il suo, e le marche di una non si consumano per un'altra.<template v-if="can('users.manage')"> Se avete già un vostro account di marcatura temporale, potete inserire qui le credenziali.</template><template v-else> Le credenziali le inserisce chi gestisce gli utenti.</template></p>
                        </template>
                        <div v-if="can('users.manage')" class="mt-3">
                            <button type="button" :class="BOTTONE_SECONDARIO" data-test="marche-credenziali" @click="apriCredenziali">{{ credenziali.aperte ? 'Chiudi le credenziali' : 'Credenziali di questa organizzazione' }}</button>
                            <p v-if="credenziali.aperte && ! credenziali.caricate && ! credenziali.errore" class="mt-2 text-sm text-gray-500">Lettura delle credenziali…</p>
                            <p v-if="credenziali.errore && ! credenziali.caricate" class="mt-2 text-sm text-red-700" data-test="marche-credenziali-errore">{{ credenziali.errore }}</p>
                            <form v-if="credenziali.aperte && credenziali.caricate" class="mt-3 flex flex-col gap-3" data-test="marche-modulo" @submit.prevent="salvaCredenziali">
                                <label class="block text-sm"><span :class="ETICHETTA">Indirizzo del servizio (https)</span><input v-model="credenziali.url" type="url" required :class="CAMPO" class="mt-1" data-test="marche-url"></label>
                                <label class="block text-sm"><span :class="ETICHETTA">Nome utente dell'account</span><input v-model="credenziali.utente" type="text" required autocomplete="off" :class="CAMPO" class="mt-1" data-test="marche-utente"></label>
                                <label class="block text-sm"><span :class="ETICHETTA">Password{{ credenziali.haPassword ? ' (vuota: resta quella salvata)' : '' }}</span><input v-model="credenziali.password" type="password" autocomplete="new-password" :required="! credenziali.haPassword" :class="CAMPO" class="mt-1" data-test="marche-password"></label>
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <label class="block text-sm"><span :class="ETICHETTA">Politica (OID, facoltativa)</span><input v-model="credenziali.policy" type="text" :class="CAMPO" class="mt-1" placeholder="es. 1.3.76.36.1.1.1"></label>
                                    <label class="block text-sm"><span :class="ETICHETTA">Marche al giorno (0 = senza tetto)</span><input v-model="credenziali.quota" type="number" min="0" max="10000" :class="CAMPO" class="mt-1" :placeholder="`di serie ${credenziali.quotaPredefinita}`"></label>
                                </div>
                                <p class="text-[13px] text-gray-500">La password si conserva cifrata e non si rilegge. Le credenziali valgono solo per questa organizzazione: le sue marche non si consumano per nessun'altra.</p>
                                <p v-if="credenziali.errore" class="text-sm text-red-700" data-test="marche-credenziali-errore">{{ credenziali.errore }}</p>
                                <p v-if="credenziali.esito" class="text-sm text-green-800" data-test="marche-credenziali-esito">{{ credenziali.esito }}</p>
                                <div class="flex flex-wrap gap-2">
                                    <button type="submit" :class="BOTTONE" :disabled="credenziali.busy" data-test="marche-salva">Salva</button>
                                    <button v-if="credenziali.haPassword" type="button" :class="BOTTONE_SECONDARIO" :disabled="credenziali.busy" data-test="marche-togli" @click="togliCredenziali">Togli le credenziali</button>
                                </div>
                            </form>
                        </div>
                    </section>

                    <section :class="CARTA" class="p-4">
                        <h2 class="text-[15px] font-semibold uppercase tracking-[0.05em] text-gray-900">Che cosa sta qui</h2>
                        <p class="mt-1 text-sm text-gray-700">Perizie emesse, verbali di ispezione chiusi, preventivi, SAL e le esportazioni già fatte, in ordine di data. I registri (fitosanitari, patentini) e le statistiche hanno la loro scheda qui sopra; le esportazioni nuove si fanno da Patrimonio.</p>
                    </section>
                </aside>
            </div>
        </div>
    </AppLayout>
</template>
