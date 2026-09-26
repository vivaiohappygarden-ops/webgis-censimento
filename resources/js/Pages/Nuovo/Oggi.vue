<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import AvvisoErrore from '@/Components/AvvisoErrore.vue';
import { usaCaricamento } from '@/caricamento';
import { BOTTONE, BOTTONE_PICCOLO, BOTTONE_SECONDARIO, CARTA, CHIP, plurale } from '@/nuovo/stile';

/*
 * Oggi, veste nuova (bozza A, 26/09/2026): una sola lista di cose da fare in
 * ordine di urgenza, con il pulsante giusto su ogni riga; accanto quello che
 * e' arrivato dal campo, i documenti da chiudere e i portali pubblici. Niente
 * riquadri di numeri: i conteggi stanno in una frase e nei filtri della lista.
 */
const page = usePage();
const can = (permesso) => (page.props.auth?.user?.permissions ?? []).includes(permesso);

const dati = ref(null);
const primoCaricamento = ref(true);
const { avviso, riprovaInCorso, carica, riprova } = usaCaricamento();
const famiglia = ref('tutte');

const TIPI = {
    lavoro: 'Lavoro', ispezione: 'Ispezione', segnalazione: 'Segnalazione', non_conformita: 'Non conformità',
    certificato: 'Patentino', vta: 'VTA', irrigazione: 'Irrigazione',
};
const FAMIGLIE = [['tutte', 'Tutte'], ['lavori', 'Lavori'], ['controlli', 'Controlli'], ['segnalazioni', 'Segnalazioni'], ['altro', 'Altro']];
const URGENZA = { ritardo: CHIP.errore, oggi: CHIP.ok, presto: CHIP.attenzione, programma: CHIP.neutra };
const URGENZA_TESTO = { ritardo: 'in ritardo', oggi: 'in corso', presto: 'in scadenza', programma: 'in programma' };

async function caricaOggi() {
    await carica(async () => {
        const { data } = await axios.get('/api/v1/oggi');
        dati.value = data.data;
    });
    primoCaricamento.value = false;
}

onMounted(caricaOggi);

const voci = computed(() => dati.value?.voci ?? []);
const perFamiglia = computed(() => {
    const n = { tutte: voci.value.length, lavori: 0, controlli: 0, segnalazioni: 0, altro: 0 };
    voci.value.forEach((v) => { n[v.famiglia] += 1; });

    return n;
});
const filtri = computed(() => FAMIGLIE.filter(([chiave]) => chiave === 'tutte' || perFamiglia.value[chiave] > 0));
const vociFiltrate = computed(() => (famiglia.value === 'tutte' ? voci.value : voci.value.filter((v) => v.famiglia === famiglia.value)));

// La frase in testa: i numeri veri di ogni sezione (non le righe elencate,
// che hanno un tetto), scritti come li direbbe un collega
const frase = computed(() => {
    if (! dati.value) return [];
    const c = dati.value.conteggi;
    const n = (chiave) => c[chiave] ?? 0;
    const pezzi = [];

    // "3 lavori, di cui 3 in ritardo" non si dice: quando sono tutti in
    // ritardo lo si dice una volta sola
    const conRitardo = (totale, ritardo, nomi, stati, altrimenti) => {
        const nome = plurale(totale, ...nomi);
        if (ritardo && ritardo === totale) return [{ t: `${totale} ${nome} ${plurale(totale, ...stati)}`, b: true, rosso: true }];
        if (! ritardo) return [{ t: `${totale} ${nome}`, b: true }, { t: ` ${altrimenti}` }];

        return [{ t: `${totale} ${nome}`, b: true }, { t: ', di cui ' }, { t: `${ritardo} ${plurale(ritardo, ...stati)}`, b: true, rosso: true }];
    };

    const lavori = n('lavori_ritardo') + n('lavori_settimana');
    if (lavori) pezzi.push(conRitardo(lavori, n('lavori_ritardo'), ['lavoro', 'lavori'], ['oltre la fine prevista', 'oltre la fine prevista'], 'in programma questa settimana'));
    const vta = n('vta_scaduti') + n('vta_in_scadenza');
    if (vta) {
        const p = [];
        if (n('vta_scaduti')) p.push({ t: `${n('vta_scaduti')} ${plurale(n('vta_scaduti'), 'ricontrollo VTA scaduto', 'ricontrolli VTA scaduti')}`, b: true, rosso: true });
        if (n('vta_in_scadenza')) {
            if (p.length) p.push({ t: ' e ' });
            p.push({ t: `${n('vta_in_scadenza')} ${plurale(n('vta_in_scadenza'), 'ricontrollo VTA', 'ricontrolli VTA')} entro 30 giorni`, b: p.length === 0 });
        }
        if (n('vta_senza_ordine')) p.push({ t: ` (${n('vta_senza_ordine')} senza ordine di lavoro)` });
        pezzi.push(p);
    }
    const ispezioni = n('ispezioni_scadute') + n('ispezioni_in_scadenza');
    if (ispezioni) pezzi.push(conRitardo(ispezioni, n('ispezioni_scadute'), ['ispezione', 'ispezioni'], ['scaduta', 'scadute'], 'in scadenza entro 14 giorni'));
    if (n('segnalazioni')) pezzi.push([{ t: `${n('segnalazioni')} ${plurale(n('segnalazioni'), 'segnalazione', 'segnalazioni')}`, b: true }, { t: ' con i tempi a rischio' }]);
    if (n('non_conformita')) pezzi.push([{ t: `${n('non_conformita')} non conformità`, b: true }, { t: n('non_conformita') === 1 ? ' aperta' : ' aperte' }]);
    const certificati = n('certificati_scaduti') + n('certificati_in_scadenza');
    if (certificati) pezzi.push(conRitardo(certificati, n('certificati_scaduti'), ['patentino', 'patentini'], ['scaduto', 'scaduti'], 'in scadenza entro 60 giorni'));
    if (n('irrigazione')) pezzi.push([{ t: `${n('irrigazione')} ${plurale(n('irrigazione'), 'impianto', 'impianti')}`, b: true }, { t: ' da aprire o invernare' }]);

    const parti = [];
    if (! pezzi.length) {
        parti.push({ t: 'Niente in ritardo e niente in scadenza nei prossimi giorni.' });
    } else {
        const totale = c.totale;
        parti.push({ t: totale === 1 ? "C'è " : 'Ci sono ' }, { t: `${totale} ${plurale(totale, 'cosa da fare', 'cose da fare')}`, b: true }, { t: ': ' });
        pezzi.forEach((p, i) => {
            parti.push(...p, { t: i < pezzi.length - 1 ? '; ' : '.' });
        });
    }

    const campo = dati.value.campo;
    if (campo) {
        const arrivi = [];
        if (campo.rilievi) arrivi.push({ t: `${campo.rilievi} ${plurale(campo.rilievi, 'rilievo', 'rilievi')}`, b: true, genere: 'm' });
        if (campo.misure) arrivi.push({ t: `${campo.misure} ${plurale(campo.misure, 'scheda con le misure aggiornate', 'schede con le misure aggiornate')}`, b: true, genere: 'f' });
        if (campo.aree) arrivi.push({ t: `${campo.aree} ${plurale(campo.aree, 'area nuova da ridisegnare', 'aree nuove da ridisegnare')}`, b: true, genere: 'f' });
        if (arrivi.length) {
            const quanti = campo.rilievi + campo.misure + campo.aree;
            const femminile = arrivi.every((a) => a.genere === 'f');
            const verbo = quanti === 1 ? (femminile ? 'è arrivata ' : 'è arrivato ') : (femminile ? 'sono arrivate ' : 'sono arrivati ');
            parti.push({ t: ` Dal campo ${verbo}` });
            arrivi.forEach((a, i) => {
                parti.push(a);
                if (i < arrivi.length - 2) parti.push({ t: ', ' });
                else if (i === arrivi.length - 2) parti.push({ t: ' e ' });
            });
            parti.push({ t: campo.operatori ? ` da ${campo.operatori} ${plurale(campo.operatori, 'operatore', 'operatori')}.` : '.' });
        } else {
            parti.push({ t: ' Dal campo oggi non è arrivato niente.' });
        }
    }

    return parti;
});

const riepilogoCampo = computed(() => {
    const c = dati.value?.campo;
    if (! c) return '';
    const parti = [];
    if (c.rilievi) parti.push(`${c.rilievi} ${plurale(c.rilievi, 'rilievo', 'rilievi')}`);
    if (c.misure) parti.push(`${c.misure} ${plurale(c.misure, 'scheda aggiornata', 'schede aggiornate')}`);
    if (c.aree) parti.push(`${c.aree} ${plurale(c.aree, 'area nuova', 'aree nuove')}`);
    if (c.operatori) parti.push(`${c.operatori} ${plurale(c.operatori, 'operatore', 'operatori')}`);

    return parti.join(' · ');
});

function dettagliPortale(p) {
    const parti = [`${p.pubblicati} ${plurale(p.pubblicati, 'elemento pubblicato', 'elementi pubblicati')}`];
    if (p.nascosti) parti.push(`${p.nascosti} ${plurale(p.nascosti, 'scheda nascosta', 'schede nascoste')}`);
    if (p.recapiti_mancanti) parti.push(`${p.recapiti_mancanti} ${plurale(p.recapiti_mancanti, 'recapito mancante', 'recapiti mancanti')}`);
    if (! p.copertina) parti.push('senza fotografia di copertina');

    return parti.join(' · ');
}
</script>

<template>
    <Head title="Oggi" />

    <AppLayout>
        <div class="mx-auto flex max-w-[1640px] flex-col gap-4 p-4 md:gap-5 md:p-6 lg:px-7">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Oggi</h1>
                    <div class="mt-0.5 text-[13px] text-gray-500" data-test="oggi-giorno">{{ dati?.giorno ?? '' }}</div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Link v-if="can('works.manage')" href="/lavori?nuovo=1" :class="BOTTONE">Nuovo lavoro</Link>
                    <Link v-if="can('works.view')" href="/segnalazioni?nuova=1" :class="BOTTONE_SECONDARIO">Nuova segnalazione</Link>
                    <Link v-if="can('assets.create')" href="/mappa" :class="BOTTONE_SECONDARIO">Nuovo rilievo dalla mappa</Link>
                </div>
            </div>

            <AvvisoErrore :messaggio="avviso" :in-corso="riprovaInCorso" @riprova="riprova" />

            <p v-if="primoCaricamento && ! avviso" class="text-sm text-gray-500">Carico le cose da fare…</p>

            <template v-if="dati">
                <p class="max-w-[78ch] text-base leading-relaxed text-gray-900" data-test="oggi-frase">
                    <template v-for="(p, i) in frase" :key="i">
                        <b v-if="p.b" :class="p.rosso ? 'text-red-800' : ''">{{ p.t }}</b>
                        <template v-else>{{ p.t }}</template>
                    </template>
                </p>

                <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_380px] lg:items-start">
                    <section :class="CARTA" class="min-w-0" data-test="oggi-lista">
                        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-4 py-3">
                            <h2 class="text-base font-bold text-gray-900">Da fare, nell'ordine in cui conviene farle</h2>
                            <div v-if="voci.length" class="flex flex-wrap gap-2 md:inline-flex md:gap-0 md:overflow-hidden md:rounded-lg md:border md:border-gray-300 md:bg-white" role="group" aria-label="Filtra per famiglia">
                                <button
                                    v-for="[chiave, etichetta] in filtri"
                                    :key="chiave"
                                    type="button"
                                    class="inline-flex min-h-11 items-center whitespace-nowrap rounded-lg border px-3 text-sm font-semibold transition md:min-h-9 md:rounded-none md:border-0 md:border-r md:border-gray-200 md:last:border-r-0"
                                    :class="famiglia === chiave ? 'border-green-700 bg-green-700 text-white' : 'border-gray-300 text-gray-700 hover:bg-gray-50'"
                                    :aria-pressed="famiglia === chiave"
                                    :data-test="`oggi-filtro-${chiave}`"
                                    @click="famiglia = chiave"
                                >{{ etichetta }} · {{ perFamiglia[chiave] }}</button>
                            </div>
                        </div>

                        <ul v-if="vociFiltrate.length" class="divide-y divide-gray-100">
                            <li
                                v-for="v in vociFiltrate"
                                :key="v.chiave"
                                class="flex flex-col items-start gap-2 px-4 py-3 md:flex-row md:flex-wrap md:items-center md:gap-x-3"
                                :data-test="`oggi-voce-${v.tipo}`"
                            >
                                <span :class="URGENZA[v.urgenza]">{{ TIPI[v.tipo] ?? v.tipo }}<span class="sr-only">, {{ URGENZA_TESTO[v.urgenza] }}</span></span>
                                <div class="w-full min-w-0 md:w-auto md:flex-1 md:basis-60">
                                    <div class="font-semibold text-gray-900">{{ v.titolo }}</div>
                                    <div class="text-[13px] text-gray-500">{{ v.dettaglio }}</div>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <Link v-for="a in v.azioni" :key="a.label" :href="a.href" :class="BOTTONE_PICCOLO">{{ a.label }}</Link>
                                </div>
                            </li>
                        </ul>
                        <p v-else class="px-4 py-6 text-sm text-gray-500" data-test="oggi-vuoto">
                            {{ voci.length ? 'Niente in questa famiglia.' : 'Niente in ritardo e niente in scadenza: la lista è vuota.' }}
                        </p>
                        <p v-if="dati.conteggi.totale > voci.length" class="border-t border-gray-100 px-4 py-2.5 text-[13px] text-gray-500">
                            Qui stanno le prime {{ voci.length }} per sezione; le altre sono nelle pagine di lavori, controlli e segnalazioni.
                        </p>
                    </section>

                    <aside class="flex min-w-0 flex-col gap-4">
                        <section v-if="dati.campo" :class="CARTA" class="p-4" data-test="oggi-campo">
                            <h2 class="text-[15px] font-bold text-gray-900">Arrivato dal campo oggi</h2>
                            <p class="mt-0.5 text-[13px] text-gray-500">{{ riepilogoCampo || 'Niente, per ora.' }}</p>
                            <ul v-if="dati.campo.righe.length" class="mt-2 divide-y divide-gray-100">
                                <li v-for="(r, i) in dati.campo.righe" :key="i" class="flex gap-3 py-2">
                                    <span class="w-12 shrink-0 pt-0.5 text-xs text-gray-500">{{ r.ora }}</span>
                                    <div class="min-w-0">
                                        <div class="truncate font-semibold text-gray-900">{{ r.cosa ?? r.tipo }}</div>
                                        <div class="text-[13px] text-gray-500">{{ r.cosa ? r.tipo : '' }}{{ r.cosa && r.utente ? ' · ' : '' }}{{ r.utente ?? '' }}</div>
                                    </div>
                                </li>
                            </ul>
                        </section>

                        <section v-if="dati.documenti" :class="CARTA" class="p-4" data-test="oggi-documenti">
                            <h2 class="text-[15px] font-bold text-gray-900">Documenti da chiudere</h2>
                            <ul v-if="dati.documenti.righe.length" class="mt-1 divide-y divide-gray-100">
                                <li v-for="d in dati.documenti.righe" :key="d.id" class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2">
                                    <div class="min-w-0 flex-1 basis-40">
                                        <div class="font-semibold text-gray-900">Perizia {{ d.cartellino ?? '' }}</div>
                                        <div class="text-[13px] text-gray-500">{{ d.numero ? `n. ${d.numero} · ` : '' }}emessa il {{ d.emessa_il }} · manca la validazione</div>
                                    </div>
                                    <Link :href="`/censimento/${d.asset_id}?vta=1`" :class="BOTTONE_PICCOLO">Apri</Link>
                                </li>
                            </ul>
                            <p v-else class="mt-0.5 text-[13px] text-gray-500">Nessuna perizia emessa in attesa di validazione.</p>
                            <p v-if="dati.documenti.perizie_da_validare > dati.documenti.righe.length" class="mt-1 text-[13px] text-gray-500">
                                E altre {{ dati.documenti.perizie_da_validare - dati.documenti.righe.length }} nello scadenzario VTA.
                            </p>
                        </section>

                        <section v-if="dati.portali" :class="CARTA" class="p-4" data-test="oggi-portali">
                            <h2 class="text-[15px] font-bold text-gray-900">Portali pubblici</h2>
                            <ul v-if="dati.portali.length" class="mt-1 divide-y divide-gray-100">
                                <li v-for="p in dati.portali" :key="p.id" class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2">
                                    <div class="min-w-0 flex-1 basis-40">
                                        <div class="truncate font-semibold text-gray-900">{{ p.nome }}</div>
                                        <div class="text-[13px] text-gray-500">{{ dettagliPortale(p) }}</div>
                                    </div>
                                    <a :href="`/comune/${p.slug}`" target="_blank" rel="noopener" :class="BOTTONE_PICCOLO">Apri</a>
                                </li>
                            </ul>
                            <p v-else class="mt-0.5 text-[13px] text-gray-500">Nessun portale acceso. Si accende da Territorio, nella scheda del committente.</p>
                        </section>
                    </aside>
                </div>
            </template>
        </div>
    </AppLayout>
</template>
