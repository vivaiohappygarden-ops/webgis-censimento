<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Inspection;
use App\Models\InspectionTemplate;
use App\Models\NonConformity;
use App\Support\Audit;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Esecuzione delle ispezioni su checklist: l'esito complessivo è calcolato
 * dalle risposte e le voci negative aprono non conformità (origin: inspection).
 */
class InspectionController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:works.view', only: ['index', 'show', 'deadlines']),
            new Middleware('can:works.manage', only: ['store']),
        ];
    }

    /**
     * Scadenzario dei controlli ricorrenti (calcolo condiviso col cruscotto
     * Oggi in InspectionDeadlines). La ricorrenza parte dalla prima
     * ispezione registrata.
     */
    public function deadlines(\App\Services\Inspections\InspectionDeadlines $deadlines): JsonResponse
    {
        return response()->json(['data' => $deadlines->compute()]);
    }

    public function index(Request $request): JsonResponse
    {
        ListQuery::validateUuidFilters($request, ['template_id', 'asset_id', 'area_id']);
        $request->validate(['outcome' => ['nullable', Rule::in(Inspection::OUTCOMES)]]);

        $query = Inspection::query()
            ->with([
                'template:id,code,name,target,standard_ref',
                'asset:id,census_code', 'area:id,name', 'inspector:id,name',
            ]);

        foreach (['template_id', 'asset_id', 'area_id'] as $key) {
            if ($request->filled($key)) {
                $query->where($key, $request->string($key));
            }
        }
        if ($request->filled('outcome')) {
            $query->where('outcome', $request->string('outcome'));
        }

        return response()->json(
            $query->orderByDesc('completed_at')
                ->paginate(ListQuery::perPage($request, 25, 100))
        );
    }

    public function show(string $id): JsonResponse
    {
        return response()->json([
            'data' => Inspection::query()
                ->with([
                    'template:id,code,name,target,standard_ref',
                    'asset:id,census_code', 'area:id,name', 'inspector:id,name',
                ])
                ->findOrFail($id),
        ]);
    }

    /**
     * Registra un'ispezione completa: risposte per voce, esito calcolato,
     * versione del modello congelata, non conformità dalle voci KO.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'template_id' => ['required', 'uuid'],
            'asset_id' => ['nullable', 'uuid'],
            'area_id' => ['nullable', 'uuid'],
            'started_at' => ['nullable', 'date'],
            'answers' => ['required', 'array'],
            // Niente regola 'string': una risposta numerica arriva come numero
            // JSON e va accettata (il controllo fine è nel ciclo per tipo)
            'answers.*.value' => ['required'],
            'answers.*.note' => ['nullable', 'string', 'max:2000'],
        ]);

        // Un solo motore per backoffice e campo: validazioni fini, esito,
        // fotografia delle domande e non conformità stanno nel servizio
        $inspection = app(\App\Services\Inspections\InspectionRunner::class)
            ->run($request->user(), $data);

        return $this->show($inspection->id);
    }
}
