<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Asset;
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
                'asset:id,census_code', 'area:id,name', 'inspector:id,name',
            ])
            ->findOrFail($id);

        $pdf = $renderer->render('pdf.inspection', [
            'organization' => Organization::find($request->user()->tenant_id),
            'inspection' => $inspection,
            'nonConformities' => NonConformity::query()
                ->where('origin', 'inspection')->where('origin_id', $inspection->id)
                ->orderBy('code')->get(),
        ]);

        $stamp = $inspection->completed_at?->format('Ymd') ?? now()->format('Ymd');

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"verbale_ispezione_{$stamp}.pdf\"",
        ]);
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
        $photoDataUri = null;
        if ($photo !== null) {
            $content = Storage::disk()->get($photo->s3_key);
            if ($content !== null) {
                $photoDataUri = 'data:'.($photo->mime_type ?: 'image/jpeg').';base64,'.base64_encode($content);
            }
        }

        $fieldLabels = \App\Models\CustomField::query()
            ->where('object_type_id', $asset->object_type_id)
            ->pluck('label', 'key');

        $assessment = $asset->tree?->assessments()
            ->orderByDesc('assessed_on')->orderByDesc('created_at')->first();

        $pdf = $renderer->render('pdf.asset', [
            'organization' => Organization::find($request->user()->tenant_id),
            'asset' => $asset,
            'photoDataUri' => $photoDataUri,
            'fieldLabels' => $fieldLabels,
            'assessment' => $assessment,
        ]);

        $name = preg_replace('/[^A-Za-z0-9_-]/', '_', $asset->census_code ?? substr($asset->id, 0, 8));

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"scheda_{$name}.pdf\"",
        ]);
    }
}
