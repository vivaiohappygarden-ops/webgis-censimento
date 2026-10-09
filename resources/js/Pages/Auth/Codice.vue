<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';

/*
 * Il secondo passaggio dell'accesso: la password e' gia' passata, manca il
 * codice a sei cifre dell'app di autenticazione. Chi non ha il telefono usa
 * uno dei codici di recupero salvati all'attivazione.
 */
const props = defineProps({
    email: { type: String, default: '' },
    ritorno: { type: String, default: '/login' },
});

const form = useForm({ codice: '' });
const recupero = ref(false);

const submit = () => form.post('/login/codice', { onFinish: () => form.reset('codice') });

function cambiaModo() {
    recupero.value = ! recupero.value;
    form.codice = '';
    form.clearErrors();
}
</script>

<template>
    <Head title="Verifica in due passaggi" />

    <div class="flex min-h-screen items-center justify-center bg-gray-100 p-4">
        <div class="w-full max-w-md rounded-sm border border-gray-300 bg-white p-8">
            <div class="mb-6 text-center">
                <h1 class="text-xl font-semibold">Verifica in due passaggi</h1>
                <p class="mt-1 text-sm text-gray-500">Accesso di <span class="font-medium text-gray-700">{{ props.email }}</span></p>
            </div>

            <form class="space-y-4" data-test="modulo-codice" @submit.prevent="submit">
                <p class="text-sm text-gray-700" data-test="istruzioni-codice">
                    <template v-if="! recupero">Apri l'app di autenticazione sul telefono e inserisci il codice a sei cifre che mostra per questo programma.</template>
                    <template v-else>Inserisci uno dei codici di recupero salvati quando hai attivato la verifica. Ogni codice vale una volta sola.</template>
                </p>

                <div>
                    <label class="mb-1 block text-sm font-medium" for="codice">{{ recupero ? 'Codice di recupero' : 'Codice a sei cifre' }}</label>
                    <input
                        id="codice"
                        v-model="form.codice"
                        type="text"
                        required
                        autofocus
                        :inputmode="recupero ? 'text' : 'numeric'"
                        autocomplete="one-time-code"
                        maxlength="14"
                        :placeholder="recupero ? 'XXXXX-XXXXX' : '000000'"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-center text-lg tabular-nums focus:border-green-600 focus:outline-none focus:ring-1 focus:ring-green-600"
                        :class="recupero ? 'font-mono uppercase' : 'tracking-[0.3em]'"
                        data-test="campo-codice"
                    >
                    <p v-if="form.errors.codice" class="mt-1 text-sm text-red-600" data-test="errore-codice">{{ form.errors.codice }}</p>
                </div>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="min-h-11 w-full rounded-lg bg-green-700 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-green-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-green-700 disabled:opacity-60"
                >
                    {{ form.processing ? 'Verifica in corso…' : 'Conferma' }}
                </button>

                <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 text-sm">
                    <button type="button" class="min-h-11 text-left font-medium text-green-800 hover:underline" data-test="cambia-modo" @click="cambiaModo">
                        {{ recupero ? "Usa il codice dell'app" : 'Non hai il telefono? Usa un codice di recupero' }}
                    </button>
                    <a :href="props.ritorno" class="inline-flex min-h-11 items-center text-gray-600 hover:underline">Torna all'accesso</a>
                </div>
            </form>
        </div>
    </div>
</template>
