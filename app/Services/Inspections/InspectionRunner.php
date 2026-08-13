<?php

namespace App\Services\Inspections;

use App\Models\Inspection;
use App\Models\InspectionTemplate;
use App\Models\NonConformity;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Esecuzione di un'ispezione su checklist: validazione delle risposte per
 * tipo, esito calcolato, fotografia delle domande, non conformità dalle
 * voci KO. Un solo motore per backoffice e sincronizzazione dal campo.
 */
class InspectionRunner
{
    /**
     * @param  array  $data  template_id, asset_id|area_id, answers {item_id: {value, note}}, started_at?
     * @param  string|null  $forcedId  id scelto dal device (UUID v7) per il percorso offline
     * @param  \Illuminate\Support\Carbon|null  $completedAt  momento del completamento in campo
     *
     * @throws ValidationException
     */
    public function run(User $user, array $data, ?string $forcedId = null, $completedAt = null): Inspection
    {
        $template = InspectionTemplate::query()->with('items')->find($data['template_id']);
        if ($template === null) {
            throw ValidationException::withMessages(['template_id' => 'Modello inesistente per questa organizzazione.']);
        }
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
            if (! \App\Models\Asset::query()->withTrashed()->whereKey($data['asset_id'])->exists()) {
                throw ValidationException::withMessages(['asset_id' => 'Elemento inesistente per questa organizzazione.']);
            }
        } else {
            if (empty($data['area_id']) || ! empty($data['asset_id'])) {
                throw ValidationException::withMessages(['area_id' => 'Questo modello si applica a un\'area.']);
            }
            if (! \App\Models\Area::query()->withTrashed()->whereKey($data['area_id'])->exists()) {
                throw ValidationException::withMessages(['area_id' => 'Area inesistente per questa organizzazione.']);
            }
        }

        $rawAnswers = $data['answers'] ?? [];
        if (! is_array($rawAnswers) || $rawAnswers === []) {
            throw ValidationException::withMessages(['answers' => 'Nessuna risposta nel modulo.']);
        }

        // Risposte a domande estranee al modello: errore esplicito, mai scarto silenzioso.
        // Dal campo capita con un modello aggiornato dopo lo scarico: va ridetto chiaro
        $knownIds = $template->items->pluck('id')->all();
        $unknown = array_diff(array_keys($rawAnswers), $knownIds);
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'answers' => 'Le risposte non combaciano con la versione attuale del modello: aggiorna i dati e ripeti l\'ispezione.',
            ]);
        }

        // Ogni domanda vuole la sua risposta, con valori ammessi per tipo
        $answers = [];
        $koItems = [];
        $remarks = false;
        foreach ($template->items as $item) {
            $answer = $rawAnswers[$item->id] ?? null;
            if ($answer === null || ! is_array($answer) || ! array_key_exists('value', $answer)) {
                throw ValidationException::withMessages([
                    'answers' => "Manca la risposta alla domanda: \"{$item->question}\".",
                ]);
            }
            if (! is_scalar($answer['value'])) {
                throw ValidationException::withMessages([
                    'answers' => "Risposta non valida per \"{$item->question}\".",
                ]);
            }
            $note = isset($answer['note']) && is_string($answer['note']) && trim($answer['note']) !== ''
                ? mb_substr(trim($answer['note']), 0, 2000)
                : null;
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
                    $koItems[] = ['item' => $item, 'note' => $note];
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
            if ($note !== null) {
                $remarks = true;
            }

            // Fotografia completa: anche il testo della domanda resta
            // nell'ispezione, leggibile pure se il modello cambierà
            $answers[$item->id] = [
                'question' => $item->question,
                'answer_type' => $item->answer_type,
                // jsonb non conserva l'ordine delle chiavi: l'ordine di
                // stampa viaggia dentro la fotografia
                'sort_order' => $item->sort_order,
                'value' => $value,
                'note' => $note,
            ];
        }

        $outcome = match (true) {
            $koItems !== [] => 'failed',
            $remarks => 'passed_with_remarks',
            default => 'passed',
        };

        return DB::transaction(function () use ($data, $user, $template, $answers, $outcome, $koItems, $forcedId, $completedAt) {
            $inspection = new Inspection([
                'tenant_id' => $user->tenant_id,
                'template_id' => $template->id,
                'template_version' => $template->version,
                'asset_id' => $data['asset_id'] ?? null,
                'area_id' => $data['area_id'] ?? null,
                'inspector_id' => $user->id,
                'started_at' => $data['started_at'] ?? $completedAt ?? now(),
                'completed_at' => $completedAt ?? now(),
                'answers' => $answers,
                'outcome' => $outcome,
            ]);
            if ($forcedId !== null) {
                $inspection->id = $forcedId;
            }
            $inspection->save();

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
    }
}
