<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import TestataSezione from '@/Components/Nuovo/TestataSezione.vue';
import AvvisoErrore from '@/Components/AvvisoErrore.vue';
import { usaCaricamento } from '@/caricamento';
import { messaggioErrore } from '@/avvisi';
import { BOTTONE, BOTTONE_SECONDARIO, CARTA, CHIP, ETICHETTA } from '@/nuovo/stile';

/*
 * Il mio accesso: la verifica in due passaggi e la password del proprio
 * utente, per chiunque entri nel programma; per chi gestisce gli utenti anche
 * la regola dell'organizzazione (chi deve avere la verifica). Ogni operazione
 * delicata chiede di nuovo la password: una sessione lasciata aperta non
 * basta a spegnere la verifica o a cambiare la chiave.
 */
const page = usePage();
const nuova = computed(() => page.props.interfaccia?.modo === 'nuova');
const permessi = computed(() => page.props.auth?.user?.permissions ?? []);
const can = (p) => permessi.value.includes(p);
const parametri = new URLSearchParams(window.location.search);
const recuperoUsato = parametri.get('recupero') === '1';
const arrivatoPerObbligo = parametri.get('obbligatoria') === '1';

// Bersagli da 44 px sul telefono, 38 con il mouse, come i pulsanti di stile.js
const CAMPO = 'min-h-11 w-full max-w-sm rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-green-600 focus:outline-none focus:ring-1 focus:ring-green-600 md:min-h-[38px]';

const { avviso, riprovaInCorso, carica, riprova } = usaCaricamento();
const stato = ref(null);
const due = computed(() => stato.value?.due_fattori ?? { attiva: false, obbligatoria: false, codici_recupero: 0, attiva_dal: null, in_attesa: false });

async function caricaStato() {
    const { data } = await axios.get('/api/v1/profilo/sicurezza');
    stato.value = data.data;
}

// --- Verifica in due passaggi ------------------------------------------------
// passo: stato | password | qr | recupero (i codici appena generati)
// scopo: che cosa fare dopo la password (avvia | codici | disattiva)
const passo = ref('stato');
const scopo = ref('avvia');
const password = ref('');
const codice = ref('');
const avvio = ref(null);
const codici = ref([]);
const busy = ref(false);
const errore = ref('');
const copiato = ref(false);

function chiediPassword(perche) {
    scopo.value = perche;
    password.value = '';
    errore.value = '';
    passo.value = 'password';
}

function annulla() {
    passo.value = 'stato';
    password.value = '';
    codice.value = '';
    errore.value = '';
    avvio.value = null;
}

async function conPassword() {
    busy.value = true;
    errore.value = '';
    try {
        if (scopo.value === 'avvia') {
            const { data } = await axios.post('/api/v1/profilo/due-fattori/avvia', { password: password.value });
            avvio.value = data.data;
            codice.value = '';
            passo.value = 'qr';
        } else if (scopo.value === 'codici') {
            const { data } = await axios.post('/api/v1/profilo/due-fattori/codici', { password: password.value });
            codici.value = data.data.codici_recupero;
            passo.value = 'recupero';
            await caricaStato();
        } else {
            await axios.delete('/api/v1/profilo/due-fattori', { data: { password: password.value } });
            passo.value = 'stato';
            await caricaStato();
        }
    } catch (err) {
        errore.value = messaggioErrore(err, 'Operazione non riuscita');
    } finally {
        password.value = '';
        busy.value = false;
    }
}

async function conferma() {
    busy.value = true;
    errore.value = '';
    try {
        const { data } = await axios.post('/api/v1/profilo/due-fattori/conferma', { codice: codice.value });
        codici.value = data.data.codici_recupero;
        passo.value = 'recupero';
        await caricaStato();
    } catch (err) {
        errore.value = messaggioErrore(err, 'Conferma non riuscita');
    } finally {
        busy.value = false;
    }
}

async function copiaCodici() {
    try {
        await navigator.clipboard.writeText(codici.value.join('\n'));
        copiato.value = true;
        setTimeout(() => { copiato.value = false; }, 2000);
    } catch {
        copiato.value = false;
    }
}

function chiudiCodici() {
    codici.value = [];
    passo.value = 'stato';
    // Chi era stato mandato qui dall'obbligo puo' tornare al lavoro
    if (arrivatoPerObbligo && due.value.attiva) router.visit('/');
}

// --- Password ----------------------------------------------------------------
const pw = reactive({ attuale: '', nuova: '', ripeti: '', busy: false, errore: '', fatto: false });

async function cambiaPassword() {
    pw.busy = true;
    pw.errore = '';
    pw.fatto = false;
    try {
        await axios.put('/api/v1/profilo/password', { password_attuale: pw.attuale, password: pw.nuova, password_confirmation: pw.ripeti });
        pw.fatto = true;
        pw.attuale = '';
        pw.nuova = '';
        pw.ripeti = '';
    } catch (err) {
        pw.errore = messaggioErrore(err, 'Cambio non riuscito');
    } finally {
        pw.busy = false;
    }
}

// --- Regola dell'organizzazione -----------------------------------------------
const REGOLE = [
    { valore: 'nessuno', label: 'Nessuno', testo: 'Ognuno decide per sé.' },
    { valore: 'amministratori', label: 'Gli amministratori', testo: 'Chi gestisce utenti e impostazioni. Il minimo consigliato.' },
    { valore: 'tutti', label: 'Tutti gli utenti', testo: 'Compresi operatori, imprese esterne e uffici dei Comuni.' },
];
const regola = reactive({ dati: null, scelta: 'nessuno', busy: false, errore: '', fatto: false });
const scoperti = computed(() => regola.dati?.scoperti?.[regola.scelta] ?? 0);

async function caricaRegola() {
    if (! can('users.manage')) return;
    const { data } = await axios.get('/api/v1/sicurezza/regola');
    regola.dati = data.data;
    regola.scelta = data.data.regola;
}

async function salvaRegola() {
    regola.busy = true;
    regola.errore = '';
    regola.fatto = false;
    try {
        await axios.put('/api/v1/sicurezza/regola', { regola: regola.scelta });
        await Promise.all([caricaRegola(), caricaStato()]);
        regola.fatto = true;
    } catch (err) {
        regola.errore = messaggioErrore(err, 'Salvataggio non riuscito');
    } finally {
        regola.busy = false;
    }
}

function formatData(iso) {
    if (! iso) return '';
    const d = new Date(iso);
    return `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}/${d.getFullYear()}`;
}

onMounted(() => carica(() => Promise.all([caricaStato(), caricaRegola()])));
</script>

<template>
    <Head title="Il mio accesso" />

    <AppLayout>
        <div class="mx-auto flex max-w-[1100px] flex-col gap-4 p-4 md:p-6 lg:px-7">
            <TestataSezione v-if="nuova" titolo="Il mio accesso" sottotitolo="La password e la verifica in due passaggi del tuo utente" attiva="sicurezza" :schede="[]" />
            <div v-else>
                <h1 class="text-lg font-semibold">Il mio accesso</h1>
                <p class="text-sm text-gray-500">La password e la verifica in due passaggi del tuo utente</p>
            </div>

            <AvvisoErrore :messaggio="avviso" :in-corso="riprovaInCorso" @riprova="riprova" />

            <div v-if="stato && due.obbligatoria && ! due.attiva" class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900" data-test="avviso-obbligatoria">
                <strong>La tua organizzazione richiede la verifica in due passaggi.</strong>
                Finché non la attivi qui sotto, le altre pagine del programma restano chiuse. Servono un telefono e un'app di autenticazione gratuita: bastano due minuti.
            </div>
            <div v-if="recuperoUsato && stato" class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900" data-test="avviso-recupero">
                <strong>Sei entrato con un codice di recupero.</strong>
                Te ne restano {{ due.codici_recupero }}. Se hai cambiato telefono, disattiva la verifica e riattivala con quello nuovo; se i codici stanno finendo, generane di nuovi.
            </div>

            <div class="grid gap-4 lg:grid-cols-2 lg:items-start">
                <section :class="CARTA" class="p-5" data-test="carta-due-fattori">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-base font-bold text-gray-900">Verifica in due passaggi</h2>
                        <span v-if="stato" :class="due.attiva ? CHIP.ok : CHIP.neutra" data-test="stato-due-fattori">{{ due.attiva ? 'attiva' : 'non attiva' }}</span>
                    </div>
                    <p class="mt-2 text-sm text-gray-700">
                        Oltre alla password, all'accesso si inserisce un codice a sei cifre che cambia ogni trenta secondi,
                        generato da un'app sul telefono (Google Authenticator, Microsoft Authenticator, FreeOTP o simili).
                        Chi scopre la password, senza il telefono non entra.
                    </p>

                    <template v-if="stato && passo === 'stato'">
                        <dl v-if="due.attiva" class="mt-4 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                            <div>
                                <dt :class="ETICHETTA">Attiva dal</dt>
                                <dd class="font-semibold text-gray-900">{{ formatData(due.attiva_dal) }}</dd>
                            </div>
                            <div>
                                <dt :class="ETICHETTA">Codici di recupero non usati</dt>
                                <dd class="font-semibold text-gray-900" data-test="codici-rimasti">{{ due.codici_recupero }}</dd>
                            </div>
                        </dl>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <button v-if="! due.attiva" type="button" :class="BOTTONE" data-test="attiva-due-fattori" @click="chiediPassword('avvia')">Attiva</button>
                            <template v-else>
                                <button type="button" :class="BOTTONE_SECONDARIO" data-test="nuovi-codici" @click="chiediPassword('codici')">Nuovi codici di recupero</button>
                                <button v-if="! due.obbligatoria" type="button" :class="BOTTONE_SECONDARIO" class="!text-red-700" data-test="disattiva-due-fattori" @click="chiediPassword('disattiva')">Disattiva</button>
                            </template>
                        </div>
                        <p v-if="due.attiva && due.obbligatoria" class="mt-2 text-[13px] text-gray-500">
                            La regola della tua organizzazione non permette di disattivarla. Per passare a un altro telefono chiedi all'amministratore di azzerarla.
                        </p>
                    </template>

                    <form v-if="passo === 'password'" class="mt-4 space-y-3" data-test="modulo-password" @submit.prevent="conPassword">
                        <p class="text-sm text-gray-700">
                            <template v-if="scopo === 'avvia'">Per cominciare, conferma la tua password.</template>
                            <template v-else-if="scopo === 'codici'">Verranno generati otto codici nuovi e quelli vecchi smetteranno di valere. Conferma la tua password.</template>
                            <template v-else>La verifica verrà spenta e all'accesso basterà di nuovo la password. Conferma la tua password.</template>
                        </p>
                        <div>
                            <label class="mb-1 block text-sm font-medium" for="password-conferma">Password</label>
                            <input id="password-conferma" v-model="password" type="password" required autocomplete="current-password" :class="CAMPO" data-test="password-conferma">
                        </div>
                        <p v-if="errore" class="text-sm text-red-600" data-test="errore-due-fattori">{{ errore }}</p>
                        <div class="flex flex-wrap gap-2">
                            <button type="submit" :class="scopo === 'disattiva' ? BOTTONE_SECONDARIO : BOTTONE" :disabled="busy" data-test="continua">{{ scopo === 'disattiva' ? 'Disattiva la verifica' : 'Continua' }}</button>
                            <button type="button" :class="BOTTONE_SECONDARIO" @click="annulla">Annulla</button>
                        </div>
                    </form>

                    <div v-if="passo === 'qr' && avvio" class="mt-4" data-test="passo-qr">
                        <ol class="list-decimal space-y-1 pl-5 text-sm text-gray-700">
                            <li>Sul telefono apri l'app di autenticazione (se non ce l'hai, installane una gratuita dallo store).</li>
                            <li>Scegli "Aggiungi" e inquadra questo codice, oppure scrivi la chiave a mano.</li>
                            <li>Scrivi qui sotto il codice a sei cifre che l'app mostra.</li>
                        </ol>
                        <div class="mt-3 flex flex-wrap items-start gap-4">
                            <img :src="avvio.qr" alt="Codice QR da inquadrare con l'app di autenticazione" class="h-52 w-52 rounded border border-gray-200 bg-white" data-test="qr">
                            <div class="min-w-0 text-sm">
                                <div :class="ETICHETTA">Chiave da scrivere a mano</div>
                                <div class="mt-1 break-all font-mono text-base tracking-wider text-gray-900" data-test="segreto">{{ avvio.segreto_leggibile }}</div>
                                <p class="mt-1 text-[13px] text-gray-500">Tipo: basato sul tempo (TOTP), sei cifre, trenta secondi.</p>
                            </div>
                        </div>
                        <form class="mt-4 space-y-3" @submit.prevent="conferma">
                            <div>
                                <label class="mb-1 block text-sm font-medium" for="codice-conferma">Codice dell'app</label>
                                <input id="codice-conferma" v-model="codice" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="8" required class="w-40 rounded-lg border border-gray-300 px-3 py-2 text-center text-lg tabular-nums tracking-[0.3em] focus:border-green-600 focus:outline-none focus:ring-1 focus:ring-green-600" data-test="codice-conferma">
                            </div>
                            <p v-if="errore" class="text-sm text-red-600" data-test="errore-due-fattori">{{ errore }}</p>
                            <div class="flex flex-wrap gap-2">
                                <button type="submit" :class="BOTTONE" :disabled="busy" data-test="conferma-codice">Conferma e attiva</button>
                                <button type="button" :class="BOTTONE_SECONDARIO" @click="annulla">Annulla</button>
                            </div>
                        </form>
                    </div>

                    <div v-if="passo === 'recupero'" class="mt-4" data-test="codici-recupero">
                        <p class="text-sm text-gray-700">
                            <strong>Salva questi codici</strong> in un posto sicuro, non sul telefono che genera i codici.
                            Servono se perdi il telefono: ognuno vale una volta sola e da qui non si rivedono più.
                        </p>
                        <ul class="mt-3 grid grid-cols-2 gap-x-6 gap-y-1 font-mono text-base tabular-nums text-gray-900 sm:max-w-sm">
                            <li v-for="c in codici" :key="c" data-test="codice-recupero">{{ c }}</li>
                        </ul>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <button type="button" :class="BOTTONE_SECONDARIO" data-test="copia-codici" @click="copiaCodici">{{ copiato ? 'Copiati' : 'Copia i codici' }}</button>
                            <button type="button" :class="BOTTONE" data-test="ho-salvato" @click="chiudiCodici">Ho salvato i codici</button>
                        </div>
                    </div>
                </section>

                <section :class="CARTA" class="p-5" data-test="carta-password">
                    <h2 class="text-base font-bold text-gray-900">Password</h2>
                    <p class="mt-2 text-sm text-gray-700">Almeno dieci caratteri. Cambiandola, le altre sessioni aperte con quella vecchia si chiudono.</p>
                    <form class="mt-4 space-y-3" data-test="modulo-cambia-password" @submit.prevent="cambiaPassword">
                        <div>
                            <label class="mb-1 block text-sm font-medium" for="pw-attuale">Password attuale</label>
                            <input id="pw-attuale" v-model="pw.attuale" type="password" required autocomplete="current-password" :class="CAMPO" data-test="pw-attuale">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium" for="pw-nuova">Nuova password</label>
                            <input id="pw-nuova" v-model="pw.nuova" type="password" required minlength="10" autocomplete="new-password" :class="CAMPO" data-test="pw-nuova">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium" for="pw-ripeti">Ripeti la nuova password</label>
                            <input id="pw-ripeti" v-model="pw.ripeti" type="password" required minlength="10" autocomplete="new-password" :class="CAMPO" data-test="pw-ripeti">
                        </div>
                        <p v-if="pw.errore" class="text-sm text-red-600" data-test="errore-password">{{ pw.errore }}</p>
                        <p v-if="pw.fatto" class="text-sm text-green-800" data-test="password-cambiata">Password cambiata.</p>
                        <button type="submit" :class="BOTTONE" :disabled="pw.busy" data-test="cambia-password">Cambia password</button>
                    </form>
                </section>

                <section v-if="can('users.manage') && regola.dati" :class="CARTA" class="p-5 lg:col-span-2" data-test="carta-regola">
                    <h2 class="text-base font-bold text-gray-900">Regola dell'organizzazione</h2>
                    <p class="mt-2 text-sm text-gray-700">
                        Chi deve avere la verifica in due passaggi. Chi ne è obbligato e non l'ha ancora attivata, al prossimo
                        accesso trova solo questa pagina finché non la attiva. Oggi {{ regola.dati.con_verifica }} utenti attivi
                        su {{ regola.dati.utenti_attivi }} ce l'hanno.
                    </p>
                    <fieldset class="mt-3 grid gap-2 sm:grid-cols-3">
                        <legend class="sr-only">Chi deve avere la verifica in due passaggi</legend>
                        <label v-for="r in REGOLE" :key="r.valore" class="flex min-h-11 cursor-pointer items-start gap-3 rounded-lg border p-3 text-sm" :class="regola.scelta === r.valore ? 'border-green-700 bg-green-50' : 'border-gray-200'">
                            <input v-model="regola.scelta" type="radio" name="regola" :value="r.valore" class="mt-0.5 h-4 w-4 border-gray-300 text-green-700" :data-test="`regola-${r.valore}`">
                            <span>
                                <span class="block font-semibold text-gray-900">{{ r.label }}</span>
                                <span class="block text-[13px] text-gray-600">{{ r.testo }}</span>
                            </span>
                        </label>
                    </fieldset>
                    <p class="mt-2 text-[13px] text-gray-600" data-test="scoperti">
                        <template v-if="regola.scelta === 'nessuno'">Nessun obbligo.</template>
                        <template v-else-if="scoperti === 0">Tutti gli interessati hanno già la verifica attiva.</template>
                        <template v-else>{{ scoperti === 1 ? '1 utente dovrà attivarla' : `${scoperti} utenti dovranno attivarla` }} al prossimo accesso{{ due.attiva ? '' : ', tu compreso' }}.</template>
                    </p>
                    <p v-if="regola.errore" class="mt-2 text-sm text-red-600">{{ regola.errore }}</p>
                    <p v-if="regola.fatto" class="mt-2 text-sm text-green-800" data-test="regola-salvata">Regola salvata.</p>
                    <button type="button" :class="BOTTONE" class="mt-3" :disabled="regola.busy || regola.scelta === regola.dati.regola" data-test="salva-regola" @click="salvaRegola">Salva la regola</button>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
