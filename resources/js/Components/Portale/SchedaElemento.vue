<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import AvvisoErrore from '@/Components/AvvisoErrore.vue';
import { usaCaricamento } from '@/caricamento';
import { BOTTONE_PICCOLO, CARTA, ETICHETTA } from '@/nuovo/stile';
import { STATI, STATO_LAVORO, formatData, misura, num, oggiIso } from '@/portale/formato';

/*
 * La scheda dell'elemento come la legge l'ufficio del Comune: fotografie,
 * specie e misure, stabilita' (con la perizia da scaricare quando e' emessa),
 * lavori e cronologia. Solo lettura: le modifiche restano al gestionale.
 */
const props = defineProps({
    elementoId: { type: String, required: true },
    navigazioneUrl: { type: String, default: '' },
    // Nel pannello della mappa e nell'anteprima dell'elenco la scheda e' piu' corta
    compatta: { type: Boolean, default: false },
});
const emit = defineEmits(['chiudi', 'mappa', 'lavoro']);

const { avviso, riprovaInCorso, carica, riprova } = usaCaricamento();
const dati = ref(null);
const caricamento = ref(false);
const fotoScelta = ref(0);

async function leggi() {
    caricamento.value = true;
    dati.value = null;
    fotoScelta.value = 0;
    try {
        await carica(async () => {
            const { data } = await axios.get(`/api/v1/portal/elementi/${props.elementoId}`);
            dati.value = data.data;
        });
    } finally {
        caricamento.value = false;
    }
}
watch(() => props.elementoId, leggi, { immediate: true });

const albero = computed(() => dati.value?.tree ?? null);
const foto = computed(() => dati.value?.foto ?? []);
const fotoGrande = computed(() => foto.value[fotoScelta.value] ?? foto.value[0] ?? null);
const stato = computed(() => (dati.value?.stato && dati.value.stato !== 'altro' ? STATI[dati.value.stato] : null));
const ultimaVta = computed(() => dati.value?.valutazioni?.[0] ?? null);
const precedentiVta = computed(() => (dati.value?.valutazioni ?? []).slice(1));
const ricontrolloScaduto = computed(() => ultimaVta.value?.next_check_due && ultimaVta.value.next_check_due < oggiIso());
const titolo = computed(() => dati.value?.census_code || dati.value?.tipo || 'Elemento');

const misure = computed(() => {
    const a = albero.value;
    if (! a) return [];

    return [
        ['Altezza', misura(a.height_m, 'm')],
        ['Circonferenza del fusto', misura(a.trunk_circumference_cm, 'cm', 0)],
        ['Diametro del fusto', misura(a.dbh_cm, 'cm', 0)],
        ['Diametro della chioma', misura(a.crown_diameter_m, 'm')],
        ['Inserzione della chioma', misura(a.crown_insertion_m, 'm')],
        ['Fusti', a.trunk_count ? num(a.trunk_count, 0) : '—'],
        ['Età stimata', a.age_years_est ? `${num(a.age_years_est, 0)} anni` : (a.age_class || '—')],
        ['Stato vegetativo', a.vegetative_state || '—'],
    ].filter(([, v]) => v !== '—' || ! props.compatta);
});

const attributi = computed(() => Object.entries(dati.value?.attributes ?? {})
    .filter(([, v]) => v !== null && v !== '' && v !== false)
    .map(([k, v]) => [k.replaceAll('_', ' ').replace(/^./, (c) => c.toUpperCase()), Array.isArray(v) ? v.join(', ') : (v === true ? 'sì' : String(v))]));

const navigazione = computed(() => (props.navigazioneUrl && dati.value?.lat !== null && dati.value?.lat !== undefined
    ? props.navigazioneUrl.replace('{lat}', dati.value.lat).replace('{lon}', dati.value.lon)
    : null));

const CRONOLOGIA = { rilievo: 'Rilievo', valutazione: 'Stabilità', lavoro: 'Lavoro', foto: 'Foto', abbattimento: 'Abbattimento' };
</script>

<template>
    <article class="flex flex-col gap-4" data-test="portale-scheda">
        <AvvisoErrore :messaggio="avviso" :in-corso="riprovaInCorso" @riprova="riprova" />
        <p v-if="caricamento" class="text-sm text-gray-500">Carico la scheda…</p>

        <template v-if="dati">
            <header class="flex items-start justify-between gap-2">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-xl font-bold text-gray-900" data-test="portale-scheda-titolo">{{ titolo }}</h2>
                        <span v-if="stato" :class="stato.chip">{{ stato.etichetta }}</span>
                        <span v-if="albero?.removed_on" class="inline-flex min-h-6 items-center rounded-full bg-gray-800 px-2.5 text-xs font-semibold text-white">Abbattuto</span>
                    </div>
                    <p class="text-sm text-gray-700">
                        <template v-if="albero?.species"><i>{{ albero.species }}</i><template v-if="albero.common_name"> · {{ albero.common_name }}</template></template>
                        <template v-else>{{ dati.tipo }}</template>
                    </p>
                    <p class="text-[13px] text-gray-500">{{ [dati.tipo !== titolo ? dati.tipo : null, dati.area, dati.localita].filter(Boolean).join(' · ') }}</p>
                </div>
                <button v-if="compatta" type="button" class="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded-lg text-gray-600 hover:bg-gray-100 md:min-h-9 md:min-w-9" aria-label="Chiudi la scheda" data-test="portale-scheda-chiudi" @click="emit('chiudi')">✕</button>
            </header>

            <div class="overflow-hidden rounded-lg bg-gray-100" :class="compatta ? 'aspect-[4/3]' : 'aspect-[4/3] md:aspect-[16/9]'">
                <a v-if="fotoGrande" :href="fotoGrande.url" target="_blank" rel="noopener" class="block h-full w-full">
                    <img :src="fotoGrande.url" :alt="`Fotografia di ${titolo}`" class="h-full w-full object-cover" loading="lazy">
                </a>
                <div v-else class="flex h-full items-center justify-center text-sm text-gray-500">Nessuna fotografia</div>
            </div>
            <div v-if="foto.length" class="flex flex-wrap items-center gap-2 text-xs text-gray-500">
                <span>Foto del {{ formatData(fotoGrande.taken_at ?? fotoGrande.created_at) }}<template v-if="foto.length > 1"> · {{ foto.length }} foto</template></span>
                <div v-if="foto.length > 1" class="flex flex-wrap gap-1.5">
                    <button
                        v-for="(f, i) in foto.slice(0, 12)"
                        :key="f.id"
                        type="button"
                        class="h-11 w-11 overflow-hidden rounded-md border-2 bg-gray-100 md:h-10 md:w-10"
                        :class="i === fotoScelta ? 'border-green-700' : 'border-transparent'"
                        :aria-label="`Foto ${i + 1} del ${formatData(f.taken_at ?? f.created_at)}`"
                        :aria-pressed="i === fotoScelta"
                        @click="fotoScelta = i"
                    ><img :src="f.url" alt="" class="h-full w-full object-cover" loading="lazy"></button>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <button type="button" :class="BOTTONE_PICCOLO" data-test="portale-scheda-mappa" @click="emit('mappa', dati)">Vedi sulla mappa</button>
                <a v-if="navigazione" :href="navigazione" target="_blank" rel="noopener" :class="BOTTONE_PICCOLO">Raggiungi</a>
                <a v-if="ultimaVta?.pdf" :href="ultimaVta.pdf" target="_blank" rel="noopener" :class="BOTTONE_PICCOLO" data-test="portale-scheda-perizia">Perizia n. {{ ultimaVta.report_number }} (PDF)</a>
            </div>

            <section v-if="albero" :class="CARTA" class="p-3">
                <h3 :class="ETICHETTA">Misure</h3>
                <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-2 text-sm" :class="compatta ? '' : 'md:grid-cols-4'">
                    <div v-for="[k, v] in misure" :key="k"><dt class="text-xs text-gray-500">{{ k }}</dt><dd class="font-semibold tabular-nums text-gray-900">{{ v }}</dd></div>
                    <div v-if="albero.planted_on"><dt class="text-xs text-gray-500">Messa a dimora</dt><dd class="font-semibold text-gray-900">{{ formatData(albero.planted_on) }}</dd></div>
                    <div v-if="albero.is_monumental || albero.is_protected"><dt class="text-xs text-gray-500">Tutela</dt><dd class="font-semibold text-gray-900">{{ [albero.is_monumental ? 'monumentale' : null, albero.is_protected ? 'vincolato' : null].filter(Boolean).join(', ') }}</dd></div>
                </dl>
                <p v-if="albero.removed_on" class="mt-2 text-sm text-gray-700">Abbattuto o rimosso il {{ formatData(albero.removed_on) }}<template v-if="albero.removal_reason">: {{ albero.removal_reason }}</template>.</p>
            </section>

            <section v-if="albero && ! albero.removed_on" :class="CARTA" class="p-3" data-test="portale-scheda-vta">
                <h3 :class="ETICHETTA">Stabilità</h3>
                <template v-if="ultimaVta">
                    <div class="mt-2 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                        <span class="text-2xl font-bold tabular-nums text-gray-900">{{ ultimaVta.failure_class ? `Classe ${ultimaVta.failure_class}` : 'Senza classe' }}</span>
                        <span class="text-sm text-gray-600">valutazione del {{ formatData(ultimaVta.assessed_on) }}</span>
                    </div>
                    <p class="mt-1 text-sm text-gray-900">{{ ultimaVta.outcome_etichetta }}</p>
                    <p v-if="ultimaVta.prescriptions" class="mt-1 text-sm text-gray-700"><span class="text-gray-500">Prescrizioni:</span> {{ ultimaVta.prescriptions }}</p>
                    <p v-if="ultimaVta.next_check_due" class="mt-1 text-sm" :class="ricontrolloScaduto ? 'font-semibold text-red-800' : 'text-gray-700'">
                        Ricontrollo {{ ricontrolloScaduto ? 'scaduto il' : 'entro il' }} {{ formatData(ultimaVta.next_check_due) }}
                    </p>
                    <p class="mt-1 text-[13px] text-gray-500">
                        <template v-if="ultimaVta.pdf">Perizia n. {{ ultimaVta.report_number }} emessa il {{ formatData(ultimaVta.report_issued_at) }}<template v-if="ultimaVta.validated_at">, validata il {{ formatData(ultimaVta.validated_at) }}</template>.</template>
                        <template v-else>La perizia di questa valutazione non è ancora stata emessa.</template>
                    </p>
                    <details v-if="precedentiVta.length" class="mt-2 text-sm">
                        <summary class="min-h-11 cursor-pointer list-none font-semibold text-green-800 underline-offset-2 hover:underline md:min-h-9 md:leading-9">Valutazioni precedenti ({{ precedentiVta.length }})</summary>
                        <ul class="mt-1 divide-y divide-gray-100">
                            <li v-for="v in precedentiVta" :key="v.id" class="flex flex-wrap items-center gap-x-3 py-1.5">
                                <span class="tabular-nums text-gray-700">{{ formatData(v.assessed_on) }}</span>
                                <span class="font-semibold">{{ v.failure_class ? `classe ${v.failure_class}` : 'senza classe' }}</span>
                                <span class="text-gray-600">{{ v.outcome_etichetta }}</span>
                                <a v-if="v.pdf" :href="v.pdf" target="_blank" rel="noopener" class="font-semibold text-green-800 underline-offset-2 hover:underline">perizia n. {{ v.report_number }}</a>
                            </li>
                        </ul>
                    </details>
                </template>
                <p v-else class="mt-2 text-sm text-gray-600">Nessuna valutazione di stabilità registrata.</p>
            </section>

            <section :class="CARTA" class="p-3" data-test="portale-scheda-lavori">
                <h3 :class="ETICHETTA">Lavori</h3>
                <ul v-if="dati.lavori.length" class="mt-2 divide-y divide-gray-100 text-sm">
                    <li v-for="l in dati.lavori" :key="l.id" class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2">
                        <span :class="STATO_LAVORO[l.status]">{{ l.status_etichetta }}</span>
                        <button type="button" class="font-semibold text-gray-900 underline-offset-2 hover:underline" @click="emit('lavoro', l.id)">{{ l.title }}</button>
                        <span class="text-gray-500">{{ l.code }}<template v-if="l.tipo"> · {{ l.tipo }}</template></span>
                        <span class="tabular-nums text-gray-600">{{ l.completed_at ? `fatto il ${formatData(l.completed_at)}` : [l.planned_start ? `dal ${formatData(l.planned_start)}` : null, l.planned_end ? `al ${formatData(l.planned_end)}` : null].filter(Boolean).join(' ') }}</span>
                        <span v-if="l.fatto_qui && l.status !== 'completed'" class="text-[13px] text-green-800">eseguito su questo elemento</span>
                    </li>
                </ul>
                <p v-else class="mt-2 text-sm text-gray-600">Nessun lavoro registrato su questo elemento.</p>
            </section>

            <section v-if="attributi.length && ! compatta" :class="CARTA" class="p-3">
                <h3 :class="ETICHETTA">Altri dati del rilievo</h3>
                <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-2 text-sm md:grid-cols-4">
                    <div v-for="[k, v] in attributi" :key="k"><dt class="text-xs text-gray-500">{{ k }}</dt><dd class="font-semibold text-gray-900">{{ v }}</dd></div>
                </dl>
            </section>

            <section :class="CARTA" class="p-3" data-test="portale-scheda-cronologia">
                <h3 :class="ETICHETTA">Cronologia</h3>
                <ol class="mt-2 divide-y divide-gray-100 text-sm">
                    <li v-for="(e, i) in dati.cronologia" :key="i" class="flex gap-3 py-1.5">
                        <span class="w-20 shrink-0 tabular-nums text-gray-600">{{ formatData(e.data) }}</span>
                        <span class="min-w-0">
                            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ CRONOLOGIA[e.tipo] ?? e.tipo }}</span>
                            <span class="block font-medium text-gray-900">{{ e.titolo }}</span>
                            <span v-if="e.dettaglio" class="block text-[13px] text-gray-600">{{ e.dettaglio }}</span>
                        </span>
                    </li>
                </ol>
            </section>

            <p class="text-[12px] text-gray-500">
                Posizione {{ dati.lat?.toFixed(6) }}, {{ dati.lon?.toFixed(6) }} · rilevato il {{ formatData(dati.rilevato_il) }}<template v-if="dati.sede"> · {{ dati.sede }}</template>
            </p>
        </template>
    </article>
</template>
