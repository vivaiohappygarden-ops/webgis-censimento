<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\WorkOrder;
use App\Models\WorkType;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PriceListController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:works.view', only: ['index', 'show']),
            new Middleware('can:works.manage', only: ['store', 'update', 'destroy', 'syncItems']),
        ];
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => PriceList::query()
                ->withCount('items')
                ->orderByDesc('year')->orderBy('code')
                ->get(),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $priceList = PriceList::query()
            ->with(['items' => fn ($q) => $q->with('workType:id,code,name,unit')->orderBy('created_at')])
            ->findOrFail($id);

        return response()->json(['data' => $priceList]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        if (PriceList::query()->where('code', $data['code'])->exists()) {
            throw ValidationException::withMessages(['code' => "Codice listino già in uso: {$data['code']}."]);
        }

        $priceList = PriceList::create([...$data, 'tenant_id' => $request->user()->tenant_id]);

        Audit::log('price_list.created', $priceList, ['code' => $priceList->code]);

        return response()->json(['data' => $priceList], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $priceList = PriceList::query()->findOrFail($id);
        $data = $this->validated($request, required: false);

        if (isset($data['code']) && $data['code'] !== $priceList->code
            && PriceList::query()->where('code', $data['code'])->exists()) {
            throw ValidationException::withMessages(['code' => "Codice listino già in uso: {$data['code']}."]);
        }

        $priceList->update($data);

        Audit::log('price_list.updated', $priceList);

        return $this->show($id);
    }

    public function destroy(string $id): Response
    {
        $priceList = PriceList::query()->findOrFail($id);

        // Un listino agganciato a ordini di lavoro non si elimina: la storia
        // economica degli ordini deve restare ricostruibile
        if (WorkOrder::query()->where('price_list_id', $priceList->id)->exists()) {
            throw ValidationException::withMessages([
                'price_list' => 'Listino usato da uno o più ordini di lavoro: disattivalo invece di eliminarlo.',
            ]);
        }

        $priceList->delete();

        Audit::log('price_list.deleted', $priceList, ['code' => $priceList->code]);

        return response()->noContent();
    }

    /** Sostituzione integrale delle voci del listino (l'elenco inviato è lo stato voluto). */
    public function syncItems(Request $request, string $id): JsonResponse
    {
        $priceList = PriceList::query()->findOrFail($id);

        $data = $request->validate([
            'items' => ['present', 'array', 'max:500'],
            'items.*.work_type_id' => ['required', 'uuid'],
            'items.*.item_code' => ['nullable', 'string', 'max:50'],
            'items.*.description' => ['nullable', 'string', 'max:500'],
            'items.*.unit' => ['required', 'string', 'max:20'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'items.*.overhead_pct' => ['nullable', 'numeric', 'min:0', 'max:999'],
            'items.*.safety_cost' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ]);

        // Doppioni nel payload intercettati prima del vincolo UNIQUE del DB
        $keys = collect($data['items'])->map(fn ($i) => $i['work_type_id'].'|'.($i['item_code'] ?? ''));
        if ($keys->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages([
                'items' => 'Due voci con la stessa lavorazione e lo stesso codice articolo.',
            ]);
        }

        $workTypeIds = collect($data['items'])->pluck('work_type_id')->unique();
        $known = WorkType::query()->whereIn('id', $workTypeIds)->pluck('id');
        if ($known->count() !== $workTypeIds->count()) {
            throw ValidationException::withMessages(['items' => 'Lavorazione inesistente per questa organizzazione.']);
        }

        DB::transaction(function () use ($priceList, $data, $request) {
            PriceListItem::query()->where('price_list_id', $priceList->id)->forceDelete();
            foreach ($data['items'] as $item) {
                PriceListItem::create([
                    'tenant_id' => $request->user()->tenant_id,
                    'price_list_id' => $priceList->id,
                    'work_type_id' => $item['work_type_id'],
                    'item_code' => $item['item_code'] ?? null,
                    'description' => $item['description'] ?? null,
                    'unit' => $item['unit'],
                    'unit_price' => $item['unit_price'],
                    'overhead_pct' => $item['overhead_pct'] ?? null,
                    'safety_cost' => $item['safety_cost'] ?? null,
                ]);
            }
        });

        Audit::log('price_list.items_updated', $priceList, ['count' => count($data['items'])]);

        return $this->show($id);
    }

    private function validated(Request $request, bool $required = true): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return $request->validate([
            'code' => [$presence, 'string', 'max:50'],
            'name' => [$presence, 'string', 'max:254'],
            'source' => ['nullable', 'string', 'max:254'],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
