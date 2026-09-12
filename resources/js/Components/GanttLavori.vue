<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import axios from 'axios';
import ScegliCommittente from '@/Components/ScegliCommittente.vue';
import { avvisoCaricamento } from '@/avvisi';
import { barra, colonneMesi, fineFinestra, posizione, raggruppa, ymd } from '@/lavori/gantt';

/*
 * Il diagramma a barre dei lavori: il tempo in orizzontale, i lavori in
 * righe raggruppate come serve (squadra, area, committente, lavorazione).
 *
 * Nessuna libreria esterna: e' una griglia di div con le barre posizionate
 * in percentuale sulla finestra scelta. I conti stanno tutti nel modulo puro
 * lavori/gantt.js, con le loro prove (node --test): qui resta il disegno.
 *
 * I dati sono quelli dell'agenda (stessa API, stessa finestra from/to): il
 * Gantt non e' un altro elenco di lavori, e' lo stesso visto per periodo.
 */

const props = defineProps({
    teams: { type: Array, default: () => [] },
    committenti: { type: Array, default: () => [] },
});
const emit = defineEmits(['open']);

const STATUS_LABELS = {
    draft: 'Bozza', planned: 'Pianificato', assigned: 'Assegnato',
    in_progress: 'In corso', suspended: 'Sospeso', completed: 'Completato', cancelled: 'Annullato',
};
// Stesse tinte dei distintivi dell'agenda: chi passa da una vista all'altra
// deve riconoscere gli stati senza rileggere la legenda
const BAR_COLORS = {
    draft: 'bg-gray-300 text-gray-800',
    planned: 'bg-blue-400 text-blue-950',
    assigned: 'bg-indigo-400 text-indigo-950',
    in_progress: 'bg-amber-400 text-amber-950',
    suspended: 'bg-orange-400 text-orange-950',
    completed: 'bg-green-500 text-green-950',
    cancelled: 'bg-gray-200 text-gray-500',
};

const RAGGRUPPAMENTI = {
    team: 'Squadra',
    area: 'Area',
    client: 'Committente',
    work_type: 'Lavorazione',
};

const filtri = reactive({ mesi: 6, gruppo: 'team', team_id: '', client_id: '', status: '' });
// Primo giorno del mese corrente: una finestra che parte a meta' mese
// spezzerebbe le colonne senza motivo
const inizio = ref(new Date(new Date().getFullYear(), new Date().getMonth(), 1));

const righe = ref([]);
const caricamento = ref(false);
const errore = ref('');
const mancanti = ref(0);

const fine = computed(() => fineFinestra(inizio.value, filtri.mesi));

/** Le colonne del diagramma: un mese ciascuna, larga quanto i suoi giorni. */
const mesi = computed(() => colonneMesi(inizio.value, fine.value));

/** Dove cade la linea di oggi, o null se oggi sta fuori dalla finestra. */
const oggi = computed(() => posizione(new Date(), inizio.value, fine.value));

const periodo = computed(() => `${inizio.value.toLocaleDateString('it-IT', { month: 'long', year: 'numeric' })} → ${fine.value.toLocaleDateString('it-IT', { month: 'long', year: 'numeric' })}`);

/** La barra pronta da disegnare: stile in percentuale e testo del titolo. */
function barraDi(ordine) {
    const b = barra(ordine, inizio.value, fine.value);

    return {
        stile: { left: `${b.sinistra}%`, width: `${b.larghezza}%` },
        // Le date vere restano nel titolo: la barra e' un'approssimazione
        // grafica, il dato no
        titolo: `${ordine.code} · ${ordine.title}\n${data(ordine.planned_start)}`
            + `${ordine.planned_end ? ' → ' + data(ordine.planned_end) : ''}`
            + `\n${STATUS_LABELS[ordine.status] ?? ordine.status}`,
        tagliataPrima: b.tagliataPrima,
        tagliataDopo: b.tagliataDopo,
    };
}

const data = (v) => (v ? String(v).slice(0, 10).split('-').reverse().join('/') : '—');

const MAX_PAGINE = 20;

async function carica() {
    caricamento.value = true;
    errore.value = '';
    try {
        const raccolti = [];
        let pagina = 1;
        let ultima = 1;
        let totale = 0;
        do {
            const { data: res } = await axios.get('/api/v1/work-orders', {
                params: {
                    from: ymd(inizio.value),
                    to: ymd(fine.value),
                    per_page: 100,
                    page: pagina,
                    ...(filtri.team_id ? { team_id: filtri.team_id } : {}),
                    ...(filtri.client_id ? { client_id: filtri.client_id } : {}),
                    ...(filtri.status ? { status: filtri.status } : {}),
                },
            });
            raccolti.push(...res.data);
            totale = res.total;
            ultima = res.last_page;
            pagina += 1;
        } while (pagina <= ultima && pagina <= MAX_PAGINE);

        // Quello che non ci sta va dichiarato: un diagramma che mostra i
        // primi duemila lavori senza dirlo racconta un periodo piu' vuoto
        mancanti.value = Math.max(0, totale - raccolti.length);
        righe.value = raccolti;
    } catch (err) {
        righe.value = [];
        errore.value = avvisoCaricamento(err);
    } finally {
        caricamento.value = false;
    }
}

/** I lavori raggruppati come chiede il selettore, in ordine di data. */
const gruppi = computed(() => raggruppa(
    righe.value,
    filtri.gruppo,
    // La barra si calcola una volta per ordine: nel modello si usa il risultato
    (ordine) => ({ ...ordine, barra: barraDi(ordine) }),
));

function spostaMesi(n) {
    const d = new Date(inizio.value);
    d.setMonth(d.getMonth() + n);
    inizio.value = d;
}

function adOggi() {
    inizio.value = new Date(new Date().getFullYear(), new Date().getMonth(), 1);
}

watch(() => [filtri.mesi, filtri.team_id, filtri.client_id, filtri.status, inizio.value], carica);
onMounted(carica);

defineExpose({ carica });
</script>

<template>
    <div data-test="gantt">
        <div class="mb-3 flex flex-wrap items-center gap-2 text-sm">
            <div class="inline-flex overflow-hidden rounded-lg border border-gray-300">
                <button class="px-2.5 py-1.5 hover:bg-gray-50" data-test="gantt-indietro" @click="spostaMesi(-1)">←</button>
                <button class="border-l border-gray-300 px-3 py-1.5 font-medium hover:bg-gray-50" data-test="gantt-oggi" @click="adOggi">Oggi</button>
                <button class="border-l border-gray-300 px-2.5 py-1.5 hover:bg-gray-50" data-test="gantt-avanti" @click="spostaMesi(1)">→</button>
            </div>
            <span class="text-gray-600" data-test="gantt-periodo">{{ periodo }}</span>

            <label class="flex items-center gap-1.5">
                <span class="text-gray-600">Mesi</span>
                <select v-model="filtri.mesi" class="rounded-lg border border-gray-300 px-2 py-1.5 text-sm" data-test="gantt-mesi">
                    <option :value="3">3</option>
                    <option :value="6">6</option>
                    <option :value="12">12</option>
                </select>
            </label>

            <label class="flex items-center gap-1.5">
                <span class="text-gray-600">Raggruppa per</span>
                <select v-model="filtri.gruppo" class="rounded-lg border border-gray-300 px-2 py-1.5 text-sm" data-test="gantt-gruppo">
                    <option v-for="(etichetta, chiave) in RAGGRUPPAMENTI" :key="chiave" :value="chiave">{{ etichetta }}</option>
                </select>
            </label>

            <select v-model="filtri.team_id" class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm sm:w-auto" data-test="gantt-squadra">
                <option value="">Tutte le squadre</option>
                <option v-for="t in teams" :key="t.id" :value="t.id">{{ t.name }}</option>
            </select>

            <ScegliCommittente
                v-model="filtri.client_id"
                class="w-full sm:w-64"
                :committenti="props.committenti"
                tutti="Tutti i committenti"
                data-test="gantt-committente"
            />

            <select v-model="filtri.status" class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm sm:w-auto" data-test="gantt-stato">
                <option value="">Tutti gli stati</option>
                <option v-for="(etichetta, chiave) in STATUS_LABELS" :key="chiave" :value="chiave">{{ etichetta }}</option>
            </select>

            <span v-if="caricamento" class="text-gray-400">Caricamento…</span>
        </div>

        <p v-if="errore" class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" data-test="gantt-errore">
            {{ errore }}
            <button class="ml-1 font-medium underline" @click="carica">Riprova</button>
        </p>

        <p v-if="mancanti" class="mb-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900" data-test="gantt-mancanti">
            Nel periodo ci sono altri {{ mancanti }} lavori che non stanno nel diagramma: restringi il periodo o usa i filtri.
        </p>

        <!-- Il diagramma sta in un contenitore che scorre: sul telefono si
             legge scorrendo, invece di comprimere i mesi fino a sparire -->
        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
            <div class="min-w-[680px]">
                <div class="flex border-b border-gray-100 bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                    <div class="w-44 shrink-0 px-3 py-2 font-medium">{{ RAGGRUPPAMENTI[filtri.gruppo] }}</div>
                    <div class="relative flex flex-1">
                        <div
                            v-for="m in mesi"
                            :key="m.chiave"
                            class="border-l border-gray-200 px-2 py-2 text-center font-medium"
                            :style="{ width: m.larghezza + '%' }"
                        >{{ m.etichetta }}</div>
                    </div>
                </div>

                <div v-if="! gruppi.length && ! caricamento" class="px-4 py-6 text-sm text-gray-400" data-test="gantt-vuoto">
                    Nessun lavoro programmato in questo periodo.
                </div>

                <div v-for="g in gruppi" :key="g.nome" class="border-b border-gray-100 last:border-0" data-test="gantt-gruppo-riga">
                    <div class="flex">
                        <div class="w-44 shrink-0 border-r border-gray-100 px-3 py-2">
                            <p class="truncate text-sm font-medium" :title="g.nome">{{ g.nome }}</p>
                            <p class="text-xs text-gray-400">{{ g.ordini.length }} {{ g.ordini.length === 1 ? 'lavoro' : 'lavori' }}</p>
                        </div>
                        <div class="relative flex-1 py-1.5">
                            <!-- Le linee dei mesi sotto le barre -->
                            <div class="pointer-events-none absolute inset-0 flex">
                                <div
                                    v-for="m in mesi"
                                    :key="m.chiave"
                                    class="border-l border-gray-100"
                                    :style="{ width: m.larghezza + '%' }"
                                ></div>
                            </div>
                            <div
                                v-if="oggi !== null"
                                class="pointer-events-none absolute inset-y-0 w-px bg-red-400"
                                :style="{ left: oggi + '%' }"
                                data-test="gantt-oggi-linea"
                            ></div>

                            <div v-for="o in g.ordini" :key="o.id" class="relative h-6">
                                <button
                                    type="button"
                                    class="absolute top-0.5 h-5 overflow-hidden rounded px-1.5 text-left text-[11px] leading-5 hover:ring-2 hover:ring-green-700"
                                    :class="BAR_COLORS[o.status] ?? 'bg-gray-300'"
                                    :style="o.barra.stile"
                                    :title="o.barra.titolo"
                                    data-test="gantt-barra"
                                    @click="emit('open', o)"
                                >
                                    <span class="whitespace-nowrap">{{ o.barra.tagliataPrima ? '←' : '' }}{{ o.code }} {{ o.title }}{{ o.barra.tagliataDopo ? '→' : '' }}</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-2 flex flex-wrap items-center gap-3 text-xs text-gray-500">
            <span v-for="(etichetta, chiave) in STATUS_LABELS" :key="chiave" class="flex items-center gap-1">
                <span class="inline-block h-3 w-3 rounded" :class="BAR_COLORS[chiave]"></span>{{ etichetta }}
            </span>
            <span class="flex items-center gap-1"><span class="inline-block h-3 w-px bg-red-400"></span>oggi</span>
        </div>
        <p class="mt-1 text-xs text-gray-400">
            Un lavoro senza data di fine prevista occupa il solo giorno di inizio, come in agenda.
            Le frecce sulla barra dicono che il lavoro comincia prima o finisce dopo il periodo mostrato.
        </p>
    </div>
</template>
