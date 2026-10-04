<?php

namespace App\Services\Piattaforma;

use App\Models\Organization;
use App\Models\User;
use App\Support\Audit;
use App\Support\Funzioni;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * La console di chi gestisce la piattaforma: tutte le organizzazioni con i
 * numeri che servono a fatturare (utenti, elementi, foto e spazio, ultimo
 * accesso), la sospensione (una sospesa non fa entrare nessuno e spegne i
 * suoi portali) e l'accesso di assistenza, che entra nell'organizzazione con
 * un utente proprio, "Assistenza piattaforma", acceso solo per il tempo
 * dell'intervento. L'accesso di assistenza resta scritto solo nel registro
 * di chi gestisce la piattaforma: nell'organizzazione assistita non lascia
 * traccia, ne' nel registro ne' nella pagina Utenti (decisione committente
 * 27/09/2026); le modifiche fatte durante l'assistenza restano, come ogni
 * modifica, a nome di chi le ha fatte.
 */
class ConsolePiattaforma
{
    public const NOME_ASSISTENZA = 'Assistenza piattaforma';

    public const ORE_ASSISTENZA = 8;

    /** Dominio riservato (RFC 2606): l'indirizzo non e' recapitabile e non puo' coincidere con uno vero. */
    public const DOMINIO_ASSISTENZA = 'piattaforma.invalid';

    /** @return list<array<string, mixed>> */
    public function organizzazioni(): array
    {
        $perTenant = fn ($query, string $colonna = 'tenant_id') => $query->groupBy($colonna)->pluck('n', $colonna);

        $utenti = $perTenant(DB::table('users')->selectRaw('tenant_id, count(*) AS n')->whereNull('deleted_at')->where('is_active', true)
            ->where('email', 'not like', '%@'.self::DOMINIO_ASSISTENZA));
        $ultimoAccesso = $perTenant(DB::table('users')->selectRaw('tenant_id, max(last_login_at) AS n')->whereNull('deleted_at')
            ->where('email', 'not like', '%@'.self::DOMINIO_ASSISTENZA));
        $elementi = $perTenant(DB::table('assets')->selectRaw('tenant_id, count(*) AS n')->whereNull('deleted_at'));
        $alberi = $perTenant(DB::table('trees')->join('assets', 'assets.id', '=', 'trees.asset_id')
            ->selectRaw('trees.tenant_id, count(*) AS n')->whereNull('assets.deleted_at'), 'trees.tenant_id');
        $foto = DB::table('photos')->selectRaw('tenant_id, count(*) AS n, coalesce(sum(size_bytes), 0) AS byte')
            ->whereNull('deleted_at')->groupBy('tenant_id')->get()->keyBy('tenant_id');
        $aree = $perTenant(DB::table('areas')->selectRaw('tenant_id, count(*) AS n')->whereNull('deleted_at'));
        $committenti = $perTenant(DB::table('clients')->selectRaw('tenant_id, count(*) AS n')->whereNull('deleted_at'));
        $portali = $perTenant(DB::table('clients')->selectRaw('tenant_id, count(*) AS n')->whereNull('deleted_at')->where('public_enabled', true));
        $lavori = $perTenant(DB::table('work_orders')->selectRaw('tenant_id, count(*) AS n')->whereNull('deleted_at'));
        // Le marche temporali sono per organizzazione, con il suo account: qui
        // si vede chi ne ha uno e quante ne ha apposte (per fatturare o per accorgersi che manca)
        $marche = $perTenant(DB::table('marche_temporali')->selectRaw('tenant_id, count(*) AS n'));
        $assistenza = User::query()->withoutGlobalScopes()->whereNull('deleted_at')
            ->where('email', 'like', 'assistenza+%@'.self::DOMINIO_ASSISTENZA)->get()->keyBy('tenant_id');

        return Organization::query()->orderBy('name')->get()->map(function (Organization $o) use (
            $utenti, $ultimoAccesso, $elementi, $alberi, $foto, $aree, $committenti, $portali, $lavori, $marche, $assistenza
        ) {
            $piattaforma = $o->settings['piattaforma'] ?? [];
            $utenteAssistenza = $assistenza[$o->id] ?? null;

            return [
                'id' => $o->id,
                'name' => $o->name,
                'slug' => $o->slug,
                'vat_number' => $o->vat_number,
                'is_active' => (bool) $o->is_active,
                'created_at' => $o->created_at?->toIso8601String(),
                'sospensione' => $piattaforma['sospensione'] ?? null,
                'note' => $piattaforma['note'] ?? null,
                'ultimo_accesso' => $ultimoAccesso[$o->id] ?? null,
                'numeri' => [
                    'utenti' => (int) ($utenti[$o->id] ?? 0),
                    'elementi' => (int) ($elementi[$o->id] ?? 0),
                    'alberi' => (int) ($alberi[$o->id] ?? 0),
                    'foto' => (int) ($foto[$o->id]->n ?? 0),
                    'spazio_byte' => (int) ($foto[$o->id]->byte ?? 0),
                    'aree' => (int) ($aree[$o->id] ?? 0),
                    'committenti' => (int) ($committenti[$o->id] ?? 0),
                    'portali' => (int) ($portali[$o->id] ?? 0),
                    'lavori' => (int) ($lavori[$o->id] ?? 0),
                    'marche' => (int) ($marche[$o->id] ?? 0),
                ],
                // Le funzioni regolabili dalla console (spente di serie per chi affitta)
                'gestionale_giardini' => Funzioni::attiva($o, Funzioni::GESTIONALE_GIARDINI),
                'marche_configurate' => ! empty($o->settings['marche']['utente']) && ! empty($o->settings['marche']['password_cifrata']),
                'marche_pacchetto' => isset($o->settings['marche']['pacchetto']) && $o->settings['marche']['pacchetto'] !== '' ? (int) $o->settings['marche']['pacchetto'] : null,
                'marche_utente' => isset($o->settings['marche']['utente']) ? \App\Services\Marche\MarcheTemporali::mascherato($o->settings['marche']['utente']) : null,
                'assistenza' => $utenteAssistenza && $utenteAssistenza->is_active && ! self::assistenzaScaduta($utenteAssistenza) ? [
                    'scade' => $utenteAssistenza->settings['assistenza']['scade'] ?? null,
                    'gestore' => $utenteAssistenza->settings['assistenza']['gestore'] ?? null,
                ] : null,
            ];
        })->values()->all();
    }

    /** Sospende: nessuno entra piu' (login, sessioni, gettoni) e i portali pubblici si spengono. */
    public function sospendi(Organization $organizzazione, User $gestore, ?string $motivo): void
    {
        DB::transaction(function () use ($organizzazione, $gestore, $motivo) {
            $o = Organization::query()->lockForUpdate()->findOrFail($organizzazione->id);
            $settings = $o->settings ?? [];
            $settings['piattaforma'] = array_replace($settings['piattaforma'] ?? [], [
                'sospensione' => ['dal' => now()->toIso8601String(), 'motivo' => $motivo ?: null, 'da' => $gestore->email],
            ]);
            $o->forceFill(['is_active' => false, 'settings' => $settings])->save();

            // I gettoni API decadono subito; le sessioni web le chiude il
            // middleware alla richiesta successiva
            DB::table('personal_access_tokens')
                ->where('tokenable_type', (new User)->getMorphClass())
                ->whereIn('tokenable_id', User::query()->withoutGlobalScopes()->where('tenant_id', $o->id)->select('id'))
                ->delete();

            Audit::log('piattaforma.sospesa', $o, ['slug' => $o->slug, 'motivo' => $motivo]);
        });
    }

    public function riattiva(Organization $organizzazione, User $gestore): void
    {
        DB::transaction(function () use ($organizzazione) {
            $o = Organization::query()->lockForUpdate()->findOrFail($organizzazione->id);
            $settings = $o->settings ?? [];
            $sospensione = $settings['piattaforma']['sospensione'] ?? null;
            unset($settings['piattaforma']['sospensione']);
            $o->forceFill(['is_active' => true, 'settings' => $settings])->save();

            Audit::log('piattaforma.riattivata', $o, ['slug' => $o->slug, 'sospensione' => $sospensione]);
        });
    }

    /**
     * Accende o spegne le funzioni regolabili di un'organizzazione (App\Support\Funzioni):
     * scrittura sotto lock come le altre impostazioni, registro nel tenant del gestore.
     *
     * @param  array<string, mixed>  $funzioni  nome => acceso
     */
    public function impostaFunzioni(Organization $organizzazione, array $funzioni): Organization
    {
        return DB::transaction(function () use ($organizzazione, $funzioni) {
            $o = Organization::query()->lockForUpdate()->findOrFail($organizzazione->id);
            $prima = Funzioni::per($o);
            $settings = $o->settings ?? [];
            $settings['funzioni'] = array_replace($settings['funzioni'] ?? [],
                array_map(fn ($acceso) => (bool) $acceso, array_intersect_key($funzioni, Funzioni::DI_SERIE)));
            $o->forceFill(['settings' => $settings])->save();
            Audit::log('piattaforma.funzioni', $o, ['slug' => $o->slug, 'prima' => $prima, 'dopo' => Funzioni::per($o)]);

            return $o;
        });
    }

    public function aggiornaNote(Organization $organizzazione, ?string $note): void
    {
        DB::transaction(function () use ($organizzazione, $note) {
            $o = Organization::query()->lockForUpdate()->findOrFail($organizzazione->id);
            $settings = $o->settings ?? [];
            $settings['piattaforma'] = array_replace($settings['piattaforma'] ?? [], ['note' => trim((string) $note) !== '' ? trim($note) : null]);
            $o->forceFill(['settings' => $settings])->save();
        });
    }

    /**
     * L'utente di assistenza dell'organizzazione: nasce alla prima richiesta,
     * e' amministratore, resta acceso per ORE_ASSISTENZA e poi decade da solo.
     * Compare nella pagina Utenti dell'organizzazione con il suo nome: chi
     * ci lavora vede quando l'assistenza e' entrata.
     */
    public function iniziaAssistenza(Organization $organizzazione, User $gestore): User
    {
        $utente = User::query()->withoutGlobalScopes()->whereNull('deleted_at')
            ->where('tenant_id', $organizzazione->id)->where('email', self::emailAssistenza($organizzazione))->first();

        if (! $utente) {
            // Password casuale mai comunicata: si entra solo dalla console
            $utente = User::query()->newModelInstance([
                'name' => self::NOME_ASSISTENZA,
                'email' => self::emailAssistenza($organizzazione),
                'password' => Str::password(40),
                'user_type' => 'internal',
            ]);
            $utente->tenant_id = $organizzazione->id;
            $utente->save();
        }

        $scade = now()->addHours(self::ORE_ASSISTENZA);
        $utente->forceFill([
            'is_active' => true,
            'settings' => array_replace($utente->settings ?? [], [
                'assistenza' => ['gestore' => $gestore->email, 'scade' => $scade->toIso8601String()],
            ]),
        ])->save();

        $registrar = app(PermissionRegistrar::class);
        $contestoPrima = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($organizzazione->id);
        if (! $utente->hasRole('amministratore')) {
            $utente->assignRole('amministratore');
        }
        $registrar->setPermissionsTeamId($contestoPrima);

        // Solo nel registro di chi gestisce la piattaforma: nel registro
        // dell'organizzazione assistita l'accesso non lascia traccia
        // (decisione committente 27/09/2026)
        Audit::logPer($gestore, 'piattaforma.assistenza_inizio', $organizzazione, ['slug' => $organizzazione->slug, 'scade' => $scade->toIso8601String()]);

        return $utente;
    }

    public function terminaAssistenza(User $utenteAssistenza, ?User $gestore): void
    {
        $utenteAssistenza->forceFill([
            'is_active' => false,
            'settings' => array_replace($utenteAssistenza->settings ?? [], [
                'assistenza' => array_replace($utenteAssistenza->settings['assistenza'] ?? [], ['scade' => now()->toIso8601String()]),
            ]),
        ])->save();

        if ($gestore) {
            Audit::logPer($gestore, 'piattaforma.assistenza_fine', Organization::query()->find($utenteAssistenza->tenant_id), ['slug' => Organization::query()->find($utenteAssistenza->tenant_id)?->slug]);
        }
    }

    public static function emailAssistenza(Organization $organizzazione): string
    {
        return 'assistenza+'.$organizzazione->slug.'@'.self::DOMINIO_ASSISTENZA;
    }

    public static function eUtenteAssistenza(User $user): bool
    {
        return str_starts_with((string) $user->email, 'assistenza+') && str_ends_with((string) $user->email, '@'.self::DOMINIO_ASSISTENZA);
    }

    /** L'utente di assistenza vale solo entro la sua ora di scadenza. */
    public static function assistenzaScaduta(User $user): bool
    {
        if (! self::eUtenteAssistenza($user)) {
            return false;
        }
        $scade = $user->settings['assistenza']['scade'] ?? null;

        return ! $scade || Carbon::parse($scade)->isPast();
    }

    /**
     * All'accesso: email e password giuste ma organizzazione sospesa. Si dice
     * solo a chi ha la password: non rivela niente a chi tira a indovinare.
     */
    public static function organizzazioneSospesaPer(string $email, string $password): bool
    {
        return User::query()->withoutGlobalScopes()->whereNull('deleted_at')
            ->where('email', $email)->where('is_active', true)
            ->whereIn('tenant_id', Organization::query()->where('is_active', false)->select('id'))
            ->get()
            ->contains(fn (User $u) => $u->password && Hash::check($password, $u->password));
    }

    public const MESSAGGIO_SOSPESA = 'L\'organizzazione è sospesa: contattare il gestore della piattaforma.';
}
