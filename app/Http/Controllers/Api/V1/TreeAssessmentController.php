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
            new Middleware('can:assets.update', only: ['store', 'update']),
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

        $data = $request->validate($this->rules(), $this->messages());

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

        // refresh: version e i valori con default lato database servono
        // subito a chi correggerà la valutazione (blocco ottimistico)
        return response()->json(['data' => $assessment->refresh()->load('assessor:id,name')], 201);
    }

    /**
     * Correzione di una valutazione già registrata: un refuso nella perizia
     * non deve costringere a cancellare tutto (le analisi strumentali sono
     * agganciate alla valutazione e sparirebbero con lei).
     *
     * Se la perizia era già stata emessa, il protocollo viene azzerato: il
     * documento corretto esce con un numero e una data nuovi, così il numero
     * già consegnato non finisce su un contenuto diverso.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            ...$this->rules(perTutti: false),
            'version' => ['sometimes', 'integer'],
        ], $this->messages());

        $assessment = \Illuminate\Support\Facades\DB::transaction(function () use ($request, $id, $data) {
            $assessment = TreeAssessment::query()->lockForUpdate()->findOrFail($id);

            if (isset($data['version']) && (int) $data['version'] !== $assessment->version) {
                abort(409, "Conflitto di versione: la valutazione è stata modificata da altri (versione attuale {$assessment->version}).");
            }
            unset($data['version']);

            if (isset($data['survey']['difetti'])) {
                $data['survey']['difetti'] = array_intersect_key(
                    $data['survey']['difetti'],
                    array_flip(TreeAssessment::BODY_PARTS),
                );
            }

            $eraEmessa = $assessment->report_number !== null;
            $assessment->fill($data);

            // Ricontrollo automatico dalla classe, come alla registrazione:
            // l'etichetta del campo lo promette anche in correzione, e una
            // classe peggiorata deve accorciare la scadenza
            // Solo se il campo è stato inviato vuoto: una correzione che non
            // lo nomina affatto non deve toccare una data messa a mano
            if (array_key_exists('next_check_due', $data) && empty($data['next_check_due'])) {
                $mesi = $assessment->failure_class !== null
                    ? ($this->recheckMonths($request)[$assessment->failure_class] ?? null)
                    : null;
                $assessment->next_check_due = $mesi !== null && $assessment->assessed_on
                    ? $assessment->assessed_on->copy()->addMonths($mesi)
                    : null;
            }

            // Il contenuto è cambiato? Va deciso PRIMA di toccare i campi di
            // servizio: se li scrivessimo ora, un salvataggio senza modifiche
            // fatto da un altro utente risulterebbe comunque una modifica e
            // butterebbe via il numero di perizia già consegnato
            $contenutoCambiato = $assessment->isDirty();
            $assessment->updated_by = $request->user()->id;

            if ($assessment->next_check_due && $assessment->assessed_on
                && $assessment->next_check_due->lte($assessment->assessed_on)) {
                throw ValidationException::withMessages([
                    'next_check_due' => 'Il prossimo controllo deve essere successivo alla data del sopralluogo ('
                        .$assessment->assessed_on->format('d/m/Y').').',
                ]);
            }

            if ($eraEmessa && $contenutoCambiato) {
                $assessment->report_number = null;
                $assessment->report_issued_at = null;
            }
            if ($contenutoCambiato) {
                $assessment->version = $assessment->version + 1;
            }
            $assessment->save();

            Audit::log('vta.updated', $assessment, [
                'failure_class' => $assessment->failure_class,
                'protocollo_azzerato' => $eraEmessa && $assessment->report_number === null,
            ]);

            return $assessment;
        });

        return response()->json(['data' => $assessment->load('assessor:id,name')]);
    }

    public function destroy(string $id): Response
    {
        $assessment = TreeAssessment::findOrFail($id);
        $assessment->delete();

        Audit::log('vta.deleted', $assessment);

        return response()->noContent();
    }

    /**
     * Regole della scheda VTA. In creazione tipo e data sono obbligatori;
     * in correzione si tocca solo quello che si invia.
     *
     * @return array<string, array<int, mixed>>
     */
    private function rules(bool $perTutti = true): array
    {
        $obbligatorio = $perTutti ? 'required' : 'sometimes';

        return [
            'assessment_type' => [$obbligatorio, 'in:vta_visual,vta_instrumental,vsa,pull_test,aerial_inspection,other'],
            // Giornata italiana, non quella del server (UTC): dopo mezzanotte
            // la data proposta a video sarebbe "nel futuro" per il server
            'assessed_on' => [$obbligatorio, 'date', 'before_or_equal:'.now('Europe/Rome')->toDateString()],
            'assessor_external' => ['nullable', 'string', 'max:254'],
            'defects' => ['sometimes', 'array'],
            'targets' => ['sometimes', 'array'],
            'targets.*' => ['nullable', 'string', 'max:254'],
            'failure_class' => ['nullable', Rule::in(TreeAssessment::FAILURE_CLASSES)],
            'outcome' => ['nullable', 'in:ok,monitor,prescriptions,fell'],
            'prescriptions' => ['nullable', 'string'],
            'next_check_due' => array_values(array_filter(
                ['nullable', 'date', $perTutti ? 'after:assessed_on' : null],
            )),
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
        ];
    }

    /** @return array<string, string> messaggi in italiano per chi compila la scheda */
    private function messages(): array
    {
        return [
            'assessed_on.before_or_equal' => 'La data del sopralluogo non può essere nel futuro.',
            'assessed_on.required' => 'Indica la data del sopralluogo.',
            'assessment_type.required' => 'Indica il tipo di valutazione.',
        ];
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
