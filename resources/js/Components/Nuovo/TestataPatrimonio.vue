<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

/*
 * La testata di Patrimonio nella veste nuova: titolo e le sue schede
 * (Elenco, Mappa, Alberi e VTA, Irrigazione). La porta la pagina nuova
 * dell'elenco e, finche' non vengono rifatte, anche le pagine di prima della
 * mappa, dello scadenzario VTA e dell'irrigazione: cosi' da qualunque scheda
 * si passa alle altre senza tornare al menu.
 */
const props = defineProps({
    attiva: { type: String, required: true },
    titolo: { type: String, default: 'Patrimonio' },
});

const page = usePage();
const can = (permesso) => (page.props.auth?.user?.permissions ?? []).includes(permesso);

const schede = computed(() => [
    { chiave: 'elenco', label: 'Elenco', href: '/patrimonio', show: can('assets.view') },
    { chiave: 'mappa', label: 'Mappa', href: '/mappa', show: can('assets.view') },
    { chiave: 'alberi', label: 'Alberi e VTA', href: '/vta', show: can('assets.view') },
    { chiave: 'irrigazione', label: 'Irrigazione', href: '/irrigazione', show: can('areas.view') },
].filter((s) => s.show));
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-3" data-test="testata-patrimonio">
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
            <h1 class="text-2xl font-bold text-gray-900">{{ props.titolo }}</h1>
            <nav
                class="flex flex-wrap gap-2 md:inline-flex md:gap-0 md:overflow-hidden md:rounded-lg md:border md:border-gray-300 md:bg-white"
                aria-label="Sezioni del patrimonio"
            >
                <Link
                    v-for="s in schede"
                    :key="s.chiave"
                    :href="s.href"
                    class="inline-flex min-h-11 items-center rounded-lg border px-3.5 text-sm font-semibold transition md:min-h-9 md:rounded-none md:border-0 md:border-r md:border-gray-200 md:last:border-r-0"
                    :class="s.chiave === props.attiva ? 'border-green-700 bg-green-700 text-white' : 'border-gray-300 text-gray-700 hover:bg-gray-50'"
                    :aria-current="s.chiave === props.attiva ? 'page' : undefined"
                    :data-test="`scheda-${s.chiave}`"
                >{{ s.label }}</Link>
            </nav>
        </div>
        <div class="flex flex-wrap gap-2"><slot /></div>
    </div>
</template>
