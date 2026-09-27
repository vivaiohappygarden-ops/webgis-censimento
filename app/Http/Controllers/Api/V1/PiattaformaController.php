<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Piattaforma\ConsolePiattaforma;
use App\Services\Sicurezza\DueFattori;
use App\Services\Tenancy\CreatoreOrganizzazione;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * La console della piattaforma: solo per chi ha la qualifica di gestore
 * (data dal terminale) e con la verifica in due passaggi attiva, perche' e'
 * l'accesso piu' potente del programma.
 */
class PiattaformaController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:piattaforma'),
            new Middleware(function (Request $request, \Closure $next) {
                abort_unless(DueFattori::attiva($request->user()), 403,
                    'Per la console della piattaforma serve la verifica in due passaggi attiva: accendila da "Il mio accesso".');

                return $next($request);
            }),
        ];
    }

    public function index(ConsolePiattaforma $console): JsonResponse
    {
        return response()->json(['data' => $console->organizzazioni()]);
    }

    public function store(Request $request, CreatoreOrganizzazione $creatore): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/'],
            'vat_number' => ['nullable', 'string', 'max:20'],
            'admin_name' => ['nullable', 'string', 'max:150'],
            'admin_email' => ['required', 'email', 'max:190'],
        ], [
            'slug.regex' => 'Lo slug ammette solo minuscole, cifre e trattini (es. comune-di-parma).',
        ]);

        try {
            $esito = $creatore->crea($data['name'], $data['slug'], $data['admin_email'], $data['admin_name'] ?? null, $data['vat_number'] ?? null);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['slug' => $e->getMessage()]);
        }

        Audit::log('piattaforma.organizzazione_creata', $esito['organizzazione'], [
            'slug' => $esito['organizzazione']->slug, 'amministratore' => $esito['amministratore']->email,
        ]);

        return response()->json(['data' => [
            'id' => $esito['organizzazione']->id,
            'name' => $esito['organizzazione']->name,
            'slug' => $esito['organizzazione']->slug,
            'admin_email' => $esito['amministratore']->email,
            // Si vede una volta sola: va comunicata subito
            'temporary_password' => $esito['password'],
            'catalogo' => $esito['catalogo'],
        ]], 201);
    }

    public function update(Request $request, string $id, ConsolePiattaforma $console): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        $console->aggiornaNote(Organization::query()->findOrFail($id), $data['note'] ?? null);

        return response()->json(['data' => ['ok' => true]]);
    }

    public function sospendi(Request $request, string $id, ConsolePiattaforma $console): JsonResponse
    {
        $data = $request->validate(['motivo' => ['nullable', 'string', 'max:300']]);
        $organizzazione = Organization::query()->findOrFail($id);
        if ($organizzazione->id === $request->user()->tenant_id) {
            throw ValidationException::withMessages(['motivo' => 'Non puoi sospendere la tua stessa organizzazione.']);
        }
        $console->sospendi($organizzazione, $request->user(), $data['motivo'] ?? null);

        return response()->json(['data' => ['is_active' => false]]);
    }

    public function riattiva(Request $request, string $id, ConsolePiattaforma $console): JsonResponse
    {
        $console->riattiva(Organization::query()->findOrFail($id), $request->user());

        return response()->json(['data' => ['is_active' => true]]);
    }
}
