<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Services\Piattaforma\ConsolePiattaforma;
use App\Services\Sicurezza\DueFattori;
use App\Support\Audit;
use App\Support\HomeRoute;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\PermissionRegistrar;

class WebAuthController extends Controller
{
    private const DUMMY_HASH = '$2y$12$TRcWRkv0zs6.HxSrsZI1W.DoFuuZFjvkR1FYNTOEWd3yzTyKLOpSG';

    /** Codici sbagliati ammessi al secondo passaggio prima di ricominciare dalla password. */
    public const TENTATIVI = 5;

    /** Minuti entro cui va inserito il codice dopo la password. */
    public const MINUTI_ATTESA = 10;

    public function show(Request $request): Response
    {
        // Chi torna alla pagina dell'accesso ricomincia da capo
        $request->session()->forget('due_fattori');

        return Inertia::render('Auth/Login');
    }

    /** La pagina d'accesso dedicata alle imprese appaltatrici. */
    public function showImpresa(Request $request): Response
    {
        $request->session()->forget('due_fattori');

        return Inertia::render('Auth/LoginImpresa');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'organization' => ['nullable', 'string'],
            'remember' => ['nullable', 'boolean'],
        ]);

        $query = $this->utentiAmmessi()->where('email', $data['email']);

        if (! empty($data['organization'])) {
            $query->whereIn('tenant_id', Organization::where('slug', $data['organization'])->pluck('id'));
        }

        $candidates = $query->get()->filter(
            fn (User $u) => $u->password && Hash::check($data['password'], $u->password)
        );

        if ($candidates->isEmpty()) {
            // Password giusta ma organizzazione sospesa dalla piattaforma:
            // si dice, altrimenti sembrerebbe una password sbagliata
            if (ConsolePiattaforma::organizzazioneSospesaPer($data['email'], $data['password'])) {
                throw ValidationException::withMessages(['email' => ConsolePiattaforma::MESSAGGIO_SOSPESA]);
            }
            Hash::check($data['password'], self::DUMMY_HASH);

            throw ValidationException::withMessages(['email' => 'Credenziali non valide.']);
        }

        if ($candidates->count() > 1) {
            throw ValidationException::withMessages([
                'organization' => 'Questa email è presente in più organizzazioni: indica lo slug della tua.',
            ]);
        }

        /** @var User $user */
        $user = $candidates->first();
        $ricordami = (bool) ($data['remember'] ?? false);

        // Con la verifica in due passaggi accesa la password non basta: si
        // ricorda in sessione chi ha superato il primo passaggio, senza
        // autenticarlo, e si chiede il codice dell'app
        if (DueFattori::attiva($user)) {
            $request->session()->regenerate();
            $request->session()->put('due_fattori', [
                'utente' => $user->id,
                'ricordami' => $ricordami,
                'scade' => now()->addMinutes(self::MINUTI_ATTESA)->getTimestamp(),
                'tentativi' => 0,
                'ritorno' => str_contains((string) $request->headers->get('referer'), '/impresa/login')
                    ? route('impresa.login') : route('login'),
            ]);

            return redirect()->route('login.codice');
        }

        return $this->completaAccesso($request, $user, $ricordami);
    }

    /** Il secondo passaggio: la pagina del codice. */
    public function showCodice(Request $request): Response|RedirectResponse
    {
        [$attesa, $user] = $this->attesa($request);
        if (! $user) {
            return redirect()->route('login');
        }

        return Inertia::render('Auth/Codice', [
            'email' => $user->email,
            'ritorno' => $attesa['ritorno'],
        ]);
    }

    public function codice(Request $request): RedirectResponse
    {
        $data = $request->validate(['codice' => ['required', 'string', 'max:40']]);

        [$attesa, $user] = $this->attesa($request);
        if (! $user) {
            return redirect()->route('login')->withErrors(['email' => 'Tempo scaduto: ripeti l\'accesso.']);
        }

        $esito = DueFattori::verifica($user, $data['codice']);
        if ($esito === null) {
            $attesa['tentativi']++;
            Audit::logPer($user, 'auth.mfa_failed');

            if ($attesa['tentativi'] >= self::TENTATIVI) {
                $request->session()->forget('due_fattori');
                Audit::logPer($user, 'auth.mfa_locked');

                return redirect()->to($attesa['ritorno'])
                    ->withErrors(['email' => 'Troppi codici sbagliati: ripeti l\'accesso con la password.']);
            }
            $request->session()->put('due_fattori', $attesa);

            throw ValidationException::withMessages([
                'codice' => 'Codice non valido. Controlla che l\'ora del telefono sia giusta e riprova con il codice nuovo.',
            ]);
        }

        $request->session()->forget('due_fattori');
        $risposta = $this->completaAccesso($request, $user, (bool) $attesa['ricordami'], $esito);

        // Chi entra con un codice di recupero deve saperlo e rimediare:
        // la pagina del proprio accesso glielo dice
        if ($esito === 'recupero') {
            Audit::log('mfa.recovery_used', $user, ['rimasti' => DueFattori::codiciRimasti($user)]);
            $request->session()->forget('url.intended');

            return redirect()->to(route('sicurezza', ['recupero' => 1]));
        }

        return $risposta;
    }

    public function logout(Request $request): RedirectResponse
    {
        Audit::log('auth.logout', $request->user());

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /** Gli utenti che possono accedere: attivi, non eliminati, di un'organizzazione attiva. */
    private function utentiAmmessi()
    {
        return User::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->whereIn('tenant_id', Organization::where('is_active', true)->pluck('id'));
    }

    /** @return array{0: array<string, mixed>, 1: ?User} */
    private function attesa(Request $request): array
    {
        $attesa = $request->session()->get('due_fattori');
        if (! is_array($attesa) || ($attesa['scade'] ?? 0) < now()->getTimestamp()) {
            $request->session()->forget('due_fattori');

            return [[], null];
        }

        $user = $this->utentiAmmessi()->find($attesa['utente'] ?? '');
        if (! $user || ! DueFattori::attiva($user)) {
            $request->session()->forget('due_fattori');

            return [[], null];
        }

        return [$attesa, $user];
    }

    private function completaAccesso(Request $request, User $user, bool $ricordami, ?string $secondoPassaggio = null): RedirectResponse
    {
        Auth::login($user, $ricordami);
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();
        Audit::log('auth.login', $user, $secondoPassaggio ? ['due_fattori' => $secondoPassaggio] : []);

        // Il middleware imposta il contesto dei permessi solo dalle richieste
        // successive: qui serve subito per decidere dove atterrare
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);

        $home = route(HomeRoute::for($user));

        // intended() ricorda l'ultima pagina chiesta da ospite: per chi non
        // ha la lettura del censimento sarebbe quasi sempre una pagina
        // vietata (es. /mappa) e produrrebbe un 403 subito dopo il login
        if (! $user->can('assets.view')) {
            $request->session()->forget('url.intended');

            return redirect()->to($home);
        }

        return redirect()->intended($home);
    }
}
