<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\WorkOrder;
use App\Services\Works\AzioniMultiple;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Azioni su piu' elementi selezionati in un elenco.
 *
 * Ogni risposta dice cosa e' stato fatto e cosa e' stato saltato con il
 * motivo: chi usa un'azione di gruppo deve poter controllare l'esito, non
 * fidarsi.
 */
class AzioniMultipleController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:works.manage', only: ['chiudiLavori', 'collegaElementi']),
            new Middleware('can:assets.update', only: ['modificaElementi']),
        ];
    }

    public function chiudiLavori(Request $request): JsonResponse
    {
        $data = $request->validate($this->regoleIds());

        // prova=1: si conta cosa succederebbe senza eseguire, per mostrare
        // "N verranno chiusi, M esclusi" prima della conferma
        return response()->json([
            'data' => AzioniMultiple::chiudiLavori($data['ids'], $request->user(), $request->boolean('prova')),
        ]);
    }

    public function modificaElementi(Request $request): JsonResponse
    {
        $data = $request->validate([
            ...$this->regoleIds(),
            // Solo campi che hanno senso applicati in blocco.
            //
            // Fuori restano: specie, misure e geometria, che sono dati del
            // singolo albero e applicarli a tutti sarebbe un modo comodo per
            // rovinare un censimento; e lo STATO, perche' l'abbattimento ha un
            // suo flusso che scrive anche data di rimozione, fine validita' e
            // scheda albero. Cambiarlo da qui sarebbe una porta di servizio
            // per scavalcarlo e lasciare i dati disallineati.
            'public_hidden' => ['sometimes', 'boolean'],
            'surveyed_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $modifiche = array_intersect_key($data, array_flip(['public_hidden', 'surveyed_at']));

        if ($modifiche === []) {
            return response()->json(['message' => 'Nessuna modifica indicata.'], 422);
        }

        return response()->json([
            'data' => AzioniMultiple::modificaElementi(
                $data['ids'], $modifiche, $request->user(), $request->boolean('prova'),
            ),
        ]);
    }

    public function collegaElementi(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            ...$this->regoleIds(),
            'work_type_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        $ordine = WorkOrder::query()->findOrFail($id);

        abort_if(in_array($ordine->status, ['completed', 'cancelled'], true), 409,
            'L\'ordine di lavoro e\' chiuso: non si possono piu\' aggiungere elementi.');

        if (! empty($data['work_type_id'])) {
            \App\Models\WorkType::query()->findOrFail($data['work_type_id']);
        }

        return response()->json([
            'data' => AzioniMultiple::collegaElementi(
                $ordine, $data['ids'], $data['work_type_id'] ?? null, $request->boolean('prova'),
            ),
        ]);
    }

    private function regoleIds(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:'.AzioniMultiple::MASSIMO],
            'ids.*' => ['uuid'],
        ];
    }
}
