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
            new Middleware('can:works.view', only: ['index', 'show']),
            new Middleware('can:works.manage', only: ['store']),
        ];
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

        $user = $request->user();
        $template = InspectionTemplate::query()->with('items')->findOrFail($data['template_id']);

        if (! $template->is_active) {
            throw ValidationException::withMessages(['template_id' => 'Modello disattivato: non utilizzabile.']);
        }
        if ($template->items->isEmpty()) {
            throw ValidationException::withMessages(['template_id' => 'Il modello non ha domande: completalo prima di usarlo.']);
        }

        // Il bersaglio deve combaciare col modello: elemento O area, mai entrambi
        if ($template->target === 'asset') {
            if (empty($data['asset_id']) || ! empty($data['area_id'])) {
                throw ValidationException::withMessages(['asset_id' => 'Questo modello si applica a un elemento censito.']);
            }
            \App\Models\Asset::query()->findOrFail($data['asset_id']);
        } else {
            if (empty($data['area_id']) || ! empty($data['asset_id'])) {
                throw ValidationException::withMessages(['area_id' => 'Questo modello si applica a un\'area.']);
            }
            \App\Models\Area::query()->findOrFail($data['area_id']);
        }

        // Risposte a domande estranee al modello: errore esplicito, mai scarto silenzioso
        $knownIds = $template->items->pluck('id')->all();
        $unknown = array_diff(array_keys($data['answers']), $knownIds);
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'answers' => 'Il modulo contiene risposte a domande non presenti nel modello: ricaricalo e riprova.',
            ]);
        }

        // Ogni domanda vuole la sua risposta, con valori ammessi per tipo
        $answers = [];
        $koItems = [];
        $remarks = false;
        foreach ($template->items as $item) {
            $answer = $data['answers'][$item->id] ?? null;
            if ($answer === null) {
                throw ValidationException::withMessages([
                    'answers' => "Manca la risposta alla domanda: \"{$item->question}\".",
                ]);
            }
            if (! is_scalar($answer['value'])) {
                throw ValidationException::withMessages([
                    'answers' => "Risposta non valida per \"{$item->question}\".",
                ]);
            }
            $value = trim((string) $answer['value']);
            if (mb_strlen($value) > 2000) {
                throw ValidationException::withMessages([
                    'answers' => "Risposta troppo lunga per \"{$item->question}\" (massimo 2000 caratteri).",
                ]);
            }
            if (in_array($item->answer_type, ['ok_ko', 'ok_ko_na'], true)) {
                $allowed = $item->answer_type === 'ok_ko' ? ['ok', 'ko'] : ['ok', 'ko', 'na'];
                if (! in_array($value, $allowed, true)) {
                    throw ValidationException::withMessages([
                        'answers' => "Risposta non valida per \"{$item->question}\": ammessi ".implode('/', $allowed).'.',
                    ]);
                }
                if ($value === 'ko') {
                    $koItems[] = ['item' => $item, 'note' => $answer['note'] ?? null];
                }
                if ($value === 'na') {
                    $remarks = true;
                }
            } elseif ($item->answer_type === 'number' && ! is_numeric($value)) {
                throw ValidationException::withMessages([
                    'answers' => "Risposta non numerica per \"{$item->question}\".",
                ]);
            }
            // Una nota su QUALSIASI tipo di risposta è una riserva da segnalare
            if (! empty($answer['note'])) {
                $remarks = true;
            }

            // Fotografia completa: anche il testo della domanda resta
            // nell'ispezione, leggibile pure se il modello cambierà
            $answers[$item->id] = [
                'question' => $item->question,
                'answer_type' => $item->answer_type,
                'value' => $value,
                'note' => isset($answer['note']) && trim($answer['note']) !== '' ? trim($answer['note']) : null,
            ];
        }

        $outcome = match (true) {
            $koItems !== [] => 'failed',
            $remarks => 'passed_with_remarks',
            default => 'passed',
        };

        $inspection = DB::transaction(function () use ($data, $user, $template, $answers, $outcome, $koItems) {
            $inspection = Inspection::create([
                'tenant_id' => $user->tenant_id,
                'template_id' => $template->id,
                'template_version' => $template->version,
                'asset_id' => $data['asset_id'] ?? null,
                'area_id' => $data['area_id'] ?? null,
                'inspector_id' => $user->id,
                'started_at' => $data['started_at'] ?? now(),
                'completed_at' => now(),
                'answers' => $answers,
                'outcome' => $outcome,
            ]);

            // Ogni voce negativa che lo prevede apre la sua non conformità
            foreach ($koItems as $ko) {
                if (! $ko['item']->ko_creates_nc) {
                    continue;
                }
                $nonConformity = NonConformity::create([
                    'tenant_id' => $user->tenant_id,
                    'code' => NonConformity::nextCode($user->tenant_id),
                    'origin' => 'inspection',
                    'origin_id' => $inspection->id,
                    'severity' => 'minor',
                    'status' => 'open',
                    'asset_id' => $data['asset_id'] ?? null,
                    'description' => 'Ispezione '.($template->code ?? $template->name).': '.$ko['item']->question
                        .($ko['note'] ? ' — '.$ko['note'] : ''),
                    'created_by' => $user->id,
                ]);
                Audit::log('non_conformity.created', $nonConformity, ['code' => $nonConformity->code, 'origin' => 'inspection']);
            }

            Audit::log('inspection.completed', $inspection, ['outcome' => $outcome, 'template' => $template->name]);

            return $inspection;
        });

        return $this->show($inspection->id);
    }
}
