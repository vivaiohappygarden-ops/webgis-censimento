<?php

namespace App\Services\Portale;

use App\Models\Asset;
use App\Models\Client;
use App\Models\TreeAssessment;
use App\Models\WorkOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cronologia pubblica degli eventi di un elemento.
 *
 * Raccoglie in un unico elenco datato quello che il gestionale registra in
 * tabelle diverse: i lavori conclusi e le valutazioni di stabilità. Ogni
 * evento porta con sé gli "atti pubblici" collegati, cioè l'ordine di
 * servizio con cui il lavoro è stato disposto e la relazione tecnica del
 * professionista.
 *
 * Niente entra qui da solo: un lavoro o una perizia compaiono in pubblico
 * solo se contrassegnati come pubblicabili dal backoffice.
 */
class AssetTimeline
{
    private const TIPI_VALUTAZIONE = [
        'vta_visual' => 'Valutazione visiva di stabilità',
        'vta_instrumental' => 'Valutazione strumentale di stabilità',
        'vsa' => 'Valutazione speditiva',
        'pull_test' => 'Prova di trazione',
        'aerial_inspection' => 'Ispezione in quota',
        'other' => 'Valutazione',
    ];

    private const ESITI = [
        'ok' => 'Nessuna criticità rilevata',
        'monitor' => 'Albero da monitorare',
        'prescriptions' => 'Interventi prescritti',
        'fell' => 'Verifica approfondita in corso',
    ];

    /** Foto divulgative ammesse nella cronologia. */
    private const FOTO_EVENTO = ['before', 'during', 'after', 'census', 'reference'];

    /** @return array<int, array<string, mixed>> eventi dal più recente */
    public static function per(Client $client, Asset $asset): array
    {
        $eventi = [...self::lavori($client, $asset), ...self::valutazioni($client, $asset)];

        usort($eventi, fn ($a, $b) => ($b['data'] <=> $a['data']) ?: ($b['titolo'] <=> $a['titolo']));

        return $eventi;
    }

    /** Lavori conclusi e pubblicabili che hanno toccato l'elemento. */
    private static function lavori(Client $client, Asset $asset): array
    {
        $righe = DB::select(<<<'SQL'
            SELECT wo.id, wo.code, wo.title, wo.description, wo.completed_at, wo.planned_end,
                   woa.notes AS nota_riga, wt.name AS lavorazione
            FROM work_order_assets woa
            JOIN work_orders wo ON wo.id = woa.work_order_id
             AND wo.deleted_at IS NULL AND wo.is_public AND wo.status = 'completed'
            LEFT JOIN work_types wt ON wt.id = coalesce(woa.work_type_id, wo.work_type_id)
            WHERE woa.asset_id = ? AND wo.tenant_id = ?
            ORDER BY coalesce(wo.completed_at::date, wo.planned_end) DESC
            SQL, [$asset->id, $client->tenant_id]);

        return array_map(function ($r) use ($client, $asset) {
            $data = $r->completed_at ? Carbon::parse($r->completed_at) : Carbon::parse($r->planned_end);

            return [
                'data' => $data,
                'titolo' => mb_strtoupper($r->lavorazione ?: $r->title),
                'nota' => trim((string) ($r->nota_riga ?: $r->description)),
                'foto' => self::foto($asset, WorkOrder::class, $r->id),
                'atti' => [[
                    'tipo' => 'Ordine di servizio',
                    'numero' => $r->code,
                    'ente' => $client->publicName(),
                    'data' => $data,
                    'descrizione' => $r->title,
                    'url' => null,
                ]],
            ];
        }, $righe);
    }

    /** Valutazioni di stabilità pubblicabili. */
    private static function valutazioni(Client $client, Asset $asset): array
    {
        if ($asset->tree === null && ! $asset->relationLoaded('tree')) {
            $asset->load(['tree' => fn ($q) => $q->withoutGlobalScopes()]);
        }

        $righe = TreeAssessment::query()->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('tenant_id', $client->tenant_id)
            ->where('tree_id', $asset->id)
            ->where('is_public', true)
            ->orderByDesc('assessed_on')
            ->get(['id', 'assessed_on', 'assessment_type', 'outcome', 'prescriptions', 'report_number', 'next_check_due']);

        return $righe->map(function (TreeAssessment $v) use ($client, $asset) {
            $atti = [];
            if ($v->report_number !== null) {
                $atti[] = [
                    'tipo' => 'Relazione tecnica',
                    'numero' => $v->report_number,
                    'ente' => $client->publicName(),
                    'data' => $v->assessed_on,
                    'descrizione' => 'Relazione tecnica redatta da professionista abilitato',
                    'url' => null,
                ];
            }

            $nota = trim((string) ($v->prescriptions ?: (self::ESITI[$v->outcome] ?? '')));
            if ($v->next_check_due !== null) {
                $nota = trim($nota."\nProssima verifica prevista: ".$v->next_check_due->format('m/Y'));
            }

            return [
                'data' => $v->assessed_on,
                'titolo' => mb_strtoupper(self::TIPI_VALUTAZIONE[$v->assessment_type] ?? 'Valutazione'),
                'nota' => $nota,
                'foto' => self::foto($asset, TreeAssessment::class, $v->id),
                'atti' => $atti,
            ];
        })->all();
    }

    /** Foto collegate esplicitamente all'evento, mai quelle dei difetti. */
    private static function foto(Asset $asset, string $tipo, string $id): array
    {
        return DB::table('photos')
            ->whereNull('deleted_at')
            ->where('asset_id', $asset->id)
            ->where('subject_type', $tipo)
            ->where('subject_id', $id)
            ->whereIn('category', self::FOTO_EVENTO)
            ->orderBy('created_at')
            ->limit(6)
            ->pluck('id')
            ->all();
    }
}
