<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { schedeVisibili } from '@/nuovo/sezioni';

/*
 * Testata generica di una sezione della veste nuova: titolo, schede e i
 * pulsanti passati nello slot. Le schede arrivano da resources/js/nuovo/sezioni.js.
 */
const props = defineProps({
    titolo: { type: String, required: true },
    sottotitolo: { type: String, default: '' },
    attiva: { type: String, required: true },
    schede: { type: Array, required: true },
});

const page = usePage();
const visibili = computed(() => schedeVisibili(props.schede, page.props.auth?.user?.permissions ?? []));
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-3" :data-test="`testata-${props.schede[0]?.chiave ?? 'sezione'}`">
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ props.titolo }}</h1>
                <p v-if="props.sottotitolo" class="text-[13px] text-gray-500">{{ props.sottotitolo }}</p>
            </div>
            <nav
                v-if="visibili.length > 1"
                class="flex flex-wrap gap-2 md:inline-flex md:gap-0 md:overflow-hidden md:rounded-lg md:border md:border-gray-300 md:bg-white"
                :aria-label="`Sezioni di ${props.titolo}`"
            >
                <Link
                    v-for="s in visibili"
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
