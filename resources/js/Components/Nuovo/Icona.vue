<script setup>
import { ICONE } from '@/nuovo/icone';

/*
 * Un'icona della veste "Lo strumento": <Icona nome="oggi" />. I disegni stanno
 * in resources/js/nuovo/icone.js. Sono decorative per lo screen reader
 * (aria-hidden): il testo accanto dice tutto.
 */
const props = defineProps({
    nome: { type: String, required: true },
    size: { type: [Number, String], default: 18 },
});
</script>

<template>
    <svg
        viewBox="0 0 20 20"
        :width="props.size"
        :height="props.size"
        fill="none"
        stroke="currentColor"
        stroke-width="1.5"
        stroke-linecap="square"
        stroke-linejoin="miter"
        aria-hidden="true"
        class="shrink-0"
        v-html="ICONE[props.nome] ?? ''"
    />
</template>
