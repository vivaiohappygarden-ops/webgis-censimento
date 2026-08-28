<?php

namespace App\Services\Works;

use App\Models\Sal;

/**
 * I conti di un SAL, scritti una volta sola: pagina, stampa e test leggono
 * gli stessi numeri da qui.
 *
 * L'IVA e' per riga (aliquote anche diverse nello stesso documento). Le
 * spese generali percentuali, se il SAL le porta, si ripartiscono sulle
 * aliquote IN PROPORZIONE agli imponibili delle righe: cosi' ogni aliquota
 * tassa la propria quota e il totale resta difendibile davanti a un
 * committente. L'eventuale resto di arrotondamento finisce sull'aliquota
 * con l'imponibile piu' alto.
 */
class SalTotali
{
    /**
     * @return array{
     *   imponibile_righe: float, spese_generali: float, imponibile: float,
     *   per_aliquota: list<array{aliquota: float, imponibile: float, iva: float}>,
     *   iva: float, totale: float
     * }
     */
    public static function per(Sal $sal): array
    {
        $righe = $sal->items;
        $imponibileRighe = round((float) $righe->sum(fn ($r) => (float) $r->imponibile), 2);

        $pct = $sal->overhead_pct !== null ? (float) $sal->overhead_pct : 0.0;
        $speseGenerali = $pct > 0 ? round($imponibileRighe * $pct / 100, 2) : 0.0;

        // Imponibile per aliquota, con le spese generali ripartite in proporzione
        $perAliquota = [];
        $gruppi = $righe->groupBy(fn ($r) => number_format((float) $r->vat_rate, 2, '.', ''));
        $assegnate = 0.0;
        $chiaveMassima = null;
        $massimo = -1.0;
        foreach ($gruppi as $chiave => $gruppo) {
            $base = round((float) $gruppo->sum(fn ($r) => (float) $r->imponibile), 2);
            $quotaSpese = $speseGenerali > 0 && $imponibileRighe > 0
                ? round($speseGenerali * $base / $imponibileRighe, 2)
                : 0.0;
            $assegnate = round($assegnate + $quotaSpese, 2);
            if ($base > $massimo) {
                $massimo = $base;
                $chiaveMassima = $chiave;
            }
            $perAliquota[$chiave] = ['aliquota' => (float) $chiave, 'imponibile' => round($base + $quotaSpese, 2)];
        }
        // Il resto di arrotondamento della ripartizione va sulla voce maggiore
        $resto = round($speseGenerali - $assegnate, 2);
        if ($resto !== 0.0 && $chiaveMassima !== null) {
            $perAliquota[$chiaveMassima]['imponibile'] = round($perAliquota[$chiaveMassima]['imponibile'] + $resto, 2);
        }

        $iva = 0.0;
        $voci = [];
        foreach ($perAliquota as $voce) {
            $voce['iva'] = round($voce['imponibile'] * $voce['aliquota'] / 100, 2);
            $iva = round($iva + $voce['iva'], 2);
            $voci[] = $voce;
        }
        usort($voci, fn ($a, $b) => $a['aliquota'] <=> $b['aliquota']);

        $imponibile = round($imponibileRighe + $speseGenerali, 2);

        return [
            'imponibile_righe' => $imponibileRighe,
            'spese_generali' => $speseGenerali,
            'imponibile' => $imponibile,
            'per_aliquota' => $voci,
            'iva' => $iva,
            'totale' => round($imponibile + $iva, 2),
        ];
    }
}
