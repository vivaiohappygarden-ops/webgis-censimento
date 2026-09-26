<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import TestataSezione from '@/Components/Nuovo/TestataSezione.vue';
import { SCHEDE_IMPOSTAZIONI } from '@/nuovo/sezioni';
import { BOTTONE, BOTTONE_SECONDARIO, CARTA, CHIP } from '@/nuovo/stile';

/*
 * Impostazioni (bozza A, schermata 10): tutto cio' che si regola una volta e
 * vale per tutti, in un posto solo. Ogni voce porta alla pagina che gia' fa
 * quel lavoro (utenti e ruoli, firma dei documenti, catalogo, listini,
 * squadre, portali); qui in piu' c'e' la scelta dell'interfaccia.
 */
const props = defineProps({ dominioPortali: { type: String, default: '' } });
const page = usePage();
const permessi = computed(() => page.props.auth?.user?.permissions ?? []);
const can = (p) => permessi.value.includes(p);
const interfaccia = computed(() => page.props.interfaccia ?? { modo: 'nuova', predefinita: 'nuova' });
const nuova = computed(() => interfaccia.value.modo === 'nuova');

const numeri = ref({ utenti: null, ruoli: null, squadre: null });
onMounted(async () => {
    if (! can('users.manage')) return;
    try {
        const [u, r, t] = await Promise.all([axios.get('/api/v1/users', { params: { per_page: 1 } }), axios.get('/api/v1/roles'), axios.get('/api/v1/teams')]);
        numeri.value = { utenti: u.data.total ?? u.data.data?.length ?? null, ruoli: r.data.data?.length ?? null, squadre: t.data.data?.length ?? null };
    } catch {
        numeri.value = { utenti: null, ruoli: null, squadre: null };
    }
});

const voci = computed(() => [
    { titolo: 'Utenti e ruoli', testo: 'Chi entra nel programma e che cosa può fare. I cinque ruoli di serie restano; se ne creano di nuovi su misura.', href: '/utenti', show: can('users.manage'), numero: numeri.value.utenti !== null ? `${numeri.value.utenti} utenti · ${numeri.value.ruoli} ruoli` : null },
    { titolo: 'Azienda e firma dei documenti', testo: 'Intestazione, professionista firmatario e luogo della riga "Luogo, data" sopra la firma di perizie, bilanci e registri.', href: '/utenti#firma', show: can('users.manage') },
    { titolo: 'Intervalli di ricontrollo VTA', testo: 'I mesi di ricontrollo per classe di propensione al cedimento, usati dallo scadenzario e dalla pagina Oggi.', href: '/utenti#vta-intervalli', show: can('users.manage') },
    { titolo: 'Squadre e imprese esterne', testo: "Le squadre interne e le imprese dei committenti, con l'accesso al portale delle imprese.", href: '/utenti#squadre', show: can('users.manage'), numero: numeri.value.squadre !== null ? `${numeri.value.squadre} squadre` : null },
    { titolo: 'Catalogo degli oggetti', testo: 'I tipi del Modello Dati (immutabili) e i tipi personalizzati con i campi aggiuntivi delle schede.', href: '/catalogo', show: can('catalog.view') },
    { titolo: 'Listini prezzi', testo: 'Le voci di prezzo per lavorazione, con cui si valorizzano ordini, preventivi e SAL.', href: '/listini', show: can('works.view') },
    { titolo: 'Patentini e certificati', testo: 'Le abilitazioni del personale con le scadenze, che compaiono in Oggi quando si avvicinano.', href: '/patentini', show: can('works.view') },
    { titolo: 'Portali pubblici e dominio', testo: props.dominioPortali ? `I portali dei Comuni escono su ${props.dominioPortali}: si accendono committente per committente.` : 'I portali dei Comuni si accendono committente per committente; il dominio si collega dal server.', href: '/committenti', show: can('clients.view') },
    { titolo: 'Collegamento al gestionale giardini', testo: 'Le impostazioni per inviare gli elementi al gestionale giardini (WordPress).', href: '/utenti#gestionale', show: can('users.manage') },
    { titolo: 'App di campo', testo: "L'app per il telefono: rilievi, misure, foto e cartellini anche senza rete. Le istruzioni stanno nella Guida.", href: '/operatore', show: can('assets.create') },
].filter((v) => v.show));

const cambiaInterfaccia = () => router.post('/interfaccia', { modo: nuova.value ? 'precedente' : 'nuova' });
</script>

<template>
    <Head title="Impostazioni" />

    <AppLayout>
        <div class="mx-auto flex max-w-[1640px] flex-col gap-4 p-4 md:p-6 lg:px-7">
            <TestataSezione titolo="Impostazioni" sottotitolo="Tutto ciò che si regola una volta e vale per tutti, in un posto solo" attiva="impostazioni" :schede="SCHEDE_IMPOSTAZIONI" />

            <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_380px] lg:items-start">
                <section class="grid gap-3 sm:grid-cols-2" data-test="impostazioni-voci">
                    <Link v-for="v in voci" :key="v.titolo" :href="v.href" :class="CARTA" class="flex min-h-11 flex-col gap-1 p-4 transition hover:border-green-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-green-700">
                        <span class="text-base font-bold text-gray-900">{{ v.titolo }}</span>
                        <span class="text-[13px] text-gray-600">{{ v.testo }}</span>
                        <span v-if="v.numero" class="mt-1 text-[13px] font-semibold text-gray-900">{{ v.numero }}</span>
                    </Link>
                    <p v-if="! voci.length" class="text-sm text-gray-500">Con i permessi di questo utente non ci sono impostazioni da regolare, tranne l'interfaccia qui a fianco.</p>
                </section>

                <aside class="flex min-w-0 flex-col gap-4">
                    <section :class="CARTA" class="p-4" data-test="impostazioni-interfaccia">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h2 class="text-base font-bold text-gray-900">Interfaccia</h2>
                            <span :class="nuova ? CHIP.ok : CHIP.neutra">{{ nuova ? 'nuova, in prova' : 'precedente' }}</span>
                        </div>
                        <p class="mt-2 text-sm text-gray-700">Stessi dati, stesse funzioni, ordinate per compiti: Oggi, Patrimonio, Lavori, Documenti, Committenti, Impostazioni. Chi preferisce la versione precedente la riattiva da qui, per il proprio utente, finché non si decide per tutti{{ interfaccia.predefinita === 'nuova' ? ' (di serie parte la nuova)' : ' (di serie parte la precedente)' }}.</p>
                        <button type="button" :class="nuova ? BOTTONE_SECONDARIO : BOTTONE" class="mt-3" data-test="impostazioni-cambia-interfaccia" @click="cambiaInterfaccia">{{ nuova ? 'Torna alla versione precedente' : 'Passa alla nuova interfaccia' }}</button>
                    </section>
                    <section :class="CARTA" class="p-4">
                        <h2 class="text-base font-bold text-gray-900">Aggiornamenti</h2>
                        <p class="mt-1 text-sm text-gray-700">Il programma si aggiorna dal server: le novità arrivano da sole, senza fermare il lavoro. Se qualcosa "a volte non funziona", la diagnostica si lancia sul server dall'assistenza.</p>
                    </section>
                </aside>
            </div>
        </div>
    </AppLayout>
</template>
