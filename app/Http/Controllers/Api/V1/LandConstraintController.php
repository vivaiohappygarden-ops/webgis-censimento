<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Document;
use App\Models\LandConstraint;
use App\Support\Audit;
use App\Support\Geometry;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Anagrafica dei vincoli del territorio e loro collegamento agli elementi.
 *
 * Il collegamento può essere fatto a mano oppure ricavato dal perimetro del
 * vincolo. Il ricalcolo geografico non tocca mai i collegamenti fatti a
 * mano: chi ha deciso una cosa a mano l'ha decisa per un motivo.
 */
class LandConstraintController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:areas.view', only: ['index', 'perAsset']),
            new Middleware('can:areas.update', only: [
                'store', 'update', 'documento', 'ricalcola', 'collega', 'scollega',
            ]),
            new Middleware('can:areas.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        ListQuery::validateUuidFilters($request, ['client_id']);

        $query = LandConstraint::query()
            ->with(['client:id,name', 'document:id,title,mime_type,size_bytes'])
            ->withCount('assets')
            ->selectRaw('land_constraints.*, (geom IS NOT NULL) AS ha_perimetro');

        if ($request->filled('client_id')) {
            $query->where(fn ($w) => $w->where('client_id', $request->string('client_id'))
                ->orWhereNull('client_id'));
        }

        return response()->json($query->orderBy('code')->paginate(ListQuery::perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $vincolo = new LandConstraint([
            ...collect($data)->except('geometry')->all(),
            'tenant_id' => $request->user()->tenant_id,
            'created_by' => $request->user()->id,
        ]);
        $vincolo->save();

        $this->salvaGeometria($vincolo, $data['geometry'] ?? null);

        Audit::log('constraint.created', $vincolo, ['code' => $vincolo->code]);

        return response()->json(['data' => $vincolo->fresh()], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $vincolo = LandConstraint::findOrFail($id);
        $data = $this->validated($request, richiesto: false);

        $vincolo->fill(collect($data)->except('geometry')->all());
        $vincolo->save();

        if (array_key_exists('geometry', $data)) {
            $this->salvaGeometria($vincolo, $data['geometry']);
        }

        Audit::log('constraint.updated', $vincolo);

        return response()->json(['data' => $vincolo->fresh()]);
    }

    public function destroy(string $id): Response
    {
        $vincolo = LandConstraint::findOrFail($id);
        $vincolo->delete();
        Audit::log('constraint.deleted', $vincolo);

        return response()->noContent();
    }

    /** Documento del vincolo: il PDF che il cittadino potrà scaricare. */
    public function documento(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'documento' => ['required', 'file', 'mimes:pdf', 'max:20480'],
        ]);

        $vincolo = LandConstraint::findOrFail($id);
        $file = $request->file('documento');
        $path = $file->store("constraints/{$vincolo->tenant_id}/{$vincolo->id}");

        $documento = Document::create([
            'tenant_id' => $vincolo->tenant_id,
            'subject_type' => $vincolo->getMorphClass(),
            'subject_id' => $vincolo->id,
            'doc_type' => 'other',
            'title' => $file->getClientOriginalName(),
            's3_key' => $path,
            'mime_type' => $file->getMimeType() ?: 'application/pdf',
            'size_bytes' => $file->getSize(),
            'hash_sha256' => hash_file('sha256', $file->getRealPath()),
            'uploaded_by' => $request->user()->id,
        ]);

        // Il documento precedente non serve più a nessuno
        $vecchio = $vincolo->document;
        $vincolo->document_id = $documento->id;
        $vincolo->save();
        if ($vecchio !== null) {
            Storage::disk()->delete($vecchio->getRawOriginal('s3_key'));
            $vecchio->delete();
        }

        Audit::log('constraint.document_uploaded', $vincolo, ['document' => $documento->title]);

        return response()->json(['data' => $vincolo->fresh()->load('document:id,title,mime_type,size_bytes')]);
    }

    /**
     * Ricalcola i collegamenti geografici di un vincolo: chi ricade dentro
     * il perimetro se lo prende, chi ne è uscito lo perde. I collegamenti
     * fatti a mano restano dove sono.
     */
    public function ricalcola(string $id): JsonResponse
    {
        $vincolo = LandConstraint::findOrFail($id);

        $haPerimetro = (bool) DB::selectOne(
            'SELECT geom IS NOT NULL AS c FROM land_constraints WHERE id = ?', [$vincolo->id],
        )->c;

        if (! $haPerimetro) {
            throw ValidationException::withMessages([
                'geometry' => 'Questo vincolo non ha un perimetro: i collegamenti si fanno a mano dalla scheda dell\'elemento.',
            ]);
        }

        $collegati = DB::transaction(function () use ($vincolo) {
            DB::delete(
                "DELETE FROM asset_constraints WHERE constraint_id = ? AND source = 'spatial'",
                [$vincolo->id],
            );

            return DB::affectingStatement(<<<'SQL'
                INSERT INTO asset_constraints (tenant_id, asset_id, constraint_id, source)
                SELECT a.tenant_id, a.id, v.id, 'spatial'
                FROM assets a
                JOIN land_constraints v ON v.id = ?
                WHERE a.tenant_id = v.tenant_id
                  AND a.deleted_at IS NULL
                  AND ST_Intersects(a.geom, v.geom)
                ON CONFLICT (asset_id, constraint_id) DO NOTHING
                SQL, [$vincolo->id]);
        });

        Audit::log('constraint.recalculated', $vincolo, ['collegati' => $collegati]);

        return response()->json(['data' => ['collegati' => $collegati]]);
    }

    /** Vincoli collegati a un elemento, con l'indicazione di come ci sono arrivati. */
    public function perAsset(string $assetId): JsonResponse
    {
        $asset = Asset::findOrFail($assetId);

        $righe = DB::table('asset_constraints as ac')
            ->join('land_constraints as v', 'v.id', '=', 'ac.constraint_id')
            ->where('ac.asset_id', $asset->id)
            ->whereNull('v.deleted_at')
            ->orderBy('v.code')
            ->get(['v.id', 'v.code', 'v.name', 'v.authority', 'v.is_public', 'ac.source']);

        return response()->json(['data' => $righe]);
    }

    public function collega(Request $request, string $assetId): JsonResponse
    {
        $data = $request->validate(['constraint_id' => ['required', 'uuid']]);

        $asset = Asset::findOrFail($assetId);
        $vincolo = LandConstraint::findOrFail($data['constraint_id']);

        DB::table('asset_constraints')->insertOrIgnore([
            'id' => (string) \Illuminate\Support\Str::uuid7(),
            'tenant_id' => $asset->tenant_id,
            'asset_id' => $asset->id,
            'constraint_id' => $vincolo->id,
            'source' => 'manual',
            'created_at' => now(),
        ]);

        Audit::log('constraint.linked', $asset, ['constraint' => $vincolo->code]);

        return $this->perAsset($assetId);
    }

    public function scollega(string $assetId, string $constraintId): JsonResponse
    {
        $asset = Asset::findOrFail($assetId);

        DB::table('asset_constraints')
            ->where('asset_id', $asset->id)
            ->where('constraint_id', $constraintId)
            ->delete();

        Audit::log('constraint.unlinked', $asset, ['constraint' => $constraintId]);

        return $this->perAsset($assetId);
    }

    private function salvaGeometria(LandConstraint $vincolo, ?array $geometria): void
    {
        if ($geometria === null) {
            DB::update('UPDATE land_constraints SET geom = NULL WHERE id = ?', [$vincolo->id]);

            return;
        }

        DB::update('UPDATE land_constraints SET geom = ? WHERE id = ?', [
            Geometry::toEwkb($geometria, forceMultiPolygon: true), $vincolo->id,
        ]);
    }

    private function validated(Request $request, bool $richiesto = true): array
    {
        $presenza = $richiesto ? 'required' : 'sometimes';

        return $request->validate([
            'code' => [$presenza, 'string', 'max:80'],
            'name' => ['sometimes', 'nullable', 'string', 'max:254'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'authority' => ['sometimes', 'nullable', 'string', 'max:254'],
            'client_id' => ['sometimes', 'nullable', 'uuid'],
            'is_public' => ['sometimes', 'boolean'],
            'geometry' => ['sometimes', 'nullable', 'array'],
            'geometry.type' => ['required_with:geometry', 'string', 'in:Polygon,MultiPolygon'],
            'geometry.coordinates' => ['required_with:geometry', 'array'],
        ]);
    }
}
