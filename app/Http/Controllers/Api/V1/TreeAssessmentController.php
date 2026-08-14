<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\TreeAssessment;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TreeAssessmentController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:assets.view', only: ['index']),
            new Middleware('can:assets.update', only: ['store']),
            new Middleware('can:assets.delete', only: ['destroy']),
        ];
    }

    public function index(string $assetId): JsonResponse
    {
        $asset = Asset::with('tree')->findOrFail($assetId);
        abort_if($asset->tree === null, 404, 'Questo elemento non è un albero.');

        return response()->json([
            'data' => $asset->tree->assessments()
                ->with('assessor:id,name')
                ->withCount('instrumentalAnalyses')
                ->orderByDesc('assessed_on')
                ->get(),
        ]);
    }

    public function store(Request $request, string $assetId): JsonResponse
    {
        $asset = Asset::with('tree')->findOrFail($assetId);
        if ($asset->tree === null) {
            throw ValidationException::withMessages([
                'asset' => 'Le valutazioni di stabilità si registrano solo sugli alberi.',
            ]);
        }

        $data = $request->validate([
            'assessment_type' => ['required', 'in:vta_visual,vta_instrumental,vsa,pull_test,aerial_inspection,other'],
            'assessed_on' => ['required', 'date', 'before_or_equal:today'],
            'assessor_external' => ['nullable', 'string', 'max:254'],
            'defects' => ['sometimes', 'array'],
            'targets' => ['sometimes', 'array'],
            'failure_class' => ['nullable', Rule::in(TreeAssessment::FAILURE_CLASSES)],
            'outcome' => ['nullable', 'in:ok,monitor,prescriptions,fell'],
            'prescriptions' => ['nullable', 'string'],
            'next_check_due' => ['nullable', 'date', 'after:assessed_on'],
            // Scheda estesa della perizia: tutte voci descrittive facoltative
            'survey' => ['sometimes', 'array'],
            'survey.contesto' => ['nullable', 'array'],
            'survey.contesto.ambito' => ['nullable', 'string', 'max:150'],
            'survey.contesto.sito_radicazione' => ['nullable', 'string', 'max:150'],
            'survey.contesto.disposizione' => ['nullable', 'string', 'max:150'],
            'survey.contesto.accessibilita' => ['nullable', 'string', 'max:150'],
            'survey.interferenze' => ['nullable', 'string', 'max:1000'],
            'survey.giudizio' => ['nullable', 'array'],
            'survey.giudizio.fase_fisiologica' => ['nullable', 'string', 'max:150'],
            'survey.giudizio.stato_vegetativo' => ['nullable', 'string', 'max:150'],
            'survey.giudizio.sintetico' => ['nullable', 'string', 'max:150'],
            'survey.giudizio.patologie_quarantena' => ['nullable', 'string', 'max:500'],
            'survey.difetti' => ['nullable', 'array'],
            'survey.difetti.*' => ['nullable', 'string', 'max:2000'],
            'survey.integrazione_vta' => ['nullable', 'string', 'max:1000'],
            'survey.priorita_intervento' => ['nullable', 'string', 'max:100'],
            'survey.conclusioni' => ['nullable', 'string', 'max:4000'],
        ]);

        if (isset($data['survey']['difetti'])) {
            // Solo le parti previste dalla scheda: niente chiavi arbitrarie
            $data['survey']['difetti'] = array_intersect_key(
                $data['survey']['difetti'],
                array_flip(TreeAssessment::BODY_PARTS),
            );
        }

        // Scadenzario automatico: se il tecnico non indica il ricontrollo,
        // lo deriva dalla classe di propensione al cedimento (configurabile per tenant)
        if (empty($data['next_check_due']) && ! empty($data['failure_class'])) {
            $months = $this->recheckMonths($request)[$data['failure_class']] ?? null;
            if ($months !== null) {
                $data['next_check_due'] = \Illuminate\Support\Carbon::parse($data['assessed_on'])
                    ->addMonths($months)->toDateString();
            }
        }

        $assessment = TreeAssessment::create([
            ...$data,
            'tree_id' => $asset->tree->asset_id,
            'assessor_id' => $request->user()->id,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        Audit::log('vta.created', $assessment, [
            'asset_id' => $asset->id,
            'failure_class' => $assessment->failure_class,
        ]);

        return response()->json(['data' => $assessment->load('assessor:id,name')], 201);
    }

    public function destroy(string $id): Response
    {
        $assessment = TreeAssessment::findOrFail($id);
        $assessment->delete();

        Audit::log('vta.deleted', $assessment);

        return response()->noContent();
    }

    /** @return array<string, int|null> */
    private function recheckMonths(Request $request): array
    {
        $settings = \App\Models\Organization::find($request->user()->tenant_id)?->settings ?? [];

        return array_replace(
            TreeAssessment::DEFAULT_RECHECK_MONTHS,
            $settings['vta_recheck_months'] ?? [],
        );
    }
}
