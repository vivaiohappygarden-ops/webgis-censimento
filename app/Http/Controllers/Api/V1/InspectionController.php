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

    /** Le scadenze si contano in date locali, non in ore UTC. */
    private const TIMEZONE = 'Europe/Rome';

    /**
     * Scadenzario dei controlli ricorrenti: per ogni modello attivo con
     * periodicità, l'ultima ispezione di ciascun bersaglio e la data entro
     * cui ripeterla. La ricorrenza parte dalla prima ispezione registrata.
     */
    public function deadlines(): JsonResponse
    {
        $rows = Inspection::query()
            ->join('inspection_templates as t', function ($join) {
                $join->on('t.id', '=', 'inspections.template_id')
                    ->on('t.tenant_id', '=', 'inspections.tenant_id');
            })
            ->whereNull('t.deleted_at')
            ->where('t.is_active', true)
            ->whereNotNull('t.frequency_days')
            ->whereNotNull('inspections.completed_at')
            ->groupBy('inspections.template_id', 't.code', 't.name', 't.frequency_days', 't.target',
                'inspections.asset_id', 'inspections.area_id')
            ->selectRaw('inspections.template_id, t.code AS template_code, t.name AS template_name,
                t.frequency_days, t.target, inspections.asset_id, inspections.area_id,
                MAX(inspections.completed_at) AS last_completed_at')
            ->get();

        // L'esistenza si verifica sull'id, non sull'etichetta: un elemento
        // senza codice censimento è legittimo e non deve sparire in silenzio
        // proprio dalla vista nata per far emergere i ritardi
        $assets = \App\Models\Asset::query()
            ->whereIn('id', $rows->pluck('asset_id')->filter())
            ->get(['id', 'census_code'])->keyBy('id');
        $areaLabels = \App\Models\Area::query()
            ->whereIn('id', $rows->pluck('area_id')->filter())->pluck('name', 'id');

        $today = \Illuminate\Support\Carbon::now(self::TIMEZONE)->startOfDay();
        $data = $rows->map(function ($row) use ($assets, $areaLabels, $today) {
            $isAsset = $row->asset_id !== null;
            $label = $isAsset
                ? (isset($assets[$row->asset_id])
                    ? ($assets[$row->asset_id]->census_code ?? 'Elemento '.substr($row->asset_id, 0, 8))
                    : null)
                : ($areaLabels[$row->area_id] ?? null);
            // Bersaglio eliminato dal censimento: nessun controllo da pianificare
            if ($label === null) {
                return null;
            }

            $last = \Illuminate\Support\Carbon::parse($row->last_completed_at)->timezone(self::TIMEZONE);
            $due = $last->copy()->startOfDay()->addDays($row->frequency_days);
            // round(): a cavallo dell'ora legale il divario tra mezzanotti
            // non è un numero intero di giorni
            $daysLeft = (int) round($today->diffInDays($due, false));

            return [
                'template_id' => $row->template_id,
                'template_code' => $row->template_code,
                'template_name' => $row->template_name,
                'frequency_days' => (int) $row->frequency_days,
                'target' => $isAsset ? 'asset' : 'area',
                'target_id' => $isAsset ? $row->asset_id : $row->area_id,
                'target_label' => $label,
                'last_completed_at' => \Illuminate\Support\Carbon::parse($row->last_completed_at)->toIso8601String(),
                'due_date' => $due->toDateString(),
                'days_left' => $daysLeft,
                'state' => $daysLeft < 0 ? 'overdue' : ($daysLeft <= 30 ? 'due_soon' : 'ok'),
            ];
        })->filter()->sortBy('due_date')->values();

        return response()->json(['data' => $data]);
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
