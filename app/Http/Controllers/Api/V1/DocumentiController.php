<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\RicercaTestuale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Documenti (veste nuova, blocco 5): tutto cio' che si stampa, si firma o si
 * consegna, in un elenco solo. Non e' una tabella nuova: sono le perizie
 * emesse, i verbali di ispezione chiusi, i preventivi, i SAL e le
 * esportazioni gia' fatte (dal registro delle operazioni), letti dalle loro
 * tabelle e messi in fila per data. Ogni sorgente esce solo a chi ha il
 * permesso della sua pagina.
 */
class DocumentiController extends Controller
{
    private const TIPI = ['perizia', 'verbale', 'preventivo', 'sal', 'esportazione'];

    private const STATO_PREVENTIVO = ['draft' => 'Bozza', 'sent' => 'Inviato', 'accepted' => 'Accettato', 'rejected' => 'Rifiutato'];

    private const ESITO_ISPEZIONE = [
        'passed' => 'Esito positivo', 'passed_with_remarks' => 'Positivo con osservazioni', 'failed' => 'Esito negativo', 'not_completed' => 'Non completata',
    ];

    private const ESPORTAZIONI = [
        'export.cam' => 'Esportazione CAM',
        'export.cam_delivery' => 'Consegna CAM completa',
        'export.assets_csv' => 'Elenco del censimento (CSV)',
        'export.assets_xlsx' => 'Elenco del censimento (Excel)',
        'export.vta_registro' => 'Registro delle valutazioni VTA (CSV)',
    ];

    /** Righe al massimo per sorgente: l'elenco racconta gli ultimi, non l'archivio intero. */
    private const TETTO = 500;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('assets.view') || $user->can('works.view'), 403);
        $request->validate([
            'tipo' => ['sometimes', 'nullable', 'string'],
            'client_id' => ['sometimes', 'nullable', 'uuid'],
            'anno' => ['sometimes', 'nullable', 'integer', 'between:2000,2100'],
            'stato' => ['sometimes', 'nullable', 'in:da_validare'],
            'q' => ['sometimes', 'nullable', ...RicercaTestuale::regole()],
        ]);

        $tenantId = $user->tenant_id;
        $righe = collect();
        if ($user->can('assets.view')) {
            $righe = $righe->concat($this->perizie($tenantId))->concat($this->esportazioni($tenantId));
        }
        if ($user->can('works.view')) {
            $righe = $righe->concat($this->verbali($tenantId))->concat($this->preventivi($tenantId))->concat($this->sal($tenantId));
        }
        $righe = $righe->sortByDesc(fn ($r) => ($r['data'] ?? '').'|'.($r['ora'] ?? ''))->values();

        // I conteggi delle schede e degli anni si fanno prima dei filtri di
        // tipo e stato: le schede devono dire quanti ce ne sono, sempre
        $base = $righe;
        if ($request->filled('client_id')) {
            $base = $base->where('client_id', $request->string('client_id')->toString());
        }
        if ($request->filled('anno')) {
            $base = $base->where('anno', (int) $request->input('anno'));
        }
        if ($request->filled('q')) {
            $parole = array_slice(array_values(array_filter(preg_split('/\s+/u', trim($request->string('q')->toString())) ?: [])), 0, 6);
            $base = $base->filter(function ($r) use ($parole) {
                // Stessa regola della ricerca del gestionale: parole in AND, accenti ignorati
                $testo = Str::ascii(mb_strtolower(implode(' ', [$r['titolo'], $r['codice'] ?? '', $r['committente'] ?? '', $r['utente'] ?? ''])));
                foreach ($parole as $p) {
                    if (! str_contains($testo, Str::ascii(mb_strtolower($p)))) {
                        return false;
                    }
                }

                return true;
            });
        }
        $conteggi = ['tutti' => $base->count(), 'da_validare' => $base->where('da_validare', true)->count()];
        foreach (self::TIPI as $tipo) {
            $conteggi[$tipo] = $base->where('tipo', $tipo)->count();
        }

        $filtrate = $base;
        if ($request->filled('tipo')) {
            $tipi = array_values(array_intersect(explode(',', $request->string('tipo')->toString()), self::TIPI));
            $filtrate = $filtrate->whereIn('tipo', $tipi);
        }
        if ($request->input('stato') === 'da_validare') {
            $filtrate = $filtrate->where('da_validare', true);
        }

        return response()->json([
            'data' => $filtrate->values()->all(),
            'conteggi' => $conteggi,
            'anni' => $righe->pluck('anno')->filter()->unique()->sortDesc()->values()->all(),
        ]);
    }

    /** Perizie emesse: rapporto numerato, validato o ancora da validare. */
    private function perizie(string $tenantId): array
    {
        return collect(DB::select(<<<'SQL'
            SELECT ta.id, ta.report_number, ta.report_issued_at, ta.validated_at, ta.content_hash, ta.tree_id,
                   a.census_code, t.species, c.id AS client_id, c.name AS client_name
            FROM tree_assessments ta
            JOIN assets a ON a.id = ta.tree_id
            LEFT JOIN trees t ON t.asset_id = a.id
            LEFT JOIN areas ar ON ar.id = a.area_id
            LEFT JOIN localities l ON l.id = ar.locality_id
            LEFT JOIN sites s ON s.id = l.site_id
            LEFT JOIN clients c ON c.id = s.client_id
            WHERE ta.tenant_id = ? AND ta.deleted_at IS NULL AND ta.report_issued_at IS NOT NULL
            ORDER BY ta.report_issued_at DESC
            LIMIT 500
            SQL, [$tenantId]))->map(fn ($r) => $this->riga('perizia', $r->id, $r->report_number,
            'Perizia '.($r->report_number ?? '').' · '.($r->census_code ?? 'senza cartellino').($r->species ? ' '.$r->species : ''),
            $r->report_issued_at, $r->validated_at ? 'Validata' : 'Da validare', $r->client_id, $r->client_name, [
                'da_validare' => $r->validated_at === null,
                'impronta' => $r->content_hash,
                'href' => '/censimento/'.$r->tree_id.'?vta=1',
                'pdf' => '/api/v1/assessments/'.$r->id.'/perizia-pdf',
            ]))->all();
    }

    /** Verbali di ispezione chiusi. */
    private function verbali(string $tenantId): array
    {
        return collect(DB::select(<<<'SQL'
            SELECT i.id, i.completed_at, i.outcome, it.name AS template, a.census_code, ar.name AS area_name,
                   c.id AS client_id, c.name AS client_name
            FROM inspections i
            LEFT JOIN inspection_templates it ON it.id = i.template_id
            LEFT JOIN assets a ON a.id = i.asset_id
            LEFT JOIN areas ar ON ar.id = COALESCE(i.area_id, a.area_id)
            LEFT JOIN localities l ON l.id = ar.locality_id
            LEFT JOIN sites s ON s.id = l.site_id
            LEFT JOIN clients c ON c.id = s.client_id
            WHERE i.tenant_id = ? AND i.deleted_at IS NULL AND i.completed_at IS NOT NULL
            ORDER BY i.completed_at DESC
            LIMIT 500
            SQL, [$tenantId]))->map(fn ($r) => $this->riga('verbale', $r->id, null,
            'Verbale di ispezione · '.($r->template ?? 'controllo').' · '.($r->census_code ?? $r->area_name ?? ''),
            $r->completed_at, self::ESITO_ISPEZIONE[$r->outcome] ?? ($r->outcome ?? '—'), $r->client_id, $r->client_name, [
                'href' => '/ispezioni', 'pdf' => '/api/v1/inspections/'.$r->id.'/pdf',
            ]))->all();
    }

    private function preventivi(string $tenantId): array
    {
        return collect(DB::select(<<<'SQL'
            SELECT e.id, e.code, e.title, e.status, e.created_at, c.id AS client_id, c.name AS client_name
            FROM estimates e LEFT JOIN clients c ON c.id = e.client_id
            WHERE e.tenant_id = ? AND e.deleted_at IS NULL
            ORDER BY e.created_at DESC LIMIT 500
            SQL, [$tenantId]))->map(fn ($r) => $this->riga('preventivo', $r->id, $r->code,
            'Preventivo '.$r->code.($r->title ? ' · '.$r->title : ''), $r->created_at,
            self::STATO_PREVENTIVO[$r->status] ?? $r->status, $r->client_id, $r->client_name, [
                'href' => '/lavori?vista=preventivi', 'pdf' => '/api/v1/estimates/'.$r->id.'/pdf',
            ]))->all();
    }

    private function sal(string $tenantId): array
    {
        return collect(DB::select(<<<'SQL'
            SELECT s.id, s.code, s.status, s.period_to, s.created_at, c.id AS client_id, c.name AS client_name
            FROM sals s LEFT JOIN clients c ON c.id = s.client_id
            WHERE s.tenant_id = ?
            ORDER BY s.created_at DESC LIMIT 500
            SQL, [$tenantId]))->map(fn ($r) => $this->riga('sal', $r->id, $r->code, 'SAL '.$r->code,
            $r->period_to ?? $r->created_at, ucfirst((string) $r->status), $r->client_id, $r->client_name, [
                'href' => '/lavori?vista=sal', 'pdf' => '/api/v1/sals/'.$r->id.'/pdf',
            ]))->all();
    }

    /** Le esportazioni gia' fatte, dal registro delle operazioni. */
    private function esportazioni(string $tenantId): array
    {
        return collect(DB::select(<<<'SQL'
            SELECT al.id, al.action, al.payload, al.created_at, u.name AS utente
            FROM audit_logs al LEFT JOIN users u ON u.id = al.user_id
            WHERE al.tenant_id = ? AND al.action LIKE 'export.%'
            ORDER BY al.created_at DESC LIMIT 200
            SQL, [$tenantId]))->map(function ($r) {
            $p = json_decode((string) $r->payload, true) ?: [];
            $titolo = self::ESPORTAZIONI[$r->action] ?? 'Esportazione';
            $dettagli = array_filter([
                $p['layer'] ?? null, $p['format'] ?? null,
                isset($p['riferimento']) ? 'riferimento '.$p['riferimento'] : null,
            ]);

            return $this->riga('esportazione', $r->id, $p['riferimento'] ?? null,
                $titolo.($dettagli ? ' · '.implode(' · ', $dettagli) : ''), $r->created_at, 'Scaricata', null, null, [
                    'utente' => $r->utente,
                    'href' => str_starts_with($r->action, 'export.cam') ? '/patrimonio' : ($r->action === 'export.vta_registro' ? '/vta' : '/patrimonio'),
                ]);
        })->all();
    }

    private function riga(string $tipo, string $id, ?string $codice, string $titolo, $quando, string $stato, ?string $clientId, ?string $clientName, array $extra = []): array
    {
        $q = $quando ? Carbon::parse($quando)->setTimezone('Europe/Rome') : null;

        return [
            'tipo' => $tipo,
            'id' => $id,
            'codice' => $codice,
            'titolo' => $titolo,
            'committente' => $clientName,
            'client_id' => $clientId,
            'data' => $q?->toDateString(),
            'ora' => $q?->format('H:i:s'),
            'anno' => $q?->year,
            'stato' => $stato,
            'da_validare' => false,
            'impronta' => null,
            'utente' => null,
            'href' => null,
            'pdf' => null,
            ...$extra,
        ];
    }
}
