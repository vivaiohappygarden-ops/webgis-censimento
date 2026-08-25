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
    /**
     * Le pagine che hanno un elenco filtrabile, con il permesso che serve per
     * vederle: chi non può aprire l'elenco (il ruolo cliente, per esempio)
     * non deve nemmeno leggere o creare le viste, che raccontano come lo
     * staff lavora e comparirebbero nelle tendine dei colleghi.
     */
    private const PAGINE = [
        'censimento' => 'assets.view',
        'lavori' => 'works.view',
        'segnalazioni' => 'works.view',
    ];

    /** Oltre questo numero di viste per pagina si chiede di fare pulizia. */
    private const MASSIMO_VISTE = 30;

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['pagina' => ['required', Rule::in(array_keys(self::PAGINE))]]);
        abort_unless($request->user()->can(self::PAGINE[$data['pagina']]), 403);

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
            'pagina' => ['required', Rule::in(array_keys(self::PAGINE))],
            'nome' => ['required', 'string', 'max:80'],
            // Filtri: solo una manciata di valori semplici. Senza il tetto,
            // un body costruito a mano potrebbe gonfiare la tabella e la
            // risposta che ogni collega scarica a ogni apertura dell'elenco
            'filtri' => ['required', 'array', 'max:20'],
            'filtri.*' => [function (string $attribute, mixed $value, \Closure $fail) {
                if (is_array($value) || is_object($value)) {
                    $fail('I filtri devono essere valori semplici.');
                } elseif (is_string($value) && mb_strlen($value) > 200) {
                    $fail('Un filtro non può superare 200 caratteri.');
                }
            }],
            'condivisa' => ['sometimes', 'boolean'],
        ]);
        abort_unless($request->user()->can(self::PAGINE[$data['pagina']]), 403);

        $esiste = SavedFilter::query()
            ->where('user_id', $request->user()->id)
            ->where('pagina', $data['pagina']);

        if (! (clone $esiste)->where('nome', trim($data['nome']))->exists()
            && (clone $esiste)->count() >= self::MASSIMO_VISTE) {
            return response()->json([
                'message' => 'Hai già '.self::MASSIMO_VISTE.' viste per questa pagina: eliminane una prima di salvarne altre.',
            ], 422);
        }

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
        abort_unless($request->user()->can(self::PAGINE[$vista->pagina] ?? 'assets.view'), 403);

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
        $vista = SavedFilter::query()
            ->where('user_id', $request->user()->id)
            ->findOrFail($id);
        abort_unless($request->user()->can(self::PAGINE[$vista->pagina] ?? 'assets.view'), 403);
        $vista->delete();

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
