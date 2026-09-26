<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import AvvisoErrore from '@/Components/AvvisoErrore.vue';
import TestataSezione from '@/Components/Nuovo/TestataSezione.vue';
import { SCHEDE_COMMITTENTI } from '@/nuovo/sezioni';
import { usaCaricamento } from '@/caricamento';
import { avvisoCaricamento } from '@/avvisi';
import { corrisponde } from '@/ricerca';
import { BOTTONE, BOTTONE_PICCOLO, BOTTONE_SECONDARIO, CARTA, CHIP, ETICHETTA, plurale } from '@/nuovo/stile';

/*
 * Committenti (bozza A, schermata 09): l'anagrafica con i numeri a colpo
 * d'occhio (elementi, aree, lavori aperti, portale) e la scheda del
 * committente scelto con le sue aree. Le scritture (anagrafica, sedi,
 * localita', aree, portale, vincoli) restano in Territorio, che si apre gia'
 * sul committente giusto.
 */
const page = usePage();
const permessi = computed(() => page.props.auth?.user?.permissions ?? []);
const can = (p) => permessi.value.includes(p);

const { avviso, riprovaInCorso, carica, riprova } = usaCaricamento();
const committenti = ref([]);
const filtro = reactive({ q: '', gruppo: 'tutti' });
const GRUPPI = [['tutti', 'Tutti'], ['pubblici', 'Comuni ed enti'], ['privati', 'Privati e condomini'], ['portale', 'Con portale']];

async function caricaCommittenti() {
    const { data } = await axios.get('/api/v1/committenti/riepilogo');
    committenti.value = data.data;
}
onMounted(() => carica(caricaCommittenti));

const perGruppo = computed(() => ({
    tutti: committenti.value.length,
    pubblici: committenti.value.filter((c) => c.tipo === 'public').length,
    privati: committenti.value.filter((c) => c.tipo !== 'public').length,
    portale: committenti.value.filter((c) => c.portale.acceso).length,
}));
const visibili = computed(() => committenti.value.filter((c) => {
    if (filtro.gruppo === 'pubblici' && c.tipo !== 'public') return false;
    if (filtro.gruppo === 'privati' && c.tipo === 'public') return false;
    if (filtro.gruppo === 'portale' && ! c.portale.acceso) return false;

    return ! filtro.q || corrisponde([c.nome, c.nome_pubblico, c.codice, c.partita_iva, c.codice_fiscale], filtro.q);
}));

// --- Scheda del committente scelto -----------------------------------------
const scelto = ref(null);
const aree = reactive({ righe: [], caricamento: false, errore: '' });
async function apri(c) {
    scelto.value = c;
    aree.righe = [];
    aree.errore = '';
    aree.caricamento = true;
    try {
        const { data } = await axios.get('/api/v1/areas', { params: { client_id: c.id, per_page: 100 } });
        if (scelto.value?.id === c.id) aree.righe = data.data;
    } catch (err) {
        aree.errore = avvisoCaricamento(err);
    } finally {
        aree.caricamento = false;
    }
}
const STATO_AREA = { active: ['Attiva', 'ok'], suspended: ['Sospesa', 'attenzione'], planned: ['Prevista', 'attenzione'], dismissed: ['Dismessa', 'neutra'] };
const contatto = (c, chiave) => c.contatti?.[chiave] ?? null;
const indirizzo = (c) => [c.indirizzo?.street ?? c.indirizzo?.via, c.indirizzo?.postal_code ?? c.indirizzo?.cap, c.indirizzo?.city ?? c.indirizzo?.comune].filter(Boolean).join(' ');
</script>

<template>
    <Head title="Committenti" />

    <AppLayout>
        <div class="mx-auto flex max-w-[1640px] flex-col gap-4 p-4 md:p-6 lg:px-7">
            <TestataSezione titolo="Committenti" :sottotitolo="`${committenti.length} ${plurale(committenti.length, 'committente', 'committenti')} · anagrafica, territorio, portale e imprese di ciascuno`" attiva="committenti" :schede="SCHEDE_COMMITTENTI">
                <Link v-if="can('clients.manage')" href="/territorio?nuovo=1" :class="BOTTONE" data-test="nuovo-committente">Nuovo committente</Link>
                <Link href="/territorio" :class="BOTTONE_SECONDARIO">Albero del territorio</Link>
            </TestataSezione>

            <AvvisoErrore :messaggio="avviso" :in-corso="riprovaInCorso" @riprova="riprova" />

            <div class="flex flex-wrap items-center gap-2">
                <label class="relative min-w-0 flex-1 basis-72"><span class="sr-only">Cerca</span><input v-model="filtro.q" type="search" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm placeholder:text-gray-400 focus:border-green-700 focus:outline-none focus:ring-1 focus:ring-green-700" placeholder="Cerca per nome, codice, partita IVA, codice fiscale" data-test="committenti-ricerca"></label>
                <div class="flex flex-wrap gap-2">
                    <button v-for="[chiave, etichetta] in GRUPPI" :key="chiave" type="button" class="inline-flex min-h-9 items-center gap-1.5 rounded-full border px-3 text-[13px] font-semibold transition" :class="filtro.gruppo === chiave ? 'border-green-800 bg-green-800 text-white' : 'border-gray-300 bg-white text-gray-700 hover:border-gray-400'" :aria-pressed="filtro.gruppo === chiave" :data-test="`committenti-gruppo-${chiave}`" @click="filtro.gruppo = chiave">{{ etichetta }} <span>{{ perGruppo[chiave] }}</span></button>
                </div>
            </div>

            <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_420px] lg:items-start">
                <section :class="CARTA" class="min-w-0" data-test="committenti-elenco">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead><tr class="border-b border-gray-200 text-left text-xs font-semibold uppercase tracking-wide text-gray-500"><th class="px-4 py-2.5">Committente</th><th class="px-3 py-2.5 text-right">Elementi</th><th class="px-3 py-2.5 text-right">Aree</th><th class="px-3 py-2.5 text-right">Lavori aperti</th><th class="px-3 py-2.5">Portale</th></tr></thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="c in visibili" :key="c.id" class="cursor-pointer transition hover:bg-gray-50" :class="scelto?.id === c.id ? 'bg-green-50' : ''" data-test="committenti-riga" @click="apri(c)">
                                    <td class="px-4 py-2.5">
                                        <button type="button" class="font-semibold text-gray-900 underline-offset-2 hover:underline" @click.stop="apri(c)">{{ c.nome }}</button>
                                        <div class="text-xs text-gray-500">{{ [c.tipo_etichetta, c.prefisso ? `prefisso ${c.prefisso}` : null, c.codice, c.nato_dal_campo ? 'nato dal campo' : null, c.attivo ? null : 'non attivo'].filter(Boolean).join(' · ') }}</div>
                                    </td>
                                    <td class="px-3 py-2.5 text-right text-gray-900">{{ c.elementi.toLocaleString('it-IT') }}</td>
                                    <td class="px-3 py-2.5 text-right text-gray-900">{{ c.aree }}</td>
                                    <td class="px-3 py-2.5 text-right text-gray-900">{{ c.lavori_aperti }}</td>
                                    <td class="px-3 py-2.5"><span :class="c.portale.acceso ? CHIP.ok : CHIP.neutra">{{ c.portale.acceso ? 'Attivo' : 'Spento' }}</span></td>
                                </tr>
                                <tr v-if="! visibili.length"><td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500" data-test="committenti-vuoto">Nessun committente con questi criteri.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <aside :class="[CARTA, scelto ? 'order-first lg:order-none' : 'hidden lg:block']" class="min-w-0 p-4 lg:sticky lg:top-4" data-test="committenti-scheda">
                    <p v-if="! scelto" class="text-sm text-gray-500">Scegli un committente per vederne anagrafica, aree e portale.</p>
                    <template v-else>
                        <div class="mb-2 flex items-center justify-between lg:hidden"><span :class="ETICHETTA">Scheda</span><button type="button" class="min-h-11 px-2 text-sm text-gray-600" @click="scelto = null">✕ Chiudi</button></div>
                        <div :class="ETICHETTA">{{ scelto.tipo_etichetta }}</div>
                        <h2 class="text-xl font-bold text-gray-900">{{ scelto.nome }}</h2>
                        <p class="text-[13px] text-gray-500">{{ [scelto.partita_iva ? `P. IVA ${scelto.partita_iva}` : null, scelto.codice_fiscale ? `CF ${scelto.codice_fiscale}` : null, contatto(scelto, 'email'), contatto(scelto, 'phone') ?? contatto(scelto, 'telefono'), indirizzo(scelto)].filter(Boolean).join(' · ') || 'Anagrafica da completare in Territorio.' }}</p>
                        <dl class="mt-3 grid grid-cols-3 gap-2 text-sm">
                            <div><dt class="text-xs text-gray-500">Elementi</dt><dd class="text-lg font-bold text-gray-900">{{ scelto.elementi.toLocaleString('it-IT') }}</dd></div>
                            <div><dt class="text-xs text-gray-500">Aree</dt><dd class="text-lg font-bold text-gray-900">{{ scelto.aree }}</dd></div>
                            <div><dt class="text-xs text-gray-500">Lavori aperti</dt><dd class="text-lg font-bold text-gray-900">{{ scelto.lavori_aperti }}</dd></div>
                        </dl>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <Link :href="`/territorio?cliente=${scelto.id}`" :class="BOTTONE" data-test="committente-territorio">Territorio e anagrafica</Link>
                            <Link v-if="can('assets.view')" :href="`/patrimonio?client_id=${scelto.id}`" :class="BOTTONE_SECONDARIO">Elementi</Link>
                            <Link v-if="can('works.view')" :href="`/lavori?client_id=${scelto.id}`" :class="BOTTONE_SECONDARIO">Lavori</Link>
                        </div>

                        <div class="mt-4 border-t border-gray-100 pt-3">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <h3 class="text-sm font-bold text-gray-900">Portale pubblico</h3>
                                <span :class="scelto.portale.acceso ? CHIP.ok : CHIP.neutra">{{ scelto.portale.acceso ? 'Attivo' : 'Spento' }}</span>
                            </div>
                            <p v-if="scelto.portale.acceso" class="mt-1 text-[13px] text-gray-600">
                                {{ [scelto.portale.copertina ? 'fotografia di copertina sì' : 'senza fotografia di copertina', `recapiti ${scelto.portale.recapiti} di 4`, scelto.portale.co2 ? 'stima CO2 accesa' : 'stima CO2 spenta', scelto.nascosti ? `${scelto.nascosti} ${plurale(scelto.nascosti, 'scheda nascosta', 'schede nascoste')}` : null].filter(Boolean).join(' · ') }}
                            </p>
                            <p v-else class="mt-1 text-[13px] text-gray-600">Si accende da Territorio, nella scheda "Portale pubblico" del committente.</p>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <a v-if="scelto.portale.acceso && scelto.portale.slug" :href="`/comune/${scelto.portale.slug}`" target="_blank" rel="noopener" :class="BOTTONE_PICCOLO">Apri il portale</a>
                                <Link v-if="can('clients.manage')" :href="`/territorio?cliente=${scelto.id}&scheda=portale`" :class="BOTTONE_PICCOLO">Impostazioni del portale</Link>
                            </div>
                        </div>

                        <div class="mt-4 border-t border-gray-100 pt-3">
                            <h3 class="text-sm font-bold text-gray-900">Aree ({{ aree.righe.length }})</h3>
                            <p v-if="aree.caricamento" class="mt-1 text-[13px] text-gray-500">Carico le aree…</p>
                            <p v-else-if="aree.errore" class="mt-1 text-[13px] text-red-700">{{ aree.errore }}</p>
                            <ul v-else-if="aree.righe.length" class="mt-1 divide-y divide-gray-100" data-test="committenti-aree">
                                <li v-for="a in aree.righe" :key="a.id" class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2">
                                    <div class="min-w-0 flex-1 basis-40"><div class="font-semibold text-gray-900">{{ a.name }}</div><div class="text-xs text-gray-500">{{ [a.locality?.name, a.code].filter(Boolean).join(' · ') }}</div></div>
                                    <span :class="CHIP[(STATO_AREA[a.status] ?? ['', 'neutra'])[1]]">{{ (STATO_AREA[a.status] ?? [a.status])[0] }}</span>
                                    <Link v-if="can('assets.view')" :href="`/patrimonio?area_id=${a.id}`" :class="BOTTONE_PICCOLO">Apri</Link>
                                </li>
                            </ul>
                            <p v-else class="mt-1 text-[13px] text-gray-500">Nessuna area: si crea dalla mappa o da Territorio.</p>
                        </div>
                    </template>
                </aside>
            </div>
        </div>
    </AppLayout>
</template>
