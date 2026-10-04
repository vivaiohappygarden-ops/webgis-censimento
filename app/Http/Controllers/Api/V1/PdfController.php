<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\ChecklistItem;
use App\Models\Inspection;
use App\Models\Issue;
use App\Models\LandConstraint;
use App\Models\NonConformity;
use App\Models\Organization;
use App\Models\WorkOrder;
use App\Models\WorkOrderAsset;
use App\Services\Assets\CronologiaElemento;
use App\Services\Benefits\CarbonEstimate;
use App\Services\Benefits\ServiziEcosistemici;
use App\Services\Pdf\LuogoFirma;
use App\Services\Pdf\PdfRenderer;
use App\Services\Pdf\PlanimetriaElemento;
use App\Services\Pdf\SezioniStampa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

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
            // Il verbale e' dell'ispezione, non del giorno in cui lo si
            // ristampa: nello spazio della firma va la data dell'ispezione,
            // la stessa che compare nel corpo e nel nome del file
            'luogoData' => LuogoFirma::riga(
                $request->user()->tenant_id, $inspection->completed_at ?? now(),
            ),
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

    /** Le sezioni della scheda stampata, nell'ordine del documento (le stesse delle pagine). */
    public const SEZIONI_SCHEDA = ['posizione', 'dendro', 'vta', 'lavori', 'attributi', 'benefici', 'cronologia', 'foto'];

    /**
     * La scheda dell'elemento: dal 04/10/2026 con posizione e planimetria,
     * tutte le valutazioni, lavori e segnalazioni, benefici stimati e
     * cronologia (punto 9 dell'elenco del committente: "manca la mappa, la
     * cronologia e altri dati"). Ogni sezione si puo' togliere con ?sezioni=.
     */
    public function asset(Request $request, PdfRenderer $renderer, string $id, PlanimetriaElemento $planimetria, CronologiaElemento $cronologia)
    {
        $asset = Asset::query()
            ->with([
                'objectType.subType.mainType',
                'area.locality.site.client',
                'tree',
                'plantingSite',
            ])
            ->findOrFail($id);

        // Stampa componibile: senza parametro escono tutte le sezioni
        $sezioni = SezioniStampa::da($request, self::SEZIONI_SCHEDA);
        $organizzazione = Organization::find($request->user()->tenant_id);
        $srid = (int) ($organizzazione?->metric_srid ?: 7791);

        $fields = \App\Models\CustomField::query()
            ->where('object_type_id', $asset->object_type_id)
            ->get()->keyBy('key');

        // Tutte le valutazioni, dalla piu' recente: la scheda racconta la storia della stabilita', non solo l'ultima riga
        $valutazioni = in_array('vta', $sezioni, true) && $asset->tree
            ? $asset->tree->assessments()->with('assessor:id,name')->orderByDesc('assessed_on')->orderByDesc('created_at')->get()
            : collect();

        // Tutte le fotografie dell'elemento, non solo quella di riferimento:
        // una scheda con una foto su dieci racconterebbe un decimo del vero
        $fotografie = in_array('foto', $sezioni, true)
            ? \App\Services\Photos\FotoStampa::perScheda($asset->id)
            : ['foto' => [], 'nota' => null];

        $lavori = ['ordini' => collect(), 'righe' => collect(), 'segnalazioni' => collect()];
        if (in_array('lavori', $sezioni, true)) {
            $righe = WorkOrderAsset::query()->where('asset_id', $asset->id)->with('workType:id,name')->get()->keyBy('work_order_id');
            $lavori = [
                'ordini' => WorkOrder::query()->whereIn('id', $righe->keys()->all())->with(['team:id,name', 'workType:id,name'])
                    ->orderByRaw('planned_start DESC NULLS LAST')->orderByDesc('created_at')->get(),
                'righe' => $righe,
                'segnalazioni' => Issue::query()->where('asset_id', $asset->id)->with('workOrder:id,code,status')->orderByDesc('created_at')->get(),
            ];
        }

        $pdf = $renderer->render('pdf.asset', [
            'organization' => $organizzazione,
            'asset' => $asset,
            // Con la richiesta POST il browser manda l'inquadratura della mappa a video (immagine,
            // confini, attribuzione): la planimetria si disegna sopra le strade
            'posizione' => in_array('posizione', $sezioni, true) ? $this->posizione($asset, $srid, $planimetria, is_array($request->input('sfondo')) ? $request->input('sfondo') : null) : null,
            'foto' => $fotografie['foto'],
            'fotoNota' => $fotografie['nota'],
            'fields' => $fields,
            'valutazioni' => $valutazioni,
            'assessment' => $valutazioni->first(),
            'lavori' => $lavori,
            'benefici' => in_array('benefici', $sezioni, true) && $asset->tree
                ? ['servizi' => ServiziEcosistemici::per($asset->tree), 'co2' => CarbonEstimate::per($asset->tree)]
                : null,
            'cronologia' => in_array('cronologia', $sezioni, true) ? $cronologia->per($asset) : null,
            'sezioni' => $sezioni,
            // Un solo orologio per tutto il documento
            'stampatoIl' => now('Europe/Rome'),
        ]);

        $name = preg_replace('/[^A-Za-z0-9_-]/', '_', $asset->census_code ?? substr($asset->id, 0, 8));

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"scheda_{$name}.pdf\"",
        ]);
    }

    /**
     * Dove sta l'elemento: coordinate geografiche e piane del centro, tipo di
     * geometria con le misure calcolate, vincoli, planimetria schematica.
     *
     * @return array<string, mixed>
     */
    private function posizione(Asset $asset, int $srid, PlanimetriaElemento $planimetria, ?array $sfondo = null): array
    {
        $centro = DB::table('assets')->where('id', $asset->id)->whereNotNull('geom')
            ->selectRaw('ST_X(ST_Centroid(geom)) AS lon, ST_Y(ST_Centroid(geom)) AS lat, ST_X(ST_Transform(ST_Centroid(geom), ?::int)) AS est, ST_Y(ST_Transform(ST_Centroid(geom), ?::int)) AS nord, GeometryType(geom) AS tipo', [$srid, $srid])
            ->first();

        return [
            'tipo' => $centro?->tipo,
            'lon' => $centro?->lon !== null ? (float) $centro->lon : null,
            'lat' => $centro?->lat !== null ? (float) $centro->lat : null,
            'est' => $centro?->est !== null ? (float) $centro->est : null,
            'nord' => $centro?->nord !== null ? (float) $centro->nord : null,
            'srid' => $srid,
            'planimetria' => $planimetria->per($asset, $srid, $sfondo),
            'vincoli' => LandConstraint::query()->whereHas('assets', fn ($q) => $q->where('assets.id', $asset->id))->orderBy('code')->get(),
        ];
    }
}
