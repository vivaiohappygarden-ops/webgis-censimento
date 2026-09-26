<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

/*
 * La testata di Lavori nella veste nuova: titolo e schede (Ordini, Agenda,
 * Gantt, Segnalazioni, Ispezioni). La portano la pagina nuova dei lavori e,
 * finche' non vengono rifatte, le pagine di prima delle segnalazioni e delle
 * ispezioni.
 */
const props = defineProps({
    attiva: { type: String, required: true },
    titolo: { type: String, default: 'Lavori' },
    // Conteggi da mostrare accanto alle schede, se la pagina li conosce
    conteggi: { type: Object, default: () => ({}) },
});

const page = usePage();
const can = (permesso) => (page.props.auth?.user?.permissions ?? []).includes(permesso);

const schede = computed(() => [
    { chiave: 'ordini', label: 'Ordini', href: '/lavori' },
    { chiave: 'agenda', label: 'Agenda', href: '/lavori?vista=agenda' },
    { chiave: 'gantt', label: 'Gantt', href: '/lavori?vista=gantt' },
    { chiave: 'segnalazioni', label: 'Segnalazioni', href: '/segnalazioni' },
    { chiave: 'ispezioni', label: 'Ispezioni', href: '/ispezioni' },
].filter(() => can('works.view')));
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-3" data-test="testata-lavori">
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
            <h1 class="text-2xl font-bold text-gray-900">{{ props.titolo }}</h1>
            <nav
                class="flex flex-wrap gap-2 md:inline-flex md:gap-0 md:overflow-hidden md:rounded-lg md:border md:border-gray-300 md:bg-white"
                aria-label="Sezioni dei lavori"
            >
                <Link
                    v-for="s in schede"
                    :key="s.chiave"
                    :href="s.href"
                    class="inline-flex min-h-11 items-center rounded-lg border px-3.5 text-sm font-semibold transition md:min-h-9 md:rounded-none md:border-0 md:border-r md:border-gray-200 md:last:border-r-0"
                    :class="s.chiave === props.attiva ? 'border-green-700 bg-green-700 text-white' : 'border-gray-300 text-gray-700 hover:bg-gray-50'"
                    :aria-current="s.chiave === props.attiva ? 'page' : undefined"
                    :data-test="`scheda-${s.chiave}`"
                >{{ s.label }}<template v-if="props.conteggi[s.chiave] !== undefined"> · {{ props.conteggi[s.chiave] }}</template></Link>
            </nav>
        </div>
        <div class="flex flex-wrap gap-2"><slot /></div>
    </div>
</template>
