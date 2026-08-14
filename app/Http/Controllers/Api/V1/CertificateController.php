<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\User;
use App\Support\Audit;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;

/**
 * Scadenzario di patentini e certificati: chi coordina i lavori vede al
 * volo cosa è scaduto o sta per scadere (patentino fitosanitario, corsi
 * sicurezza, tarature attrezzature) e aggiorna le date al rinnovo.
 */
class CertificateController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:works.view', only: ['index']),
            new Middleware('can:works.manage', only: ['store', 'update', 'destroy']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        ListQuery::validateUuidFilters($request, ['user_id']);

        $query = Certificate::query()->with('user:id,name');
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->string('user_id'));
        }

        // Prima ciò che brucia: scaduti, poi scadenze vicine, in fondo
        // i certificati senza scadenza
        $rows = $query
            ->orderByRaw('expires_on ASC NULLS LAST')
            ->orderBy('holder_name')
            ->limit(500)->get()
            ->map(fn (Certificate $c) => $this->present($c));

        return response()->json([
            'data' => $rows,
            'total' => $rows->count() === 500 ? $query->count() : $rows->count(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $user = $request->user();
        $certificate = Certificate::create([
            ...$data,
            'tenant_id' => $user->tenant_id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        Audit::log('certificate.created', $certificate, ['title' => $certificate->title]);

        return response()->json(['data' => $this->present($certificate->fresh('user'))], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $this->validated($request, sometimes: true);

        $certificate = DB::transaction(function () use ($request, $id, $data) {
            $certificate = Certificate::query()->lockForUpdate()->findOrFail($id);
            if ($request->filled('version') && (int) $request->input('version') !== $certificate->version) {
                abort(409, "Conflitto di versione: il certificato è stato modificato da altri (versione attuale {$certificate->version}).");
            }

            $certificate->fill([...$data, 'updated_by' => $request->user()->id]);
            if ($certificate->isDirty()) {
                $certificate->version = $certificate->version + 1;
            }
            $certificate->save();
            Audit::log('certificate.updated', $certificate);

            return $certificate;
        });

        return response()->json(['data' => $this->present($certificate->fresh('user'))]);
    }

    public function destroy(Request $request, string $id): Response
    {
        DB::transaction(function () use ($request, $id) {
            $certificate = Certificate::query()->lockForUpdate()->findOrFail($id);
            $certificate->forceFill(['updated_by' => $request->user()->id])->save();
            $certificate->delete();
            Audit::log('certificate.deleted', $certificate, ['title' => $certificate->title]);
        });

        return response()->noContent();
    }

    private function validated(Request $request, bool $sometimes = false): array
    {
        $req = $sometimes ? 'sometimes' : 'required';

        $data = $request->validate([
            'version' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'uuid'],
            'holder_name' => [$req, 'string', 'max:200'],
            'title' => [$req, 'string', 'max:200'],
            'number' => ['nullable', 'string', 'max:100'],
            'issued_by' => ['nullable', 'string', 'max:200'],
            'issued_on' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date', 'after_or_equal:issued_on'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        unset($data['version']);

        if (! empty($data['user_id'])) {
            $data['user_id'] = strtolower($data['user_id']);
            User::query()->findOrFail($data['user_id']);
        }

        return $data;
    }

    private function present(Certificate $certificate): array
    {
        $daysLeft = $certificate->expires_on === null ? null
            : (int) now('Europe/Rome')->startOfDay()
                ->diffInDays($certificate->expires_on->toDateString(), false);

        return [
            ...$certificate->toArray(),
            'state' => $certificate->state(),
            'days_left' => $daysLeft,
        ];
    }
}
