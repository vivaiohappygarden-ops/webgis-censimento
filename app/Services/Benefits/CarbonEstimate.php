<?php

namespace App\Services\Benefits;

use App\Models\Tree;

/**
 * Stima dell'anidride carbonica immagazzinata da un albero.
 *
 * Il metodo, i coefficienti e i loro riferimenti stanno in config/co2.php:
 * qui c'è solo il calcolo. Nessun numero è inventato dal programma, e la
 * pagina pubblica dichiara sempre quale modello è stato applicato.
 *
 * Serve il diametro del tronco. Se manca ma c'è la circonferenza, il
 * diametro si ricava da quella. Senza nessuna delle due la stima non si fa:
 * meglio nessun dato che un dato finto.
 */
class CarbonEstimate
{
    /**
     * @return array{
     *   diametro_cm: float, biomassa_kg: float, co2_kg: float,
     *   annuo_kg: float|null, gruppo: string, metodo: string,
     *   valore_euro: float|null, valore_annuo_euro: float|null,
     *   prezzo_tonnellata: float|null, prezzo_fonte: string|null
     * }|null
     */
    public static function per(?Tree $albero): ?array
    {
        if ($albero === null) {
            return null;
        }

        $diametro = self::diametro($albero);
        if ($diametro === null || $diametro <= 0 || $diametro > config('co2.diametro_massimo_cm', 250)) {
            return null;
        }

        $gruppo = self::gruppo($albero);
        $coefficienti = config("co2.gruppi.{$gruppo}");
        if (! is_array($coefficienti)) {
            return null;
        }

        // Biomassa epigea secca in chilogrammi
        $epigea = exp($coefficienti['b0'] + $coefficienti['b1'] * log($diametro))
            * (float) config('co2.correzione_isolati', 0.8);

        // Con le radici
        $biomassa = $epigea * (1 + (float) config('co2.rapporto_radici', 0.26));

        $carbonio = $biomassa * (float) config('co2.frazione_carbonio', 0.47);
        $co2 = $carbonio * (float) config('co2.co2_per_carbonio', 3.6667);

        // Media sulla vita della pianta: senza età non si dichiara nulla
        $eta = (int) ($albero->age_years_est ?? 0);
        $annuo = $eta > 0 ? $co2 / $eta : null;

        $prezzo = self::prezzoTonnellata();

        return [
            'diametro_cm' => round($diametro, 1),
            'biomassa_kg' => round($biomassa, 1),
            'co2_kg' => round($co2, 1),
            'annuo_kg' => $annuo !== null ? round($annuo, 1) : null,
            'gruppo' => $gruppo,
            'metodo' => (string) config('co2.modello'),
            // Il controvalore viaggia sempre col prezzo applicato e la sua
            // fonte: un euro senza il "come" non si pubblica
            'valore_euro' => $prezzo !== null ? round($co2 / 1000 * $prezzo, 2) : null,
            'valore_annuo_euro' => $prezzo !== null && $annuo !== null ? round($annuo / 1000 * $prezzo, 2) : null,
            'prezzo_tonnellata' => $prezzo,
            'prezzo_fonte' => $prezzo !== null ? (string) config('co2.prezzo_fonte') : null,
        ];
    }

    /** Prezzo di una tonnellata di CO2 in euro; null se spento (0 o vuoto). */
    public static function prezzoTonnellata(): ?float
    {
        $prezzo = (float) config('co2.euro_per_tonnellata', 0);

        return $prezzo > 0 ? $prezzo : null;
    }

    /** Diametro del tronco in centimetri, anche partendo dalla circonferenza. */
    private static function diametro(Tree $albero): ?float
    {
        if ($albero->dbh_cm !== null && (float) $albero->dbh_cm > 0) {
            return (float) $albero->dbh_cm;
        }

        if ($albero->trunk_circumference_cm !== null && (float) $albero->trunk_circumference_cm > 0) {
            return (float) $albero->trunk_circumference_cm / M_PI;
        }

        return null;
    }

    /** Conifera o latifoglia, dal genere botanico. */
    private static function gruppo(Tree $albero): string
    {
        $genere = mb_strtolower(trim((string) ($albero->genus ?: explode(' ', trim((string) $albero->species))[0] ?? '')));

        return in_array($genere, config('co2.conifere', []), true) ? 'conifere' : 'latifoglie';
    }

    /**
     * Somma dell'anidride carbonica immagazzinata da un elenco di alberi.
     * Gli alberi senza diametro non contano: il totale dice anche su quanti
     * alberi è stato calcolato, così il numero resta leggibile.
     *
     * @param  iterable<Tree>  $alberi
     * @return array{co2_kg: float, alberi: int, valore_euro: float|null}
     */
    public static function totale(iterable $alberi): array
    {
        $co2 = 0.0;
        $contati = 0;

        foreach ($alberi as $albero) {
            $stima = self::per($albero);
            if ($stima !== null) {
                $co2 += $stima['co2_kg'];
                $contati++;
            }
        }

        $prezzo = self::prezzoTonnellata();

        return [
            'co2_kg' => round($co2, 1),
            'alberi' => $contati,
            'valore_euro' => $prezzo !== null ? round($co2 / 1000 * $prezzo, 2) : null,
        ];
    }
}
