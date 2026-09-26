<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import AvvisoErrore from '@/Components/AvvisoErrore.vue';
import ScegliCommittente from '@/Components/ScegliCommittente.vue';
import TestataSezione from '@/Components/Nuovo/TestataSezione.vue';
import { SCHEDE_DOCUMENTI } from '@/nuovo/sezioni';
import { usaCaricamento } from '@/caricamento';
import { fetchPdf } from '@/pdf';
import { BOTTONE, BOTTONE_PICCOLO, BOTTONE_SECONDARIO, CARTA, CHIP, ETICHETTA, plurale } from '@/nuovo/stile';

/*
 * Documenti (bozza A, schermata 08): tutto cio' che si stampa, si firma o si
 * consegna in un elenco solo (perizie, verbali, preventivi, SAL,
 * esportazioni), con le schede per tipo e la scorciatoia "da validare"; a
 * fianco i documenti da produrre (bilancio arboreo, relazione annuale,
 * registro fitosanitari) e una nota onesta su impronte e marche temporali.
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
const filtri = reactive({ tipo: parametri.get('tipo') ?? 'tutti', q: '', clientId: '', anno: '', daValidare: parametri.get('stato') === 'da_validare' });

const SCHEDE_TIPO = [
    ['tutti', 'Tutti', null], ['perizia', 'Perizie', 'perizia'], ['verbale', 'Verbali', 'verbale'],
    ['contabili', 'Preventivi e SAL', 'preventivo,sal'], ['esportazione', 'Esportazioni', 'esportazione'],
];
const ETICHETTA_TIPO = { perizia: 'Perizia', verbale: 'Verbale', preventivo: 'Preventivo', sal: 'SAL', esportazione: 'Esportazione' };
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
            anno: filtri.anno || undefined, stato: filtri.daValidare ? 'da_validare' : undefined,
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
watch(() => [filtri.tipo, filtri.q, filtri.clientId, filtri.anno, filtri.daValidare], () => {
    clearTimeout(attesa);
    attesa = setTimeout(() => carica(caricaDocumenti), 300);
});
onMounted(() => carica(() => Promise.all([caricaDocumenti(), caricaCommittenti()])));

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
                    <summary :class="BOTTONE" class="cursor-pointer list-none">Nuovo documento</summary>
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
                        class="inline-flex min-h-9 items-center gap-1.5 rounded-full border px-3 text-[13px] font-semibold transition"
                        :class="filtri.daValidare ? 'border-red-800 bg-red-800 text-white' : 'border-gray-300 bg-white text-gray-700 hover:border-red-300'"
                        :aria-pressed="filtri.daValidare"
                        data-test="documenti-da-validare"
                        @click="filtri.daValidare = ! filtri.daValidare"
                    >Perizie da validare <span :class="filtri.daValidare ? '' : 'text-red-800'">{{ conteggi.da_validare }}</span></button>
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
                                    <td class="whitespace-nowrap px-3 py-2.5"><span :class="r.da_validare ? CHIP.attenzione : CHIP.neutra">{{ r.stato }}</span></td>
                                    <td class="px-3 py-2.5 font-mono text-xs text-gray-500" :title="r.impronta ?? ''">{{ r.impronta ? `SHA-256 ${r.impronta.slice(0, 10)}…` : '—' }}</td>
                                    <td class="whitespace-nowrap px-3 py-2.5 text-right">
                                        <Link v-if="r.href" :href="r.href" :class="BOTTONE_PICCOLO">Apri</Link>
                                        <button v-if="r.pdf" type="button" :class="BOTTONE_PICCOLO" class="ml-1" :disabled="stampa.busy === `${r.tipo}-${r.id}`" @click="scarica(`${r.tipo}-${r.id}`, r.pdf)">PDF</button>
                                    </td>
                                </tr>
                                <tr v-if="! righe.length && ! caricamento"><td colspan="7" class="px-4 py-8 text-center text-sm text-gray-500" data-test="documenti-vuoto">Nessun documento con questi filtri.</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-if="stampa.errore" class="border-t border-gray-100 px-4 py-2 text-sm text-red-700">{{ stampa.errore }}</p>
                    <p class="border-t border-gray-100 px-4 py-2.5 text-[13px] text-gray-500">{{ righe.length }} {{ plurale(righe.length, 'documento', 'documenti') }} · le perizie validate portano la propria impronta SHA-256.</p>
                </section>

                <aside class="flex min-w-0 flex-col gap-4">
                    <section :class="CARTA" class="p-4" data-test="documenti-da-produrre">
                        <h2 class="text-base font-bold text-gray-900">Da produrre</h2>
                        <div v-if="can('assets.view')" class="mt-3">
                            <div :class="ETICHETTA">Bilancio arboreo (L. 10/2013)</div>
                            <div class="mt-1 flex flex-wrap items-center gap-2">
                                <select v-model="produci.bilancioClient" class="rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-sm" aria-label="Committente del bilancio"><option value="">Tutti i committenti</option><option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option></select>
                                <select v-model="produci.bilancioAnno" class="rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-sm" aria-label="Anno del bilancio"><option v-for="a in anniProducibili" :key="a" :value="a">{{ a }}</option></select>
                                <button type="button" :class="BOTTONE_PICCOLO" :disabled="stampa.busy === 'bilancio'" data-test="genera-bilancio" @click="scarica('bilancio', urlBilancio)">Genera</button>
                            </div>
                        </div>
                        <div v-if="can('works.view') && clients.length" class="mt-3">
                            <div :class="ETICHETTA">Relazione annuale del verde</div>
                            <div class="mt-1 flex flex-wrap items-center gap-2">
                                <select v-model="produci.relazioneClient" class="rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-sm" aria-label="Committente della relazione"><option value="">Committente…</option><option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option></select>
                                <select v-model="produci.relazioneAnno" class="rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-sm" aria-label="Anno della relazione"><option v-for="a in anniProducibili" :key="a" :value="a">{{ a }}</option></select>
                                <button type="button" :class="BOTTONE_PICCOLO" :disabled="! urlRelazione || stampa.busy === 'relazione'" @click="scarica('relazione', urlRelazione)">Genera</button>
                            </div>
                        </div>
                        <div v-if="can('works.view')" class="mt-3">
                            <div :class="ETICHETTA">Registro dei trattamenti fitosanitari</div>
                            <div class="mt-1 flex flex-wrap items-center gap-2">
                                <select v-model="produci.fitoAnno" class="rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-sm" aria-label="Anno del registro"><option v-for="a in anniProducibili" :key="a" :value="a">{{ a }}</option></select>
                                <button type="button" :class="BOTTONE_PICCOLO" :disabled="stampa.busy === 'fito'" @click="scarica('fito', urlFito)">Stampa</button>
                            </div>
                        </div>
                        <p class="mt-3 text-[13px] text-gray-500">Sopra la firma di ogni documento c'è la riga "Luogo, data": il luogo si imposta una volta sola in Impostazioni.</p>
                    </section>

                    <section :class="CARTA" class="p-4" data-test="documenti-impronte">
                        <h2 class="text-base font-bold text-gray-900">Impronte e marche temporali</h2>
                        <p class="mt-1 text-sm text-gray-700">Una perizia validata porta con sé la propria impronta SHA-256: ristampata, viene identica. Le marche temporali, che certificano a una data certa che un documento esisteva così com'è, non sono ancora attive: quando lo saranno si apporranno da qui ai registri chiusi e alle perizie validate.</p>
                    </section>

                    <section :class="CARTA" class="p-4">
                        <h2 class="text-base font-bold text-gray-900">Che cosa sta qui</h2>
                        <p class="mt-1 text-sm text-gray-700">Perizie emesse, verbali di ispezione chiusi, preventivi, SAL e le esportazioni già fatte, in ordine di data. I registri (fitosanitari, patentini) e le statistiche hanno la loro scheda qui sopra; le esportazioni nuove si fanno da Patrimonio.</p>
                    </section>
                </aside>
            </div>
        </div>
    </AppLayout>
</template>
