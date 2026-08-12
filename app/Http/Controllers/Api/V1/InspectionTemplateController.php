<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ChecklistItem;
use App\Models\InspectionTemplate;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InspectionTemplateController extends Controller implements HasMiddleware
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
            'data' => InspectionTemplate::query()
                ->withCount(['items', 'inspections'])
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json([
            'data' => InspectionTemplate::query()
                ->with('items')
                ->withCount('inspections')
                ->findOrFail($id),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        if (! empty($data['code']) && InspectionTemplate::query()->where('code', $data['code'])->exists()) {
            throw ValidationException::withMessages(['code' => "Codice modello già in uso: {$data['code']}."]);
        }

        try {
            $template = InspectionTemplate::create([...$data, 'tenant_id' => $request->user()->tenant_id]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => 'Codice modello già in uso.']);
        }

        Audit::log('inspection_template.created', $template, ['name' => $template->name]);

        return response()->json(['data' => $template], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $template = InspectionTemplate::query()->findOrFail($id);
        $data = $this->validated($request, required: false);

        if (! empty($data['code']) && $data['code'] !== $template->code
            && InspectionTemplate::query()->where('code', $data['code'])->exists()) {
            throw ValidationException::withMessages(['code' => "Codice modello già in uso: {$data['code']}."]);
        }

        // Il bersaglio (elemento o area) non si cambia dopo le prime ispezioni:
        // le esecuzioni passate diventerebbero illeggibili
        if (isset($data['target']) && $data['target'] !== $template->target
            && $template->inspections()->exists()) {
            throw ValidationException::withMessages([
                'target' => 'Modello già usato da ispezioni: il tipo di bersaglio non si cambia. Crea un nuovo modello.',
            ]);
        }

        try {
            $template->update($data);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => 'Codice modello già in uso.']);
        }

        Audit::log('inspection_template.updated', $template);

        return $this->show($id);
    }

    public function destroy(string $id): Response
    {
        $template = InspectionTemplate::query()->findOrFail($id);

        if ($template->inspections()->exists()) {
            throw ValidationException::withMessages([
                'template' => 'Modello già usato da ispezioni: disattivalo invece di eliminarlo.',
            ]);
        }

        $template->delete();

        Audit::log('inspection_template.deleted', $template, ['name' => $template->name]);

        return response()->noContent();
    }

    /**
     * Sostituzione integrale delle domande. Se il modello è già stato usato
     * la versione avanza: le ispezioni passate restano congelate sulla loro.
     */
    public function syncItems(Request $request, string $id): JsonResponse
    {
        $template = InspectionTemplate::query()->findOrFail($id);

        $data = $request->validate([
            'items' => ['present', 'array', 'max:200'],
            'items.*.question' => ['required', 'string', 'max:500'],
            'items.*.answer_type' => ['sometimes', Rule::in(['ok_ko', 'ok_ko_na', 'text', 'number'])],
            'items.*.ko_creates_nc' => ['sometimes', 'boolean'],
            'items.*.weight' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        DB::transaction(function () use ($template, $data, $request) {
            ChecklistItem::query()->where('template_id', $template->id)->delete();
            foreach ($data['items'] as $index => $item) {
                ChecklistItem::create([
                    'tenant_id' => $request->user()->tenant_id,
                    'template_id' => $template->id,
                    'sort_order' => $index + 1,
                    'question' => $item['question'],
                    'answer_type' => $item['answer_type'] ?? 'ok_ko',
                    'ko_creates_nc' => $item['ko_creates_nc'] ?? true,
                    'weight' => $item['weight'] ?? 1,
                ]);
            }

            if ($template->inspections()->exists()) {
                $template->version += 1;
                $template->save();
            }
        });

        Audit::log('inspection_template.items_updated', $template, ['count' => count($data['items'])]);

        return $this->show($id);
    }

    private function validated(Request $request, bool $required = true): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$presence, 'string', 'max:254'],
            'code' => ['nullable', 'string', 'max:50'],
            'target' => ['sometimes', Rule::in(['asset', 'area'])],
            'standard_ref' => ['nullable', 'string', 'max:150'],
            'frequency_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
