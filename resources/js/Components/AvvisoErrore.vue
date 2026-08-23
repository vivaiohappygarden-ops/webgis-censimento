<script setup>
defineProps({
    messaggio: { type: String, default: '' },
    inCorso: { type: Boolean, default: false },
});

defineEmits(['riprova']);
</script>

<template>
    <div
        v-if="messaggio"
        class="mb-3 flex flex-wrap items-center gap-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700"
        data-test="avviso-errore"
    >
        <span>{{ messaggio }}</span>
        <button
            type="button"
            class="rounded-lg border border-red-300 px-2 py-1 text-xs font-medium text-red-700 hover:bg-red-100 disabled:opacity-50"
            :disabled="inCorso"
            data-test="riprova"
            @click="$emit('riprova')"
        >{{ inCorso ? 'Riprovo' : 'Riprova' }}</button>
    </div>
</template>
