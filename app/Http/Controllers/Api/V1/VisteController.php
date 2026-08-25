<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SavedFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Viste salvate: i filtri di un elenco memorizzati con un nome.
 *
 * Ogni utente vede le proprie viste piu' quelle che i colleghi hanno
 * condiviso. Si modifica ed elimina solo cio' che e' proprio; la
 * predefinita e' una scelta personale e non tocca gli altri.
 */
class VisteController extends Controller
{
    /** Le pagine che hanno un elenco filtrabile: altrove una vista non ha senso. */
    private const PAGINE = ['censimento', 'lavori', 'segnalazioni'];

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['pagina' => ['required', Rule::in(self::PAGINE)]]);

        $viste = SavedFilter::query()
            ->with('user:id,name')
            ->where('pagina', $data['pagina'])
            ->where(fn ($w) => $w->where('user_id', $request->user()->id)->orWhere('condivisa', true))
            ->orderByDesc('predefinita')
            ->orderBy('nome')
            ->get();

        return response()->json(['data' => $viste->map(fn (SavedFilter $v) => $this->presenta($v, $request))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pagina' => ['required', Rule::in(self::PAGINE)],
            'nome' => ['required', 'string', 'max:80'],
            'filtri' => ['required', 'array'],
            'condivisa' => ['sometimes', 'boolean'],
        ]);

        // Stesso nome = si aggiorna la vista: "salva di nuovo" deve
        // sovrascrivere, non creare un doppione con lo stesso nome
        $vista = SavedFilter::query()->updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'pagina' => $data['pagina'],
                'nome' => trim($data['nome']),
            ],
            [
                'filtri' => $data['filtri'],
                'condivisa' => $data['condivisa'] ?? false,
            ],
        );

        return response()->json(['data' => $this->presenta($vista, $request)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['predefinita' => ['required', 'boolean']]);

        // La predefinita si sceglie solo fra le proprie viste: una vista di
        // un collega si puo' usare, non adottare come apertura automatica
        $vista = SavedFilter::query()
            ->where('user_id', $request->user()->id)
            ->findOrFail($id);

        DB::transaction(function () use ($vista, $data, $request) {
            if ($data['predefinita']) {
                SavedFilter::query()
                    ->where('user_id', $request->user()->id)
                    ->where('pagina', $vista->pagina)
                    ->update(['predefinita' => false]);
            }
            $vista->update(['predefinita' => $data['predefinita']]);
        });

        return response()->json(['data' => $this->presenta($vista->refresh(), $request)]);
    }

    public function destroy(Request $request, string $id)
    {
        SavedFilter::query()
            ->where('user_id', $request->user()->id)
            ->findOrFail($id)
            ->delete();

        return response()->noContent();
    }

    private function presenta(SavedFilter $vista, Request $request): array
    {
        $mia = $vista->user_id === $request->user()->id;

        return [
            'id' => $vista->id,
            'nome' => $vista->nome,
            'filtri' => $vista->filtri,
            // La predefinita e' personale: quella di un collega, sulla sua
            // vista condivisa, non deve aprirsi da sola anche per me
            'predefinita' => $mia && $vista->predefinita,
            'condivisa' => $vista->condivisa,
            'mia' => $mia,
            'di' => $vista->user?->name,
        ];
    }
}
