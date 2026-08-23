<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Locality;
use App\Models\Site;
use App\Support\Audit;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\ValidationException;

class LocalityController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:areas.view', only: ['index', 'scheda']),
            new Middleware('can:clients.manage', only: ['store', 'update', 'destroy', 'documento', 'eliminaDocumento']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        ListQuery::validateUuidFilters($request, ['site_id']);

        $query = Locality::query()->with('site:id,name,client_id')->withCount('areas');

        if ($request->filled('site_id')) {
            $query->where('site_id', $request->string('site_id'));
        }

        return response()->json($query->orderBy('name')->paginate(ListQuery::perPage($request, 50, 200)));
    }

    /**
     * Scheda completa della localita': superfici, cosa c'e' dentro, chi ci
     * lavora e i documenti allegati.
     */
    public function scheda(string $id): JsonResponse
    {
        $localita = Locality::with(['site:id,name,client_id', 'site.client:id,name'])->findOrFail($id);

        return response()->json([
            'data' => \App\Services\Territorio\LocalitySheet::per($localita),
            'meta' => ['classificazioni' => config('istat.verde_urbano', [])],
        ]);
    }

    /** Allega un documento alla localita' (per esempio il piano di gestione). */
    public function documento(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'documento' => ['required', 'file', 'mimes:pdf', 'max:20480'],
            'titolo' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);

        $localita = Locality::findOrFail($id);
        $file = $request->file('documento');
        $path = $file->store("localities/{$localita->tenant_id}/{$localita->id}");

        $documento = \App\Models\Document::create([
            'tenant_id' => $localita->tenant_id,
            'subject_type' => $localita->getMorphClass(),
            'subject_id' => $localita->id,
            'doc_type' => 'other',
            'title' => $request->string('titolo')->toString() ?: $file->getClientOriginalName(),
            's3_key' => $path,
            'mime_type' => $file->getMimeType() ?: 'application/pdf',
            'size_bytes' => $file->getSize(),
            'hash_sha256' => hash_file('sha256', $file->getRealPath()),
            'uploaded_by' => $request->user()->id,
        ]);

        Audit::log('locality.document_uploaded', $localita, ['document' => $documento->title]);

        return response()->json(['data' => $documento], 201);
    }

    public function eliminaDocumento(string $id, string $documentId): Response
    {
        $localita = Locality::findOrFail($id);
        $documento = \App\Models\Document::query()
            ->where('subject_type', $localita->getMorphClass())
            ->where('subject_id', $localita->id)
            ->findOrFail($documentId);

        \Illuminate\Support\Facades\Storage::disk()->delete($documento->getRawOriginal('s3_key'));
        $documento->delete();

        Audit::log('locality.document_deleted', $localita, ['document' => $documento->title]);

        return response()->noContent();
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        Site::findOrFail($data['site_id']);

        $locality = Locality::create($data);
        Audit::log('locality.created', $locality, ['name' => $locality->name]);

        return response()->json(['data' => $locality], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $locality = Locality::findOrFail($id);
        $data = $this->validated($request, true);
        unset($data['site_id']);

        $locality->update($data);
        Audit::log('locality.updated', $locality);

        return response()->json(['data' => $locality->fresh()]);
    }

    public function destroy(string $id): Response
    {
        $locality = Locality::withCount('areas')->findOrFail($id);

        if ($locality->areas_count > 0) {
            throw ValidationException::withMessages([
                'locality' => "La località ha {$locality->areas_count} aree collegate: eliminale prima.",
            ]);
        }

        $locality->delete();
        Audit::log('locality.deleted', $locality);

        return response()->noContent();
    }

    private function validated(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'site_id' => [$updating ? 'sometimes' : 'required', 'uuid'],
            'name' => [$updating ? 'sometimes' : 'required', 'string', 'max:254'],
            'code' => ['sometimes', 'nullable', 'string', 'max:40'],
            'survey_zone_code' => ['sometimes', 'nullable', 'string', 'max:12'],
            // I valori ammessi stanno in config/istat.php: la tassonomia e'
            // esterna e cambia con le rilevazioni, quindi non e' fissata nel
            // database ne' nel codice
            'istat_class' => ['sometimes', 'nullable',
                \Illuminate\Validation\Rule::in(array_keys(config('istat.verde_urbano', [])))],
        ]);
    }
}
