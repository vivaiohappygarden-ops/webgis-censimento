<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\InstrumentalAnalysis;
use App\Models\TreeAssessment;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Analisi strumentali (resistografo, tomografo, ...) collegate a una valutazione VTA. */
class InstrumentalAnalysisController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:assets.view', only: ['index']),
            new Middleware('can:assets.update', only: ['store']),
            new Middleware('can:assets.delete', only: ['destroy']),
        ];
    }

    public function index(string $assessmentId): JsonResponse
    {
        $assessment = TreeAssessment::findOrFail($assessmentId);

        return response()->json([
            'data' => $assessment->instrumentalAnalyses()
                ->with('document:id,title,mime_type,size_bytes')
                ->orderByDesc('measured_at')
                ->orderByDesc('created_at')
                ->get(),
        ]);
    }

    public function store(Request $request, string $assessmentId): JsonResponse
    {
        $assessment = TreeAssessment::findOrFail($assessmentId);

        $data = $request->validate([
            'instrument_type' => ['required',
                'in:resistograph,sonic_tomograph,electric_tomograph,pull_test,dendro_densimeter,other'],
            'instrument_model' => ['nullable', 'string', 'max:150'],
            'measured_at' => ['nullable', 'date'],
            'measurement_height_cm' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'measures' => ['sometimes', 'array'],
            'measures.*' => ['nullable', function (string $attribute, mixed $value, \Closure $fail) {
                if (is_array($value) || is_object($value)) {
                    $fail('Le misure devono essere valori semplici (numero o testo).');
                }
            }],
            'notes' => ['nullable', 'string'],
            'report' => ['nullable', 'file', 'mimes:pdf', 'max:20480'],
        ]);

        $analysis = DB::transaction(function () use ($request, $assessment, $data) {
            $analysis = InstrumentalAnalysis::create([
                'tenant_id' => $assessment->tenant_id,
                'assessment_id' => $assessment->id,
                'instrument_type' => $data['instrument_type'],
                'instrument_model' => $data['instrument_model'] ?? null,
                'measured_at' => $data['measured_at'] ?? null,
                'measurement_height_cm' => $data['measurement_height_cm'] ?? null,
                'measures' => $data['measures'] ?? [],
                'notes' => $data['notes'] ?? null,
            ]);

            if ($request->hasFile('report')) {
                $file = $request->file('report');
                $path = $file->store("documents/{$assessment->tenant_id}/{$assessment->tree_id}");

                $document = Document::create([
                    'tenant_id' => $assessment->tenant_id,
                    'asset_id' => $assessment->tree_id,
                    'subject_type' => 'instrumental_analysis',
                    'subject_id' => $analysis->id,
                    'doc_type' => 'vta_report',
                    'title' => $file->getClientOriginalName(),
                    's3_key' => $path,
                    'mime_type' => $file->getMimeType() ?: 'application/pdf',
                    'size_bytes' => $file->getSize(),
                    'hash_sha256' => hash_file('sha256', $file->getRealPath()),
                    'uploaded_by' => $request->user()->id,
                ]);

                $analysis->update(['document_id' => $document->id]);
            }

            return $analysis;
        });

        Audit::log('instrumental_analysis.created', $analysis, [
            'assessment_id' => $assessment->id,
            'instrument_type' => $analysis->instrument_type,
        ]);

        return response()->json([
            'data' => $analysis->fresh()->load('document:id,title,mime_type,size_bytes'),
        ], 201);
    }

    public function destroy(string $id): Response
    {
        $analysis = InstrumentalAnalysis::findOrFail($id);

        DB::transaction(function () use ($analysis) {
            // Il referto segue l'analisi: senza questo resterebbe scaricabile un file orfano
            if ($analysis->document_id && ($document = $analysis->document)) {
                \Illuminate\Support\Facades\Storage::disk()->delete($document->s3_key);
                $document->delete();
            }
            $analysis->delete();
        });

        Audit::log('instrumental_analysis.deleted', $analysis);

        return response()->noContent();
    }
}
