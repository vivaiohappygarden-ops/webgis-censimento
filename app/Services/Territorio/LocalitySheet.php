<?php

namespace App\Services\Territorio;

use App\Models\Document;
use App\Models\Locality;
use Illuminate\Support\Facades\DB;

/**
 * Scheda di una località: quello che c'è dentro, quanto è grande, chi ci
 * lavora e con quali documenti.
 *
 * Le due superfici sono definite e dichiarate, perché "superficie" da sola
 * non vuol dire niente e su un capitolato a misura si litiga:
 *  - TOTALE: il terreno che la località occupa, dal suo perimetro; se il
 *    perimetro non è stato disegnato si somma quello delle aree interne.
 *  - GESTITA: la somma delle aree effettivamente in gestione (attive). È
 *    quella che conta per i lavori e per il corrispettivo.
 */
class LocalitySheet
{
    public static function per(Locality $localita): array
    {
        return [
            'localita' => [
                'id' => $localita->id,
                'code' => $localita->code,
                'name' => $localita->name,
                'survey_zone_code' => $localita->survey_zone_code,
                'istat_class' => $localita->istat_class,
                'istat_class_label' => $localita->istat_class
                    ? (config('istat.verde_urbano')[$localita->istat_class] ?? $localita->istat_class)
                    : null,
                'sede' => $localita->site?->only(['id', 'name']),
                'committente' => $localita->site?->client?->only(['id', 'name']),
            ],
            'superfici' => self::superfici($localita),
            'per_tipo' => self::perTipo($localita),
            'piante' => self::piante($localita),
            'imprese' => self::imprese($localita),
            'lavori' => self::lavori($localita),
            'documenti' => self::documenti($localita),
        ];
    }

    private static function superfici(Locality $localita): array
    {
        $aree = DB::selectOne(<<<'SQL'
            SELECT
              COUNT(*)                                                              AS quante,
              COUNT(*) FILTER (WHERE status = 'active')                             AS attive,
              COALESCE(SUM(computed_area_sqm), 0)                                   AS totale_aree,
              COALESCE(SUM(computed_area_sqm) FILTER (WHERE status = 'active'), 0)  AS gestita
            FROM areas
            WHERE locality_id = ? AND deleted_at IS NULL
            SQL, [$localita->id]);

        // Perimetro della località, quando è stato disegnato: è la misura più
        // corretta del terreno occupato, perché le aree interne possono
        // lasciare vuoti o sovrapporsi
        $daPerimetro = DB::selectOne(
            'SELECT ST_Area(geom::geography) AS mq FROM localities WHERE id = ? AND geom IS NOT NULL',
            [$localita->id],
        );

        return [
            'totale_mq' => $daPerimetro?->mq !== null
                ? round((float) $daPerimetro->mq, 2)
                : round((float) $aree->totale_aree, 2),
            'totale_da_perimetro' => $daPerimetro?->mq !== null,
            'gestita_mq' => round((float) $aree->gestita, 2),
            'aree' => (int) $aree->quante,
            'aree_attive' => (int) $aree->attive,
        ];
    }

    /**
     * Quanti elementi censiti, per tipo di oggetto del catalogo. Fuori
     * l'archivio (abbattuti e dismessi): questi numeri finiscono anche nella
     * stampa PDF della località, e su un capitolato a misura si litiga —
     * prima il conteggio prendeva perfino gli abbattuti, in contrasto con la
     * planimetria della stessa scheda.
     */
    private static function perTipo(Locality $localita): array
    {
        $fuoriArchivio = \App\Support\AssetStatus::sqlArchivio();

        return DB::select(<<<SQL
            SELECT t.code, t.name, COUNT(*) AS quanti
            FROM assets a
            JOIN areas ar ON ar.id = a.area_id AND ar.deleted_at IS NULL
            JOIN catalog_object_types t ON t.id = a.object_type_id
            WHERE ar.locality_id = ? AND a.deleted_at IS NULL
              AND a.status NOT IN ({$fuoriArchivio})
            GROUP BY t.code, t.name
            ORDER BY COUNT(*) DESC, t.name
            SQL, [$localita->id]);
    }

    /**
     * Le piante presenti con nome scientifico, nome comune e quantità: è
     * l'elenco che un ufficio tecnico chiede per primo.
     */
    private static function piante(Locality $localita): array
    {
        // Anche qui fuori l'archivio su assets.status: un dismesso ha
        // removed_on vuota e col solo filtro sulle date conterebbe ancora
        $fuoriArchivio = \App\Support\AssetStatus::sqlArchivio();

        return DB::select(<<<SQL
            SELECT
              COALESCE(NULLIF(TRIM(tr.species), ''), NULLIF(TRIM(tr.genus), ''), 'Specie non indicata') AS scientifico,
              NULLIF(TRIM(MIN(tr.common_name)), '') AS comune,
              COUNT(*) AS quanti
            FROM trees tr
            JOIN assets a ON a.id = tr.asset_id AND a.deleted_at IS NULL
                         AND a.status NOT IN ({$fuoriArchivio})
            JOIN areas ar ON ar.id = a.area_id AND ar.deleted_at IS NULL
            WHERE ar.locality_id = ? AND tr.removed_on IS NULL
            GROUP BY 1
            ORDER BY COUNT(*) DESC, 1
            SQL, [$localita->id]);
    }

    /** Chi lavora qui: squadre e responsabili degli ordini non annullati. */
    private static function imprese(Locality $localita): array
    {
        return DB::select(<<<'SQL'
            SELECT COALESCE(te.name, us.name, 'Non assegnato') AS nome,
                   COUNT(DISTINCT wo.id) AS lavori
            FROM work_orders wo
            JOIN areas ar ON ar.id = wo.area_id AND ar.deleted_at IS NULL
            LEFT JOIN teams te ON te.id = wo.team_id
            LEFT JOIN users us ON us.id = wo.assigned_to
            WHERE ar.locality_id = ? AND wo.deleted_at IS NULL AND wo.status <> 'cancelled'
            GROUP BY 1
            ORDER BY COUNT(DISTINCT wo.id) DESC, 1
            SQL, [$localita->id]);
    }

    private static function lavori(Locality $localita): array
    {
        return DB::select(<<<'SQL'
            SELECT wo.id, wo.code, wo.title, wo.status, wo.planned_start, wo.planned_end
            FROM work_orders wo
            JOIN areas ar ON ar.id = wo.area_id AND ar.deleted_at IS NULL
            WHERE ar.locality_id = ? AND wo.deleted_at IS NULL
            ORDER BY COALESCE(wo.planned_start, wo.created_at::date) DESC
            LIMIT 20
            SQL, [$localita->id]);
    }

    private static function documenti(Locality $localita): array
    {
        return Document::query()
            ->where('subject_type', $localita->getMorphClass())
            ->where('subject_id', $localita->id)
            ->orderByDesc('created_at')
            ->get(['id', 'title', 'doc_type', 'mime_type', 'size_bytes', 'created_at'])
            ->all();
    }
}
