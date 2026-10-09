<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import TestataSezione from '@/Components/Nuovo/TestataSezione.vue';
import AvvisoErrore from '@/Components/AvvisoErrore.vue';
import { usaCaricamento } from '@/caricamento';
import { messaggioErrore } from '@/avvisi';
import { BOTTONE, BOTTONE_PICCOLO, BOTTONE_SECONDARIO, CARTA, CHIP, ETICHETTA } from '@/nuovo/stile';

/*
 * La console della piattaforma: tutte le organizzazioni ospitate con i
 * numeri che servono a fatturare, la creazione di una nuova (la stessa
 * procedura del comando tenant:create), la sospensione e l'accesso di
 * assistenza. Solo per chi ha la qualifica di gestore, con la verifica in
 * due passaggi attiva.
 */
const props = defineProps({ dueFattoriAttiva: { type: Boolean, default: false } });
const page = usePage();
const nuova = computed(() => page.props.interfaccia?.modo === 'nuova');
const mioTenant = computed(() => page.props.auth?.user?.tenant_id);

const CAMPO = 'min-h-11 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-green-600 focus:outline-none focus:ring-1 focus:ring-green-600 md:min-h-[38px]';
const { avviso, riprovaInCorso, carica, riprova } = usaCaricamento();
const organizzazioni = ref(null);
const scelta = ref(null);

async function caricaElenco() {
    const { data } = await axios.get('/api/v1/piattaforma/organizzazioni');
    organizzazioni.value = data.data;
    if (scelta.value) scelta.value = data.data.find((o) => o.id === scelta.value.id) ?? null;
}

const numero = new Intl.NumberFormat('it-IT');
const n = (v) => numero.format(v ?? 0);
function spazio(byte) {
    if (! byte) return '0 MB';
    if (byte >= 1024 ** 3) return `${(byte / 1024 ** 3).toLocaleString('it-IT', { maximumFractionDigits: 1 })} GB`;
    return `${Math.max(1, Math.round(byte / 1024 ** 2)).toLocaleString('it-IT')} MB`;
}
function data(iso) {
    if (! iso) return 'mai';
    const d = new Date(iso);
    return `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}/${d.getFullYear()}`;
}
function ora(iso) {
    if (! iso) return '';
    const d = new Date(iso);
    return `${data(iso)} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
}

const totali = computed(() => {
    const o = organizzazioni.value ?? [];
    const somma = (chiave) => o.reduce((acc, x) => acc + (x.numeri?.[chiave] ?? 0), 0);
    return { organizzazioni: o.length, sospese: o.filter((x) => ! x.is_active).length, utenti: somma('utenti'), elementi: somma('elementi'), foto: somma('foto'), spazio: somma('spazio_byte') };
});

// --- Nuova organizzazione ------------------------------------------------------
const creazione = reactive({ aperta: false, busy: false, errore: '', form: { name: '', slug: '', vat_number: '', admin_name: '', admin_email: '' }, slugManuale: false });
const credenziali = ref(null);
const slugDa = (testo) => testo.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 60);
function aggiornaSlug() {
    if (! creazione.slugManuale) creazione.form.slug = slugDa(creazione.form.name);
}
async function crea() {
    creazione.busy = true;
    creazione.errore = '';
    try {
        const { data: risposta } = await axios.post('/api/v1/piattaforma/organizzazioni', creazione.form);
        credenziali.value = risposta.data;
        creazione.aperta = false;
        creazione.form = { name: '', slug: '', vat_number: '', admin_name: '', admin_email: '' };
        creazione.slugManuale = false;
        await caricaElenco();
    } catch (err) {
        creazione.errore = messaggioErrore(err, 'Creazione non riuscita');
    } finally {
        creazione.busy = false;
    }
}
const copiato = ref(false);
async function copiaCredenziali() {
    try {
        await navigator.clipboard.writeText(`${credenziali.value.admin_email}\n${credenziali.value.temporary_password}`);
        copiato.value = true;
        setTimeout(() => { copiato.value = false; }, 2000);
    } catch {
        copiato.value = false;
    }
}

// --- Azioni sull'organizzazione scelta --------------------------------------------
const azione = reactive({ busy: false, errore: '', motivo: '', note: '', chiediMotivo: false, noteSalvate: false });
function scegli(o) {
    scelta.value = o;
    azione.errore = '';
    azione.motivo = '';
    azione.chiediMotivo = false;
    azione.note = o.note ?? '';
    azione.noteSalvate = false;
    Object.assign(funzioni, { gestionale: !! o.gestionale_giardini, salvato: false, errore: '' });
    Object.assign(pacchetto, { valore: o.marche_pacchetto ?? '', utente: '', password: '', url: '', policy: '', apriAccount: false, salvato: false, errore: '' });
}

// Le funzioni accese per l'organizzazione: il collegamento al gestionale
// giardini e' spento di serie per chi affitta il programma e si accende da qui
const funzioni = reactive({ gestionale: false, salvato: false, errore: '' });
async function salvaFunzioni() {
    funzioni.errore = '';
    funzioni.salvato = false;
    await conAzione(async () => {
        const { data } = await axios.put(`/api/v1/piattaforma/organizzazioni/${scelta.value.id}/funzioni`, { gestionale_giardini: funzioni.gestionale });
        scelta.value.gestionale_giardini = data.data.gestionale_giardini;
        const riga = (organizzazioni.value ?? []).find((o) => o.id === scelta.value.id);
        if (riga) riga.gestionale_giardini = data.data.gestionale_giardini;
        funzioni.salvato = true;
    }, 'Salvataggio delle funzioni non riuscito');
    if (azione.errore) {
        funzioni.errore = azione.errore;
        azione.errore = '';
    }
}

// Le marche temporali si vendono a pacchetti: qui si assegna il pacchetto a
// un'organizzazione e, se serve, l'account con cui le appone
const pacchetto = reactive({ valore: '', utente: '', password: '', url: '', policy: '', apriAccount: false, salvato: false, errore: '' });
async function salvaPacchetto() {
    pacchetto.errore = '';
    pacchetto.salvato = false;
    const corpo = { pacchetto: pacchetto.valore === '' ? null : Number(pacchetto.valore) };
    if (pacchetto.apriAccount && pacchetto.utente) {
        Object.assign(corpo, { utente: pacchetto.utente, password: pacchetto.password || undefined, url: pacchetto.url || 'https://servizi.arubapec.it/tsa/ngrequest.php', policy: pacchetto.policy || null });
    }
    await conAzione(async () => {
        await axios.put(`/api/v1/piattaforma/organizzazioni/${scelta.value.id}/marche`, corpo);
        pacchetto.password = '';
        pacchetto.salvato = true;
    }, 'Salvataggio del pacchetto non riuscito');
    if (azione.errore) {
        pacchetto.errore = azione.errore;
        azione.errore = '';
    }
}
async function conAzione(fn, predefinito) {
    azione.busy = true;
    azione.errore = '';
    try {
        await fn();
        await caricaElenco();
    } catch (err) {
        azione.errore = messaggioErrore(err, predefinito);
    } finally {
        azione.busy = false;
    }
}
const sospendi = () => conAzione(async () => {
    await axios.post(`/api/v1/piattaforma/organizzazioni/${scelta.value.id}/sospendi`, { motivo: azione.motivo });
    azione.chiediMotivo = false;
    azione.motivo = '';
}, 'Sospensione non riuscita');
const riattiva = () => conAzione(() => axios.post(`/api/v1/piattaforma/organizzazioni/${scelta.value.id}/riattiva`), 'Riattivazione non riuscita');
const salvaNote = () => conAzione(async () => {
    await axios.patch(`/api/v1/piattaforma/organizzazioni/${scelta.value.id}`, { note: azione.note });
    azione.noteSalvate = true;
}, 'Salvataggio non riuscito');
function entraInAssistenza() {
    if (! window.confirm(`Entrare in "${scelta.value.name}" come Assistenza piattaforma? L'accesso dura al massimo otto ore e resta annotato solo nel tuo registro.`)) return;
    router.post(`/piattaforma/assistenza/${scelta.value.id}`, {}, { onError: (e) => { azione.errore = Object.values(e)[0] ?? 'Accesso non riuscito'; } });
}

onMounted(() => { if (props.dueFattoriAttiva) carica(caricaElenco); });
</script>

<template>
    <Head title="Piattaforma" />

    <AppLayout>
        <div class="mx-auto flex max-w-[1640px] flex-col gap-4 p-4 md:p-6 lg:px-7">
            <TestataSezione v-if="nuova" titolo="Piattaforma" sottotitolo="Le organizzazioni ospitate: numeri, sospensione, assistenza" attiva="piattaforma" :schede="[]">
                <button v-if="props.dueFattoriAttiva" type="button" :class="BOTTONE" data-test="nuova-organizzazione" @click="creazione.aperta = ! creazione.aperta">Nuova organizzazione</button>
            </TestataSezione>
            <div v-else class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 class="text-lg font-semibold">Piattaforma</h1>
                    <p class="text-sm text-gray-500">Le organizzazioni ospitate: numeri, sospensione, assistenza</p>
                </div>
                <button v-if="props.dueFattoriAttiva" type="button" :class="BOTTONE" data-test="nuova-organizzazione" @click="creazione.aperta = ! creazione.aperta">Nuova organizzazione</button>
            </div>

            <section v-if="! props.dueFattoriAttiva" :class="CARTA" class="p-5" data-test="serve-due-fattori">
                <h2 class="text-base font-bold text-gray-900">Serve la verifica in due passaggi</h2>
                <p class="mt-2 text-sm text-gray-700">
                    La console vede tutte le organizzazioni: è l'accesso più delicato del programma e si apre solo a chi ha la
                    verifica in due passaggi attiva sul proprio utente.
                </p>
                <Link href="/sicurezza" :class="BOTTONE" class="mt-3">Attivala da "Il mio accesso"</Link>
            </section>

            <template v-else>
                <AvvisoErrore :messaggio="avviso" :in-corso="riprovaInCorso" @riprova="riprova" />

                <section v-if="credenziali" class="rounded-lg border border-green-300 bg-green-50 p-4 text-sm text-green-900" data-test="credenziali-nuova">
                    <p><strong>{{ credenziali.name }}</strong> è pronta (slug <span class="font-mono">{{ credenziali.slug }}</span>): ruoli, catalogo Modello Dati v2.1 e amministratore.</p>
                    <p class="mt-1">Credenziali dell'amministratore, da comunicare in modo sicuro. La password si vede una volta sola: al primo accesso la cambierà da "Il mio accesso".</p>
                    <dl class="mt-2 grid gap-x-6 gap-y-1 sm:grid-cols-2">
                        <div><dt :class="ETICHETTA">Email</dt><dd class="font-mono">{{ credenziali.admin_email }}</dd></div>
                        <div><dt :class="ETICHETTA">Password provvisoria</dt><dd class="font-mono" data-test="password-provvisoria">{{ credenziali.temporary_password }}</dd></div>
                    </dl>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button type="button" :class="BOTTONE_SECONDARIO" @click="copiaCredenziali">{{ copiato ? 'Copiate' : 'Copia le credenziali' }}</button>
                        <button type="button" :class="BOTTONE_SECONDARIO" @click="credenziali = null">Chiudi</button>
                    </div>
                </section>

                <section v-if="creazione.aperta" :class="CARTA" class="p-5" data-test="modulo-nuova-organizzazione">
                    <h2 class="text-base font-bold text-gray-900">Nuova organizzazione</h2>
                    <p class="mt-1 text-sm text-gray-700">Nasce pronta: ruoli di serie, catalogo Modello Dati v2.1 e un amministratore con password provvisoria. Lo slug è il nome breve che l'organizzazione usa all'accesso e nei portali.</p>
                    <form class="mt-4 grid gap-3 sm:grid-cols-2" @submit.prevent="crea">
                        <div class="sm:col-span-2">
                            <label class="mb-1 block text-sm font-medium" for="org-nome">Nome</label>
                            <input id="org-nome" v-model="creazione.form.name" type="text" required maxlength="150" :class="CAMPO" data-test="org-nome" @input="aggiornaSlug">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium" for="org-slug">Slug</label>
                            <input id="org-slug" v-model="creazione.form.slug" type="text" required maxlength="60" pattern="[a-z0-9]+(-[a-z0-9]+)*" :class="CAMPO" class="font-mono" data-test="org-slug" @input="creazione.slugManuale = true">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium" for="org-piva">Partita IVA o codice fiscale</label>
                            <input id="org-piva" v-model="creazione.form.vat_number" type="text" maxlength="20" :class="CAMPO" data-test="org-piva">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium" for="org-admin-nome">Nome dell'amministratore</label>
                            <input id="org-admin-nome" v-model="creazione.form.admin_name" type="text" maxlength="150" placeholder="Amministratore" :class="CAMPO" data-test="org-admin-nome">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium" for="org-admin-email">Email dell'amministratore</label>
                            <input id="org-admin-email" v-model="creazione.form.admin_email" type="email" required maxlength="190" :class="CAMPO" data-test="org-admin-email">
                        </div>
                        <p v-if="creazione.errore" class="text-sm text-red-600 sm:col-span-2" data-test="errore-creazione">{{ creazione.errore }}</p>
                        <div class="flex flex-wrap gap-2 sm:col-span-2">
                            <button type="submit" :class="BOTTONE" :disabled="creazione.busy" data-test="crea-organizzazione">{{ creazione.busy ? 'Creazione in corso…' : 'Crea l\'organizzazione' }}</button>
                            <button type="button" :class="BOTTONE_SECONDARIO" @click="creazione.aperta = false">Annulla</button>
                        </div>
                    </form>
                </section>

                <p v-if="organizzazioni" class="text-sm text-gray-700" data-test="frase-totali">
                    <template v-if="totali.organizzazioni === 0">Nessuna organizzazione.</template>
                    <template v-else>
                        {{ totali.organizzazioni === 1 ? '1 organizzazione' : `${n(totali.organizzazioni)} organizzazioni` }}<template v-if="totali.sospese">, di cui {{ totali.sospese === 1 ? '1 sospesa' : `${totali.sospese} sospese` }}</template>.
                        In tutto {{ n(totali.utenti) }} utenti attivi, {{ n(totali.elementi) }} elementi censiti e {{ n(totali.foto) }} fotografie ({{ spazio(totali.spazio) }}).
                    </template>
                </p>

                <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_360px] lg:items-start">
                    <div :class="CARTA" class="overflow-x-auto">
                        <table class="w-full text-sm tabular-nums" data-test="tabella-organizzazioni">
                            <thead>
                                <tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wide text-gray-500">
                                    <th class="px-3 py-2.5 font-medium">Organizzazione</th>
                                    <th class="px-3 py-2.5 font-medium">Stato</th>
                                    <th class="px-3 py-2.5 text-right font-medium">Utenti</th>
                                    <th class="px-3 py-2.5 text-right font-medium">Elementi</th>
                                    <th class="px-3 py-2.5 text-right font-medium">Alberi</th>
                                    <th class="px-3 py-2.5 text-right font-medium">Foto</th>
                                    <th class="px-3 py-2.5 text-right font-medium">Spazio</th>
                                    <th class="px-3 py-2.5 text-right font-medium">Portali</th>
                                    <th class="px-3 py-2.5 font-medium">Ultimo accesso</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                <tr
                                    v-for="o in organizzazioni ?? []"
                                    :key="o.id"
                                    class="cursor-pointer transition hover:bg-gray-50"
                                    :class="scelta?.id === o.id ? 'bg-green-50' : ''"
                                    data-test="riga-organizzazione"
                                    @click="scegli(o)"
                                >
                                    <td class="px-3 py-2">
                                        <div class="font-semibold text-gray-900">{{ o.name }}<span v-if="o.id === mioTenant" class="ml-1 rounded-sm bg-gray-100 px-2 py-0.5 text-[10px] font-medium text-gray-500">la tua</span></div>
                                        <div class="font-mono text-xs text-gray-500">{{ o.slug }}</div>
                                    </td>
                                    <td class="px-3 py-2">
                                        <span :class="o.is_active ? CHIP.ok : CHIP.attenzione">{{ o.is_active ? 'attiva' : 'sospesa' }}</span>
                                        <div v-if="o.assistenza" class="mt-1 text-[11px] text-amber-800">assistenza fino alle {{ ora(o.assistenza.scade) }}</div>
                                    </td>
                                    <td class="px-3 py-2 text-right">{{ n(o.numeri.utenti) }}</td>
                                    <td class="px-3 py-2 text-right">{{ n(o.numeri.elementi) }}</td>
                                    <td class="px-3 py-2 text-right">{{ n(o.numeri.alberi) }}</td>
                                    <td class="px-3 py-2 text-right">{{ n(o.numeri.foto) }}</td>
                                    <td class="px-3 py-2 text-right">{{ spazio(o.numeri.spazio_byte) }}</td>
                                    <td class="px-3 py-2 text-right">{{ n(o.numeri.portali) }} / {{ n(o.numeri.committenti) }}</td>
                                    <td class="px-3 py-2 text-gray-600">{{ data(o.ultimo_accesso) }}</td>
                                </tr>
                                <tr v-if="organizzazioni && ! organizzazioni.length">
                                    <td colspan="9" class="px-3 py-8 text-center text-gray-400">Nessuna organizzazione.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <aside :class="CARTA" class="p-4" data-test="dettaglio-organizzazione">
                        <template v-if="! scelta">
                            <p class="text-sm text-gray-500">Scegli un'organizzazione nella tabella per vedere il dettaglio, sospenderla o entrare in assistenza.</p>
                        </template>
                        <template v-else>
                            <h2 class="text-base font-bold text-gray-900">{{ scelta.name }}</h2>
                            <p class="font-mono text-xs text-gray-500">{{ scelta.slug }}<template v-if="scelta.vat_number"> · {{ scelta.vat_number }}</template></p>
                            <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                                <div><dt :class="ETICHETTA">Creata il</dt><dd class="font-semibold text-gray-900">{{ data(scelta.created_at) }}</dd></div>
                                <div><dt :class="ETICHETTA">Ultimo accesso</dt><dd class="font-semibold text-gray-900">{{ data(scelta.ultimo_accesso) }}</dd></div>
                                <div><dt :class="ETICHETTA">Aree</dt><dd class="font-semibold text-gray-900">{{ n(scelta.numeri.aree) }}</dd></div>
                                <div><dt :class="ETICHETTA">Lavori</dt><dd class="font-semibold text-gray-900">{{ n(scelta.numeri.lavori) }}</dd></div>
                                <div><dt :class="ETICHETTA">Committenti</dt><dd class="font-semibold text-gray-900">{{ n(scelta.numeri.committenti) }}</dd></div>
                                <div><dt :class="ETICHETTA">Portali pubblici accesi</dt><dd class="font-semibold text-gray-900">{{ n(scelta.numeri.portali) }}</dd></div>
                                <div class="col-span-2" data-test="dettaglio-marche"><dt :class="ETICHETTA">Marche temporali</dt><dd class="font-semibold text-gray-900">{{ n(scelta.numeri.marche) }} apposte<template v-if="scelta.marche_pacchetto !== null"> su un pacchetto di {{ n(scelta.marche_pacchetto) }}</template> <span class="font-normal text-gray-500">· {{ scelta.marche_configurate ? `account ${scelta.marche_utente}` : 'nessun account di marcatura' }}</span></dd></div>
                            </dl>

                            <form class="mt-4 space-y-2" data-test="modulo-pacchetto" @submit.prevent="salvaPacchetto">
                                <label class="block text-sm font-medium" for="pacchetto-marche">Pacchetto di marche temporali</label>
                                <div class="flex flex-wrap items-center gap-2">
                                    <input id="pacchetto-marche" v-model="pacchetto.valore" type="number" min="0" max="100000" :class="CAMPO" class="!w-32" placeholder="nessuno" data-test="pacchetto-marche" @input="pacchetto.salvato = false">
                                    <button type="button" :class="BOTTONE_PICCOLO" data-test="apri-account-marche" @click="pacchetto.apriAccount = ! pacchetto.apriAccount">{{ pacchetto.apriAccount ? 'Nascondi l\'account' : (scelta.marche_configurate ? 'Cambia l\'account' : 'Imposta l\'account') }}</button>
                                </div>
                                <p class="text-[13px] text-gray-600">Quante marche comprende il pacchetto venduto a questa organizzazione: le consuma solo lei, e a pacchetto finito la sua pagina Documenti rimanda all'assistenza. Vuoto: nessun tetto.</p>
                                <div v-if="pacchetto.apriAccount" class="grid gap-2 sm:grid-cols-2" data-test="account-marche">
                                    <input v-model="pacchetto.utente" type="text" :class="CAMPO" placeholder="Nome utente dell'account" autocomplete="off" data-test="account-marche-utente">
                                    <input v-model="pacchetto.password" type="password" :class="CAMPO" :placeholder="scelta.marche_configurate ? 'Password (vuota: resta quella salvata)' : 'Password'" autocomplete="new-password" data-test="account-marche-password">
                                    <input v-model="pacchetto.url" type="url" :class="CAMPO" placeholder="Indirizzo del servizio (vuoto: Aruba)">
                                    <input v-model="pacchetto.policy" type="text" :class="CAMPO" placeholder="Politica OID (facoltativa)">
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <button type="submit" :class="BOTTONE_PICCOLO" :disabled="azione.busy" data-test="salva-pacchetto">Salva il pacchetto</button>
                                    <span v-if="pacchetto.salvato" class="text-[13px] text-green-800" data-test="pacchetto-salvato">Salvato.</span>
                                    <span v-if="pacchetto.errore" class="text-[13px] text-red-700" data-test="pacchetto-errore">{{ pacchetto.errore }}</span>
                                </div>
                            </form>

                            <form class="mt-4 space-y-2" data-test="modulo-funzioni" @submit.prevent="salvaFunzioni">
                                <p class="text-sm font-medium">Funzioni accese per questa organizzazione</p>
                                <label class="flex min-h-11 items-start gap-2 text-sm md:min-h-9">
                                    <input v-model="funzioni.gestionale" type="checkbox" class="mt-1 rounded border-gray-300" data-test="funzione-gestionale">
                                    <span>
                                        Collegamento al gestionale giardini (WordPress)
                                        <span class="block text-[13px] text-gray-600">Spento di serie per chi affitta il programma: e' un collegamento nostro, non del prodotto. Acceso, l'organizzazione vede la sezione in Utenti e "Invia al gestionale" nelle schede.</span>
                                    </span>
                                </label>
                                <div class="flex flex-wrap items-center gap-2">
                                    <button type="submit" :class="BOTTONE_PICCOLO" :disabled="azione.busy" data-test="salva-funzioni">Salva le funzioni</button>
                                    <span v-if="funzioni.salvato" class="text-[13px] text-green-800" data-test="funzioni-salvate">Salvato.</span>
                                    <span v-if="funzioni.errore" class="text-[13px] text-red-700" data-test="funzioni-errore">{{ funzioni.errore }}</span>
                                </div>
                            </form>

                            <div v-if="! scelta.is_active && scelta.sospensione" class="mt-3 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900" data-test="dettaglio-sospensione">
                                Sospesa dal {{ ora(scelta.sospensione.dal) }}<template v-if="scelta.sospensione.da"> da {{ scelta.sospensione.da }}</template><template v-if="scelta.sospensione.motivo">: {{ scelta.sospensione.motivo }}</template>.
                                Nessuno entra e i suoi portali pubblici sono spenti.
                            </div>

                            <div class="mt-4 flex flex-wrap gap-2">
                                <button v-if="scelta.is_active && scelta.id !== mioTenant" type="button" :class="BOTTONE" data-test="entra-assistenza" :disabled="azione.busy" @click="entraInAssistenza">Entra in assistenza</button>
                                <button v-if="scelta.is_active && scelta.id !== mioTenant && ! azione.chiediMotivo" type="button" :class="BOTTONE_SECONDARIO" class="!text-red-700" data-test="sospendi" :disabled="azione.busy" @click="azione.chiediMotivo = true">Sospendi</button>
                                <button v-if="! scelta.is_active" type="button" :class="BOTTONE" data-test="riattiva" :disabled="azione.busy" @click="riattiva">Riattiva</button>
                            </div>
                            <form v-if="azione.chiediMotivo" class="mt-3 space-y-2" data-test="modulo-sospensione" @submit.prevent="sospendi">
                                <label class="block text-sm font-medium" for="motivo-sospensione">Motivo della sospensione (facoltativo, resta nel registro)</label>
                                <input id="motivo-sospensione" v-model="azione.motivo" type="text" maxlength="300" :class="CAMPO" placeholder="es. canone non rinnovato" data-test="motivo-sospensione">
                                <p class="text-[13px] text-gray-600">Da subito nessuno dell'organizzazione entra più, le sessioni aperte si chiudono e i portali pubblici si spengono. Si può riattivare in qualsiasi momento.</p>
                                <div class="flex flex-wrap gap-2">
                                    <button type="submit" :class="BOTTONE_SECONDARIO" class="!border-red-300 !text-red-700" :disabled="azione.busy" data-test="conferma-sospensione">Sospendi l'organizzazione</button>
                                    <button type="button" :class="BOTTONE_SECONDARIO" @click="azione.chiediMotivo = false">Annulla</button>
                                </div>
                            </form>
                            <p v-if="scelta.id === mioTenant" class="mt-2 text-[13px] text-gray-500">È la tua organizzazione: non si sospende da qui e non serve l'assistenza.</p>
                            <p v-if="azione.errore" class="mt-2 text-sm text-red-600" data-test="errore-azione">{{ azione.errore }}</p>

                            <form class="mt-4 space-y-2" @submit.prevent="salvaNote">
                                <label class="block text-sm font-medium" for="note-organizzazione">Note della piattaforma</label>
                                <textarea id="note-organizzazione" v-model="azione.note" rows="3" maxlength="1000" :class="CAMPO" placeholder="Contratto, referente, canone, scadenze: quello che serve a chi gestisce, non lo vede l'organizzazione." data-test="note-organizzazione" @input="azione.noteSalvate = false" />
                                <div class="flex flex-wrap items-center gap-2">
                                    <button type="submit" :class="BOTTONE_PICCOLO" :disabled="azione.busy" data-test="salva-note">Salva le note</button>
                                    <span v-if="azione.noteSalvate" class="text-[13px] text-green-800" data-test="note-salvate">Salvate.</span>
                                </div>
                            </form>
                        </template>
                    </aside>
                </div>
            </template>
        </div>
    </AppLayout>
</template>
