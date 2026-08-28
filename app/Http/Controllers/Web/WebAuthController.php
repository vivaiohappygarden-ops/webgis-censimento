<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WebAuthController extends Controller
{
    private const DUMMY_HASH = '$2y$12$TRcWRkv0zs6.HxSrsZI1W.DoFuuZFjvkR1FYNTOEWd3yzTyKLOpSG';

    public function show(): Response
    {
        return Inertia::render('Auth/Login');
    }

    /** La pagina d'accesso dedicata alle imprese appaltatrici. */
    public function showImpresa(): Response
    {
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

        $query = User::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('email', $data['email'])
            ->where('is_active', true)
            ->whereIn('tenant_id', Organization::where('is_active', true)->pluck('id'));

        if (! empty($data['organization'])) {
            $query->whereIn('tenant_id', Organization::where('slug', $data['organization'])->pluck('id'));
        }

        $candidates = $query->get()->filter(
            fn (User $u) => $u->password && Hash::check($data['password'], $u->password)
        );

        if ($candidates->isEmpty()) {
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
        Auth::login($user, (bool) ($data['remember'] ?? false));
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();
        Audit::log('auth.login', $user);

        // Il middleware imposta il contesto dei permessi solo dalle richieste
        // successive: qui serve subito per decidere dove atterrare
        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);

        $home = route(\App\Support\HomeRoute::for($user));

        // intended() ricorda l'ultima pagina chiesta da ospite: per chi non
        // ha la lettura del censimento sarebbe quasi sempre una pagina
        // vietata (es. /mappa) e produrrebbe un 403 subito dopo il login
        if (! $user->can('assets.view')) {
            $request->session()->forget('url.intended');

            return redirect()->to($home);
        }

        return redirect()->intended($home);
    }

    public function logout(Request $request): RedirectResponse
    {
        Audit::log('auth.logout', $request->user());

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
