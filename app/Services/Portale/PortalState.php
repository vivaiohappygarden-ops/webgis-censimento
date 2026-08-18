<?php

namespace App\Services\Portale;

/**
 * Stato dell'elemento come lo vede il cittadino.
 *
 * Quattro voci decise dal committente (18/08/2026): sano, in cura, da
 * potare, in verifica. "In verifica" copre gli alberi per cui la
 * valutazione di stabilità propone l'abbattimento: in pubblico non si
 * scrive "da abbattere" prima che l'ente abbia deciso. Gli alberi già
 * abbattuti non compaiono affatto sul portale (li esclude PortalQuery).
 *
 * La regola è scritta una volta sola, in SQL, perché serve identica alla
 * scheda, alla mappa e ai riquadri della cartografia.
 */
class PortalState
{
    public const ETICHETTE = [
        'sano' => 'Sano',
        'cura' => 'In cura',
        'potare' => 'Da potare',
        'verifica' => 'In verifica',
    ];

    public const COLORI = [
        'sano' => '#15803d',
        'cura' => '#0369a1',
        'potare' => '#b45309',
        'verifica' => '#b91c1c',
    ];

    /** Termini che identificano una potatura fra i tipi di lavorazione. */
    private const POTATURA = 'potatur|rimonda|capitozz|spalcatur';

    /** Stati di un ordine di lavoro che significano "lavoro ancora aperto". */
    private const APERTI = "('planned','assigned','in_progress','suspended')";

    /**
     * Espressione SQL che calcola lo stato pubblico. L'alias dell'elemento è
     * parametrico perché la query dei riquadri cartografici usa un altro nome.
     */
    public static function sql(string $asset = 'assets'): string
    {
        $ultimaValutazione = fn (string $campo) => "(
            SELECT ta.{$campo} FROM tree_assessments ta
            WHERE ta.tree_id = {$asset}.id AND ta.deleted_at IS NULL
            ORDER BY ta.assessed_on DESC, ta.created_at DESC LIMIT 1)";

        $lavoriAperti = fn (?string $tipo) => "(
            SELECT count(*) FROM work_order_assets woa
            JOIN work_orders wo ON wo.id = woa.work_order_id
              AND wo.deleted_at IS NULL AND wo.status IN ".self::APERTI."
            LEFT JOIN work_types wt ON wt.id = coalesce(woa.work_type_id, wo.work_type_id)
            WHERE woa.asset_id = {$asset}.id AND woa.status <> 'done'"
            .($tipo !== null ? " AND coalesce(wt.name, '') || ' ' || coalesce(wt.code, '') ~* '{$tipo}'" : '')
            .')';

        return "CASE
            WHEN {$ultimaValutazione('outcome')} = 'fell'
              OR {$ultimaValutazione('failure_class')} IN ('D', 'C/D') THEN 'verifica'
            WHEN {$lavoriAperti(self::POTATURA)} > 0
              OR {$ultimaValutazione('outcome')} = 'prescriptions' THEN 'potare'
            WHEN {$lavoriAperti(null)} > 0
              OR {$ultimaValutazione('outcome')} = 'monitor' THEN 'cura'
            ELSE 'sano'
        END";
    }

    public static function etichetta(?string $stato): string
    {
        return self::ETICHETTE[$stato] ?? self::ETICHETTE['sano'];
    }

    public static function colore(?string $stato): string
    {
        return self::COLORI[$stato] ?? self::COLORI['sano'];
    }
}
