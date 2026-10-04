<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TreeSpecies;
use App\Services\Botanica\DizionarioSpecie;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Il dizionario delle specie visto da un'organizzazione: ricerca a parole per
 * la scheda dell'albero, elenco intero per l'app di campo, voci proprie da
 * aggiungere e togliere. Le voci di serie non si toccano.
 */
class SpecieController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:assets.view', only: ['index']),
            new Middleware('can:assets.update', only: ['store', 'destroy']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'tutte' => ['sometimes', 'boolean'],
        ]);
        $tenantId = $request->user()->tenant_id;
        if ($request->boolean('tutte')) {
            return response()->json(['data' => DizionarioSpecie::tutte($tenantId)]);
        }

        return response()->json(['data' => DizionarioSpecie::cerca($tenantId, $data['q'] ?? null)->map(fn (TreeSpecies $v) => $v->voce())->values()]);
    }

    /** Una voce dell'organizzazione; se la specie c'e' gia' (di serie o propria) torna quella. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'species' => ['required', 'string', 'max:150'],
            'genus' => ['nullable', 'string', 'max:100'],
            'cultivar' => ['nullable', 'string', 'max:100'],
            'family' => ['nullable', 'string', 'max:100'],
            'common_name' => ['nullable', 'string', 'max:150'],
            'synonyms' => ['sometimes', 'array', 'max:20'],
            'synonyms.*' => ['string', 'max:100'],
        ]);
        $tenantId = $request->user()->tenant_id;
        $species = trim($data['species']);
        $cultivar = trim((string) ($data['cultivar'] ?? '')) ?: null;
        $esistente = DizionarioSpecie::stessaVoce($tenantId, $species, $cultivar, anchePerSerie: true)->first();
        if ($esistente) {
            return response()->json(['data' => $esistente->voce(), 'esistente' => true]);
        }
        $voce = TreeSpecies::create([
            'tenant_id' => $tenantId,
            'genus' => trim((string) ($data['genus'] ?? '')) ?: explode(' ', $species)[0],
            'species' => $species,
            'cultivar' => $cultivar,
            'family' => trim((string) ($data['family'] ?? '')) ?: null,
            'common_name' => trim((string) ($data['common_name'] ?? '')) ?: null,
            'synonyms' => array_values(array_filter(array_map('trim', $data['synonyms'] ?? []))),
            'source' => 'organizzazione',
            'created_by' => $request->user()->id,
        ]);
        Audit::log('specie.aggiunta', null, ['species' => $voce->species, 'cultivar' => $voce->cultivar]);

        return response()->json(['data' => $voce->voce(), 'esistente' => false], 201);
    }

    /** Si tolgono solo le voci proprie: quelle di serie restano per tutti. */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $voce = TreeSpecies::query()->where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
        $voce->delete();
        Audit::log('specie.tolta', null, ['species' => $voce->species, 'cultivar' => $voce->cultivar]);

        return response()->json(null, 204);
    }
}
