<script setup>
import { Head, useForm } from '@inertiajs/vue3';

// La porta d'ingresso delle imprese appaltatrici: stessa serratura del
// gestionale (il POST va a /login), parole pensate per la ditta. Dopo
// l'accesso si atterra direttamente su "I lavori affidati".

const form = useForm({
    email: '',
    password: '',
    organization: '',
    remember: true,
});

const submit = () => form.post('/login', { onFinish: () => form.reset('password') });
</script>

<template>
    <Head title="Area imprese" />

    <div class="flex min-h-screen items-center justify-center bg-gradient-to-br from-stone-100 to-green-50 p-4">
        <div class="w-full max-w-md rounded-2xl bg-white p-8 shadow-xl">
            <div class="mb-8 text-center">
                <p class="text-xs font-semibold uppercase tracking-widest text-green-700">Area imprese appaltatrici</p>
                <h1 class="mt-1 text-xl font-semibold">I lavori affidati</h1>
                <p class="mt-1 text-sm text-gray-500">
                    Ordini di lavoro, date e prescrizioni dei cantieri affidati alla tua impresa.
                </p>
            </div>

            <form class="space-y-4" @submit.prevent="submit">
                <div>
                    <label class="mb-1 block text-sm font-medium" for="email">Email</label>
                    <input
                        id="email"
                        v-model="form.email"
                        type="email"
                        required
                        autofocus
                        autocomplete="username"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-green-600 focus:outline-none focus:ring-1 focus:ring-green-600"
                    >
                    <p v-if="form.errors.email" class="mt-1 text-sm text-red-600">{{ form.errors.email }}</p>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium" for="password">Password</label>
                    <input
                        id="password"
                        v-model="form.password"
                        type="password"
                        required
                        autocomplete="current-password"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-green-600 focus:outline-none focus:ring-1 focus:ring-green-600"
                    >
                    <p v-if="form.errors.password" class="mt-1 text-sm text-red-600">{{ form.errors.password }}</p>
                </div>

                <div v-if="form.errors.organization || form.organization">
                    <label class="mb-1 block text-sm font-medium" for="organization">Organizzazione (slug)</label>
                    <input
                        id="organization"
                        v-model="form.organization"
                        type="text"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-green-600 focus:outline-none focus:ring-1 focus:ring-green-600"
                    >
                    <p v-if="form.errors.organization" class="mt-1 text-sm text-red-600">{{ form.errors.organization }}</p>
                </div>

                <label class="flex items-center gap-2 text-sm text-gray-600">
                    <input v-model="form.remember" type="checkbox" class="rounded border-gray-300 text-green-600">
                    Resta collegato su questo dispositivo
                </label>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="w-full rounded-lg bg-green-700 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-green-800 disabled:opacity-50"
                >
                    {{ form.processing ? 'Accesso in corso…' : 'Entra' }}
                </button>
            </form>

            <p class="mt-6 rounded-lg bg-gray-50 px-3 py-2.5 text-xs text-gray-500">
                Le credenziali le rilascia la ditta di manutenzione del verde che ti ha
                affidato i lavori: se non le hai o le hai smarrite, chiedi a lei.
            </p>

            <p class="mt-4 text-center text-xs text-gray-400">
                Lavori per il gestore del verde?
                <a href="/login" class="text-green-700 hover:underline">Accesso al gestionale</a>
            </p>
        </div>
    </div>
</template>
