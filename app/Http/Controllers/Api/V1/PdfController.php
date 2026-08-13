<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\ChecklistItem;
use App\Models\Inspection;
use App\Models\NonConformity;
use App\Models\Organization;
use App\Services\Pdf\PdfRenderer;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Storage;

/** Stampe: verbale di ispezione e scheda dell'elemento censito. */
class PdfController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:works.view', only: ['inspection']),
            new Middleware('can:assets.view', only: ['asset']),
        ];
    }

    public function inspection(Request $request, PdfRenderer $renderer, string $id)
    {
        $inspection = Inspection::query()
            ->with([
                'template:id,code,name,target,standard_ref',
                // Il verbale resta leggibile anche se il bersaglio è stato
                // poi rimosso dal censimento
                'asset' => fn ($q) => $q->withTrashed()->select('id', 'census_code'),
                'area' => fn ($q) => $q->withTrashed()->select('id', 'name'),
                'inspector:id,name',
            ])
            ->findOrFail($id);

        $pdf = $renderer->render('pdf.inspection', [
            'organization' => Organization::find($request->user()->tenant_id),
            'inspection' => $inspection,
            'rows' => $this->checklistRows($inspection),
            'nonConformities' => NonConformity::query()
                ->where('origin', 'inspection')->where('origin_id', $inspection->id)
                ->orderBy('code')->get(),
        ]);

        $stamp = ($inspection->completed_at ?? now())->timezone('Europe/Rome')->format('Ymd');

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"verbale_ispezione_{$stamp}_".substr($inspection->id, 0, 8).'.pdf"',
        ]);
    }

    /**
     * Righe della checklist in ordine di modello: jsonb non conserva
     * l'ordine delle chiavi, quindi si ordina sulla fotografia (o sulla
     * voce attuale per le ispezioni registrate prima di questa aggiunta).
     * Le risposte libere lunghe scendono sotto la domanda: una cella
     * stretta con duemila caratteri produrrebbe pagine illeggibili.
     */
    private function checklistRows(Inspection $inspection): array
    {
        $items = ChecklistItem::query()
            ->whereIn('id', array_keys($inspection->answers ?? []))
            ->get()->keyBy('id');

        return collect($inspection->answers ?? [])
            ->map(function ($answer, $itemId) use ($items) {
                $type = $answer['answer_type'] ?? $items[$itemId]?->answer_type;
                $value = $answer['value'] ?? null;
                $isChoice = in_array($type, ['ok_ko', 'ok_ko_na'], true);
                $display = $isChoice
                    ? (['ok' => 'OK', 'ko' => 'KO', 'na' => 'N.A.'][$value] ?? (string) $value)
                    : trim((string) ($value ?? ''));
                $fits = $isChoice || mb_strlen($display) <= 20;

                return [
                    'question' => $answer['question'] ?? ($items[$itemId]?->question ?? '-'),
                    'short' => $fits ? ($display !== '' ? $display : '-') : null,
                    'long' => $fits ? null : $display,
                    'note' => $answer['note'] ?? null,
                    'order' => $answer['sort_order'] ?? $items[$itemId]?->sort_order ?? 9999,
                    'tiebreak' => (string) $itemId,
                ];
            })
            ->sortBy([['order', 'asc'], ['tiebreak', 'asc']])
            ->values()
            ->all();
    }

    public function asset(Request $request, PdfRenderer $renderer, string $id)
    {
        $asset = Asset::query()
            ->with([
                'objectType.subType.mainType',
                'area.locality.site.client',
                'tree',
            ])
            ->findOrFail($id);

        // La stessa foto di riferimento dell'export CAM: prima la categoria
        // censimento, poi la più recente
        $photo = $asset->photos()
            ->orderByRaw("(category = 'census') DESC")
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        $fields = \App\Models\CustomField::query()
            ->where('object_type_id', $asset->object_type_id)
            ->get()->keyBy('key');

        $assessment = $asset->tree?->assessments()
            ->orderByDesc('assessed_on')->orderByDesc('created_at')->first();

        $pdf = $renderer->render('pdf.asset', [
            'organization' => Organization::find($request->user()->tenant_id),
            'asset' => $asset,
            'photoDataUri' => $photo !== null ? $this->photoDataUri($photo) : null,
            'fields' => $fields,
            'assessment' => $assessment,
        ]);

        $name = preg_replace('/[^A-Za-z0-9_-]/', '_', $asset->census_code ?? substr($asset->id, 0, 8));

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"scheda_{$name}.pdf\"",
        ]);
    }

    /**
     * Foto incorporata in dimensione da stampa: l'originale può pesare
     * 15 MB e decine di megapixel, e finirebbe intero nel PDF (e nella
     * memoria di dompdf). Oltre le soglie di sicurezza si rinuncia alla
     * foto, mai alla scheda.
     */
    private function photoDataUri(\App\Models\Photo $photo): ?string
    {
        $jpeg = \App\Services\Photos\ImageDerivative::jpeg(
            Storage::disk()->get($photo->s3_key),
            maxDimension: 1200,
            quality: 78,
        );

        return $jpeg !== null ? 'data:image/jpeg;base64,'.base64_encode($jpeg) : null;
    }
}
