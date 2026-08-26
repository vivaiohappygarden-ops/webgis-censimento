<script setup>
import ScegliVoce from '@/Components/ScegliVoce.vue';

/*
 * Scelta del committente: la ricerca a parole di ScegliVoce, con i campi
 * dell'anagrafica (nome, codice, partita IVA, codice fiscale).
 */
const props = defineProps({
    modelValue: { type: [String, null], default: '' },
    committenti: { type: Array, default: () => [] },
    tutti: { type: String, default: null },
    campoClasse: { type: String, default: 'px-2.5 py-1.5 text-sm' },
});

const emit = defineEmits(['update:modelValue', 'cambia']);
</script>

<template>
    <ScegliVoce
        :model-value="props.modelValue"
        :voci="props.committenti"
        :campi-ricerca="['name', 'code', 'vat_number', 'fiscal_code']"
        :tutti="props.tutti"
        :campo-classe="props.campoClasse"
        vuoto="Nessun committente trovato."
        @update:model-value="(v) => emit('update:modelValue', v)"
        @cambia="(v) => emit('cambia', v)"
    />
</template>
