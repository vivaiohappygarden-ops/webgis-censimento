<?php

namespace App\Services\Trees;

use App\Models\Client;
use App\Models\PlantingSite;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bilancio arboreo fra due date (legge 10/2013).
 *
 * Un albero "esiste" dalla data di messa a dimora (planted_on) o, se non
 * nota, dalla data di censimento; cessa alla data di abbattimento
 * (removed_on).
 *
 * Il calcolo sta qui e non nel controllo perché serve identico a due
 * destinazioni: la pagina VTA e il documento da consegnare all'ente. Due
 * conteggi separati, prima o poi, divergono - e a divergere sarebbe un
 * numero che il sindaco pubblica.
 *
 * Il committente si può restringere: il bilancio di fine mandato riguarda
 * un Comune, non tutto il lavoro dell'impresa.
 */
class TreeBalance
{
    public static function per(string $tenantId, string $from, string $to, ?string $clientId = null): array
    {
        // Il validatore 'date' accetta formati che PostgreSQL non digerisce:
        // si normalizza a AAAA-MM-GG prima del cast ?::date
        $from = Carbon::parse($from)->toDateString();
        $to = Carbon::parse($to)->toDateString();

        // Il committente si raggiunge dall'elemento risalendo la catena
        // area -> località -> sede -> committente
        $join = $clientId === null ? '' : <<<'SQL'
              JOIN areas ar ON ar.id = a.area_id
              JOIN localities lo ON lo.id = ar.locality_id
              JOIN sites si ON si.id = lo.site_id
            SQL;
        $dove = $clientId === null ? '' : ' AND si.client_id = ?';
        $primi = $clientId === null ? [$tenantId] : [$tenantId, $clientId];

        $totali = DB::selectOne(<<<SQL
            WITH t AS (
              SELECT COALESCE(tr.planted_on, a.created_at::date) AS starts_on,
                     tr.removed_on
              FROM trees tr
              JOIN assets a ON a.id = tr.asset_id
              {$join}
              WHERE tr.tenant_id = ? AND a.deleted_at IS NULL{$dove}
            )
            SELECT
              COUNT(*) FILTER (WHERE starts_on < ?::date
                                 AND (removed_on IS NULL OR removed_on >= ?::date)) AS initial_count,
              COUNT(*) FILTER (WHERE starts_on BETWEEN ?::date AND ?::date)          AS planted_count,
              COUNT(*) FILTER (WHERE removed_on BETWEEN ?::date AND ?::date)         AS felled_count,
              COUNT(*) FILTER (WHERE starts_on <= ?::date
                                 AND (removed_on IS NULL OR removed_on > ?::date))   AS final_count
            FROM t
            SQL, [...$primi, $from, $from, $from, $to, $from, $to, $to, $to]);

        $perSpecie = DB::select(<<<SQL
            WITH t AS (
              SELECT COALESCE(tr.planted_on, a.created_at::date) AS starts_on,
                     tr.removed_on,
                     -- species porta il binomio completo (convenzione CAM); genus è il ripiego
                     COALESCE(NULLIF(TRIM(tr.species), ''), NULLIF(TRIM(tr.genus), ''), 'Specie non indicata') AS species_label
              FROM trees tr
              JOIN assets a ON a.id = tr.asset_id
              {$join}
              WHERE tr.tenant_id = ? AND a.deleted_at IS NULL{$dove}
            )
            SELECT species_label,
                   COUNT(*) FILTER (WHERE starts_on BETWEEN ?::date AND ?::date)  AS planted,
                   COUNT(*) FILTER (WHERE removed_on BETWEEN ?::date AND ?::date) AS felled
            FROM t
            GROUP BY species_label
            HAVING COUNT(*) FILTER (WHERE starts_on BETWEEN ?::date AND ?::date)
                 + COUNT(*) FILTER (WHERE removed_on BETWEEN ?::date AND ?::date) > 0
            ORDER BY COUNT(*) FILTER (WHERE starts_on BETWEEN ?::date AND ?::date)
                   + COUNT(*) FILTER (WHERE removed_on BETWEEN ?::date AND ?::date) DESC,
                     species_label
            LIMIT 50
            SQL, [...$primi, $from, $to, $from, $to, $from, $to, $from, $to, $from, $to, $from, $to]);

        // Stato attuale dei posti liberi: è una fotografia di oggi, non
        // storicizzata per data, e va letta come tale
        $posti = self::postiLiberi($clientId);

        return [
            'from' => $from,
            'to' => $to,
            'client' => $clientId === null ? null
                : Client::query()->whereKey($clientId)->value('name'),
            'initial_count' => (int) $totali->initial_count,
            'planted_count' => (int) $totali->planted_count,
            'felled_count' => (int) $totali->felled_count,
            'final_count' => (int) $totali->final_count,
            'variation' => (int) $totali->final_count - (int) $totali->initial_count,
            'by_species' => $perSpecie,
            'planting_sites' => $posti,
        ];
    }

    private static function postiLiberi(?string $clientId): array
    {
        $base = fn () => PlantingSite::query()
            ->join('assets', 'assets.id', '=', 'planting_sites.asset_id')
            ->whereNull('assets.deleted_at')
            ->when($clientId !== null, fn ($q) => $q
                ->join('areas', 'areas.id', '=', 'assets.area_id')
                ->join('localities', 'localities.id', '=', 'areas.locality_id')
                ->join('sites', 'sites.id', '=', 'localities.site_id')
                ->where('sites.client_id', $clientId));

        $conteggi = $base()
            ->selectRaw('planting_sites.status, COUNT(*) AS n')
            ->groupBy('planting_sites.status')
            ->pluck('n', 'status');

        return [
            'free' => (int) ($conteggi['free'] ?? 0),
            'reserved' => (int) ($conteggi['reserved'] ?? 0),
            'planted' => (int) ($conteggi['planted'] ?? 0),
            'unusable' => (int) ($conteggi['unusable'] ?? 0),
            'replacements_from_felling' => $base()
                ->where('planting_sites.origin', 'felling')
                ->where('planting_sites.status', 'planted')
                ->count(),
        ];
    }
}
