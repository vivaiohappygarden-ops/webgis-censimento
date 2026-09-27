<?php

namespace App\Services\Sicurezza;

use App\Models\Organization;
use App\Models\User;
use App\Support\Totp;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\DB;

/**
 * La verifica in due passaggi dell'accesso: oltre alla password, un codice a
 * tempo generato da un'app sul telefono. Qui stanno, una volta sola,
 * l'attivazione (segreto, conferma, codici di recupero), la verifica al
 * momento dell'accesso (web e API) e la regola dell'organizzazione (nessuno,
 * amministratori, tutti). Il segreto vive cifrato in users.mfa_secret; i
 * codici di recupero solo come impronta, e ognuno vale una volta.
 */
class DueFattori
{
    public const REGOLE = ['nessuno', 'amministratori', 'tutti'];

    public const CODICI_RECUPERO = 8;

    /** Senza 0/O e 1/I: si dettano al telefono e si ricopiano a mano. */
    private const ALFABETO_RECUPERO = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function regola(Organization|string|null $organizzazione): string
    {
        if (is_string($organizzazione)) {
            $organizzazione = Organization::query()->find($organizzazione);
        }
        $regola = $organizzazione?->settings['sicurezza']['due_fattori'] ?? 'nessuno';

        return in_array($regola, self::REGOLE, true) ? $regola : 'nessuno';
    }

    /** Se la regola della sua organizzazione obbliga questo utente ad avere la verifica accesa. */
    public static function obbligatoriaPer(User $user, ?string $regola = null): bool
    {
        return match ($regola ?? self::regola($user->tenant_id)) {
            'tutti' => true,
            'amministratori' => self::eAmministratore($user),
            default => false,
        };
    }

    /**
     * Il ruolo letto dalle tabelle, senza passare dal pacchetto dei permessi:
     * al momento dell'accesso il suo contesto (il tenant) non e' ancora
     * impostato e la risposta sarebbe sbagliata.
     */
    public static function eAmministratore(User $user): bool
    {
        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', $user->id)
            ->where('model_has_roles.model_type', $user->getMorphClass())
            ->where('roles.tenant_id', $user->tenant_id)
            ->where('roles.name', 'amministratore')
            ->exists();
    }

    /** Quanti utenti attivi la regola obbligherebbe e non hanno ancora la verifica accesa. */
    public static function utentiScoperti(string $tenantId, string $regola): int
    {
        if ($regola === 'nessuno') {
            return 0;
        }

        $utenti = User::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->whereNull('deleted_at')
            ->where('is_active', true)->where('mfa_enabled', false);

        if ($regola === 'amministratori') {
            $utenti->whereExists(fn ($q) => $q->selectRaw('1')->from('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->whereColumn('model_has_roles.model_id', 'users.id')
                ->where('model_has_roles.model_type', (new User)->getMorphClass())
                ->where('roles.tenant_id', $tenantId)
                ->where('roles.name', 'amministratore'));
        }

        return $utenti->count();
    }

    public static function attiva(User $user): bool
    {
        return (bool) $user->mfa_enabled && (string) $user->mfa_secret !== '';
    }

    /** Un'attivazione avviata (segreto pronto) e non ancora confermata con il primo codice. */
    public static function inAttesa(User $user): bool
    {
        return ! $user->mfa_enabled && (string) $user->mfa_secret !== '';
    }

    /**
     * Prepara un segreto nuovo, non ancora attivo: il codice QR da inquadrare
     * e la chiave da scrivere a mano. Finche' non arriva la conferma, la
     * verifica resta spenta e l'accesso non cambia.
     *
     * @return array{segreto: string, segreto_leggibile: string, uri: string, qr: string}
     */
    public static function avvia(User $user): array
    {
        $segreto = Totp::segreto();
        $user->forceFill([
            'mfa_secret' => $segreto, 'mfa_enabled' => false, 'mfa_confirmed_at' => null,
            'mfa_recovery_codes' => null, 'mfa_last_step' => null,
        ])->save();

        $uri = self::uri($user, $segreto);

        return [
            'segreto' => $segreto,
            'segreto_leggibile' => trim(chunk_split($segreto, 4, ' ')),
            'uri' => $uri,
            'qr' => self::qr($uri),
        ];
    }

    /**
     * La conferma con il primo codice dell'app: da qui la verifica e' accesa.
     * Restituisce i codici di recupero in chiaro (si vedono una volta sola) o
     * null se il codice e' sbagliato.
     *
     * @return list<string>|null
     */
    public static function conferma(User $user, string $codice): ?array
    {
        if (! self::inAttesa($user)) {
            return null;
        }
        $passo = Totp::verifica($user->mfa_secret, $codice);
        if ($passo === null) {
            return null;
        }

        $codici = self::generaCodici();
        $user->forceFill([
            'mfa_enabled' => true,
            'mfa_confirmed_at' => now(),
            'mfa_last_step' => $passo,
            'mfa_recovery_codes' => array_map(self::impronta(...), $codici),
        ])->save();

        // I gettoni API rilasciati prima non sono passati dal secondo passaggio
        $user->tokens()->delete();

        return $codici;
    }

    /**
     * La verifica al momento dell'accesso: 'codice' (dall'app), 'recupero'
     * (un codice di recupero, che si consuma) o null se non vale. Un codice
     * dell'app gia' speso non vale una seconda volta.
     */
    public static function verifica(User $user, string $inserito): ?string
    {
        if (! self::attiva($user)) {
            return null;
        }

        $pulito = preg_replace('/\s+/', '', $inserito);
        if (preg_match('/^\d{'.Totp::CIFRE.'}$/', $pulito)) {
            $passo = Totp::verifica($user->mfa_secret, $pulito, 1, $user->mfa_last_step);
            if ($passo === null) {
                return null;
            }
            $user->forceFill(['mfa_last_step' => $passo])->save();

            return 'codice';
        }

        $impronta = self::impronta($pulito);
        $codici = $user->mfa_recovery_codes ?? [];
        foreach ($codici as $indice => $salvata) {
            if (hash_equals((string) $salvata, $impronta)) {
                unset($codici[$indice]);
                $user->forceFill(['mfa_recovery_codes' => array_values($codici)])->save();

                return 'recupero';
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function nuoviCodici(User $user): array
    {
        $codici = self::generaCodici();
        $user->forceFill(['mfa_recovery_codes' => array_map(self::impronta(...), $codici)])->save();

        return $codici;
    }

    public static function codiciRimasti(User $user): int
    {
        return count($user->mfa_recovery_codes ?? []);
    }

    /** Spegne tutto: segreto, conferma, codici. Serve a chi disattiva e all'amministratore che azzera. */
    public static function spegni(User $user): void
    {
        $user->forceFill([
            'mfa_secret' => null, 'mfa_enabled' => false, 'mfa_confirmed_at' => null,
            'mfa_recovery_codes' => null, 'mfa_last_step' => null,
        ])->save();
    }

    /** L'impronta con cui si conservano i codici di recupero: legata alla chiave dell'applicazione, come il segreto cifrato. */
    public static function impronta(string $codice): string
    {
        $normale = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $codice));

        return hash_hmac('sha256', $normale, (string) config('app.key'));
    }

    /** @return list<string> */
    private static function generaCodici(): array
    {
        $codici = [];
        $massimo = strlen(self::ALFABETO_RECUPERO) - 1;
        for ($i = 0; $i < self::CODICI_RECUPERO; $i++) {
            $codice = '';
            for ($j = 0; $j < 10; $j++) {
                $codice .= self::ALFABETO_RECUPERO[random_int(0, $massimo)];
            }
            $codici[] = substr($codice, 0, 5).'-'.substr($codice, 5);
        }

        return $codici;
    }

    private static function uri(User $user, string $segreto): string
    {
        $organizzazione = Organization::query()->find($user->tenant_id);
        $emittente = (string) config('app.name');
        if ($emittente === '' || $emittente === 'Laravel') {
            $emittente = 'WebGIS Censimento';
        }

        // Nell'app il conto si chiama con l'email e lo slug dell'organizzazione:
        // chi ha lo stesso indirizzo in due organizzazioni li distingue
        return Totp::uri($segreto, $emittente, $user->email.($organizzazione ? ' ('.$organizzazione->slug.')' : ''));
    }

    public static function qr(string $uri): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(240), new SvgImageBackEnd)))->writeString($uri);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
