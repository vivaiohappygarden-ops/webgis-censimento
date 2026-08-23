<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\Asset;
use App\Models\Organization;
use App\Models\PhytoTreatment;
use App\Models\User;
use App\Services\Pdf\LuogoFirma;
use App\Services\Pdf\PdfRenderer;
use App\Support\Audit;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Registro dei trattamenti fitosanitari (quaderno di campagna): ogni
 * trattamento resta agli atti con prodotto, dose e avversità; il registro
 * annuale esce in PDF pronto per un controllo.
 */
class PhytoTreatmentController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:works.view', only: ['index', 'registerPdf']),
            new Middleware('can:works.manage', only: ['store', 'update', 'destroy']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        ListQuery::validateUuidFilters($request, ['area_id']);
        $request->validate(['year' => ['nullable', 'integer', 'between:2000,2100']]);

        $rows = $this->filtered($request)
            ->with(['area:id,name', 'asset:id,census_code', 'operator:id,name'])
            ->orderByDesc('treated_on')->orderByDesc('created_at')
            ->limit(500)->get();

        // Il totale permette alla pagina di dire quando l'elenco è parziale
        // (il PDF del registro invece stampa sempre tutto)
        return response()->json([
            'data' => $rows,
            'total' => $rows->count() === 500 ? $this->filtered($request)->count() : $rows->count(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $this->assertAssetInArea($data['asset_id'] ?? null, $data['area_id']);

        $user = $request->user();
        $treatment = PhytoTreatment::create([
            ...$data,
            'tenant_id' => $user->tenant_id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        Audit::log('phyto_treatment.created', $treatment, [
            'product' => $treatment->product_name, 'treated_on' => $treatment->treated_on->toDateString(),
        ]);

        return response()->json(['data' => $this->presented($treatment->id)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $this->validated($request, sometimes: true);

        DB::transaction(function () use ($request, $id, $data) {
            $treatment = PhytoTreatment::query()->lockForUpdate()->findOrFail($id);
            if ($request->filled('version') && (int) $request->input('version') !== $treatment->version) {
                abort(409, "Conflitto di versione: il trattamento è stato modificato da altri (versione attuale {$treatment->version}).");
            }

            // La coerenza si valuta sullo stato finale: anche un cambio di
            // area senza toccare l'albero non deve lasciarli scollegati.
            // Un albero nel frattempo abbattuto (soft delete) resta valido
            // finché non cambia: correggere le note non deve dare 404
            $finalAsset = array_key_exists('asset_id', $data) ? $data['asset_id'] : $treatment->asset_id;
            $this->assertAssetInArea(
                $finalAsset,
                $data['area_id'] ?? $treatment->area_id,
                allowTrashed: $finalAsset !== null && $finalAsset === $treatment->asset_id,
            );

            $treatment->fill([...$data, 'updated_by' => $request->user()->id]);
            if ($treatment->isDirty()) {
                $treatment->version = $treatment->version + 1;
            }
            $treatment->save();
            Audit::log('phyto_treatment.updated', $treatment);
        });

        return response()->json(['data' => $this->presented($id)]);
    }

    public function destroy(Request $request, string $id): Response
    {
        DB::transaction(function () use ($request, $id) {
            $treatment = PhytoTreatment::query()->lockForUpdate()->findOrFail($id);
            $treatment->forceFill(['updated_by' => $request->user()->id])->save();
            $treatment->delete();
            Audit::log('phyto_treatment.deleted', $treatment, ['product' => $treatment->product_name]);
        });

        return response()->noContent();
    }

    /** Il registro annuale in PDF: quello che si esibisce a un controllo. */
    public function registerPdf(Request $request, PdfRenderer $renderer)
    {
        ListQuery::validateUuidFilters($request, ['area_id']);
        $request->validate(['year' => ['nullable', 'integer', 'between:2000,2100']]);
        $year = (int) ($request->input('year') ?: now('Europe/Rome')->year);

        $treatments = $this->filtered($request, $year)
            ->with(['area:id,name', 'asset:id,census_code', 'operator:id,name'])
            ->orderBy('treated_on')->orderBy('created_at')
            ->get();

        $area = $request->filled('area_id') ? Area::query()->find($request->string('area_id')) : null;

        // Un solo orologio per tutto il documento: vedi TreeBalanceController
        $adesso = now('Europe/Rome');

        $pdf = $renderer->render('pdf.phyto-register', [
            'treatments' => $treatments,
            'organization' => Organization::query()->find($request->user()->tenant_id),
            'stampatoIl' => $adesso,
            'luogoData' => LuogoFirma::riga($request->user()->tenant_id, $adesso),
            'year' => $year,
            'area' => $area,
        ]);

        Audit::log('phyto_treatment.register_pdf', null, ['year' => $year, 'count' => $treatments->count()]);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="registro_fitosanitari_'.$year.'.pdf"',
        ]);
    }

    private function filtered(Request $request, ?int $forcedYear = null)
    {
        $query = PhytoTreatment::query();
        $year = $forcedYear ?? $request->input('year');
        if ($year) {
            $query->whereBetween('treated_on', ["{$year}-01-01", "{$year}-12-31"]);
        }
        if ($request->filled('area_id')) {
            $query->where('area_id', $request->string('area_id'));
        }

        return $query;
    }

    private function validated(Request $request, bool $sometimes = false): array
    {
        $req = $sometimes ? 'sometimes' : 'required';

        $data = $request->validate([
            'version' => ['nullable', 'integer'],
            'area_id' => [$req, 'uuid'],
            'asset_id' => ['nullable', 'uuid'],
            'treated_on' => [$req, 'date', 'before_or_equal:'.now('Europe/Rome')->toDateString()],
            'product_name' => [$req, 'string', 'max:200'],
            'registration_number' => ['nullable', 'string', 'max:50'],
            'active_substance' => ['nullable', 'string', 'max:200'],
            'vegetation' => [$req, 'string', 'max:200'],
            'adversity' => [$req, 'string', 'max:200'],
            'method' => [$req, Rule::in(PhytoTreatment::METHODS)],
            'quantity' => [$req, 'numeric', 'decimal:0,3', 'gt:0', 'max:99999'],
            'unit' => [$req, Rule::in(PhytoTreatment::UNITS)],
            'water_volume_l' => ['nullable', 'numeric', 'decimal:0,1', 'min:0', 'max:99999'],
            'surface_sqm' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999'],
            'reentry_hours' => ['nullable', 'integer', 'between:0,720'],
            'operator_id' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        unset($data['version']);

        // La regola 'uuid' accetta anche l'esadecimale maiuscolo mentre il
        // DB restituisce la forma minuscola: si normalizza subito, o i
        // confronti stretti tra id fallirebbero su dati equivalenti
        foreach (['area_id', 'asset_id', 'operator_id'] as $field) {
            if (! empty($data[$field])) {
                $data[$field] = strtolower($data[$field]);
            }
        }

        // I riferimenti devono esistere nel tenant (l'albero si controlla
        // in assertAssetInArea, che sa quando ammettere un soft delete)
        if (array_key_exists('area_id', $data)) {
            Area::query()->findOrFail($data['area_id']);
        }
        if (! empty($data['operator_id'])) {
            User::query()->findOrFail($data['operator_id']);
        }

        return $data;
    }

    /** L'albero deve stare nell'area dichiarata, o il registro racconterebbe il falso. */
    private function assertAssetInArea(?string $assetId, string $areaId, bool $allowTrashed = false): void
    {
        if ($assetId === null || $assetId === '') {
            return;
        }
        $query = $allowTrashed ? Asset::withTrashed() : Asset::query();
        $asset = $query->findOrFail($assetId);
        if ($asset->area_id !== $areaId) {
            throw ValidationException::withMessages([
                'asset_id' => "L'elemento indicato non appartiene all'area del trattamento.",
            ]);
        }
    }

    private function presented(string $id): array
    {
        return PhytoTreatment::query()
            ->with(['area:id,name', 'asset:id,census_code', 'operator:id,name'])
            ->findOrFail($id)->toArray();
    }
}
