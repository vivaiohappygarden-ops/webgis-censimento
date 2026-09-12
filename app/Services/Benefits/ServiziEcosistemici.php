<?php

namespace App\Services\Benefits;

use App\Models\Tree;

/**
 * I benefici ambientali di un albero oltre all'anidride carbonica:
 * ossigeno liberato, polveri sottili trattenute, pioggia intercettata.
 *
 * Il metodo, i coefficienti e i loro riferimenti stanno in config/benefici.php
 * (l'anidride carbonica ha il suo, config/co2.php): qui c'è solo il calcolo.
 * Come per la CO2, nessun numero è inventato dal programma e ogni pagina
 * dichiara il modello applicato.
 *
 * Le voci si compongono qui una volta sola - etichetta, valore, unità,
 * eventuale controvalore - perché le stesse righe escono nella scheda del
 * gestionale, nel portale pubblico e nella relazione annuale: tre posti che
 * devono dire le stesse identiche cose.
 *
 * Che cosa serve: l'ossigeno vive sull'assorbimento annuo di CO2 (e quindi
 * sull'età), polveri e pioggia sull'area di chioma. Quel che manca non si
 * stima e non compare.
 */
class ServiziEcosistemici
{
    /**
     * @return array{
     *   chioma_m2: float|null, voci: list<array>, metodo: string
     * }|null  null quando non c'è niente da dire
     */
    public static function per(?Tree $albero): ?array
    {
        if ($albero === null) {
            return null;
        }

        $voci = [];

        $co2 = CarbonEstimate::per($albero);
        $annuo = $co2['annuo_kg'] ?? null;
        if ($annuo !== null) {
            $voci[] = self::voce(
                'ossigeno',
                'Ossigeno liberato',
                round($annuo * (float) config('benefici.ossigeno_per_co2', 0.7273), 1),
                'kg/anno',
            );
        }

        $chioma = self::areaChioma($albero);
        if ($chioma !== null) {
            $pm10 = $chioma * (float) config('benefici.pm10_g_per_m2_anno', 0);
            $pm25 = $chioma * (float) config('benefici.pm25_g_per_m2_anno', 0);
            $litri = $chioma
                * (float) config('benefici.pioggia_mm_anno', 0)
                * (float) config('benefici.frazione_intercettazione', 0);

            if ($pm10 > 0) {
                $voci[] = self::voce('pm10', 'Polveri PM10 trattenute', round($pm10, 1), 'g/anno',
                    self::euro($pm10 / 1000, 'benefici.euro_per_kg_pm10', 'benefici.fonte_prezzo_polveri'));
            }
            if ($pm25 > 0) {
                $voci[] = self::voce('pm25', 'Polveri fini PM2,5 trattenute', round($pm25, 2), 'g/anno',
                    self::euro($pm25 / 1000, 'benefici.euro_per_kg_pm25', 'benefici.fonte_prezzo_polveri'));
            }
            if ($litri > 0) {
                $voci[] = self::voce('pioggia', 'Pioggia intercettata dalla chioma', round($litri), 'litri/anno',
                    self::euro($litri / 1000, 'benefici.euro_per_m3_pioggia', 'benefici.fonte_prezzo_pioggia'));
            }
        }

        if ($voci === []) {
            return null;
        }

        return [
            'chioma_m2' => $chioma !== null ? round($chioma, 1) : null,
            'voci' => $voci,
            'metodo' => (string) config('benefici.modello'),
        ];
    }

    /**
     * Le stesse voci sommate su un elenco di alberi, con quanti alberi hanno
     * davvero contribuito a ciascuna: un totale senza il suo denominatore non
     * si può leggere (l'ossigeno lo sanno solo gli alberi con l'età, le
     * polveri solo quelli con la chioma censita).
     *
     * @param  iterable<Tree>  $alberi
     * @return array{voci: list<array>, metodo: string}|null
     */
    public static function totale(iterable $alberi): ?array
    {
        $somme = [];

        foreach ($alberi as $albero) {
            $stima = self::per($albero);
            if ($stima === null) {
                continue;
            }

            foreach ($stima['voci'] as $voce) {
                $chiave = $voce['chiave'];
                $somme[$chiave] ??= [...$voce, 'valore' => 0.0, 'euro' => null, 'alberi' => 0];
                $somme[$chiave]['valore'] += $voce['valore'];
                $somme[$chiave]['alberi']++;
                if ($voce['euro'] !== null) {
                    $somme[$chiave]['euro'] = round(($somme[$chiave]['euro'] ?? 0) + $voce['euro'], 2);
                }
            }
        }

        if ($somme === []) {
            return null;
        }

        // I litri d'anno di un patrimonio intero non si leggono: sopra il
        // metro cubo si passa ai metri cubi, con lo stesso numero dietro
        $voci = array_map(function (array $voce) {
            if ($voce['chiave'] === 'pioggia' && $voce['valore'] >= 1000) {
                $voce['valore'] = round($voce['valore'] / 1000, 1);
                $voce['unita'] = 'm³/anno';
            } elseif (in_array($voce['chiave'], ['pm10', 'pm25'], true) && $voce['valore'] >= 1000) {
                $voce['valore'] = round($voce['valore'] / 1000, 1);
                $voce['unita'] = 'kg/anno';
            } else {
                $voce['valore'] = round($voce['valore'], 1);
            }

            return $voce;
        }, array_values($somme));

        return ['voci' => $voci, 'metodo' => (string) config('benefici.modello')];
    }

    /**
     * Area di chioma proiettata a terra, in metri quadrati. Una chioma
     * assurda (diametro fuori scala: quasi sempre un errore di digitazione)
     * non produce stime: moltiplicata per l'area diventerebbe un numero
     * grottesco in prima pagina.
     */
    private static function areaChioma(Tree $albero): ?float
    {
        $diametro = (float) ($albero->crown_diameter_m ?? 0);
        if ($diametro <= 0 || $diametro > (float) config('benefici.chioma_massima_m', 40)) {
            return null;
        }

        return M_PI * ($diametro / 2) ** 2;
    }

    /** @return array{chiave: string, etichetta: string, valore: float, unita: string, euro: float|null, prezzo: float|null, prezzo_fonte: string|null} */
    private static function voce(string $chiave, string $etichetta, float $valore, string $unita, ?array $euro = null): array
    {
        return [
            'chiave' => $chiave,
            'etichetta' => $etichetta,
            'valore' => $valore,
            'unita' => $unita,
            'euro' => $euro['euro'] ?? null,
            'prezzo' => $euro['prezzo'] ?? null,
            'prezzo_fonte' => $euro['fonte'] ?? null,
        ];
    }

    /**
     * Controvalore di una quantità, con il prezzo applicato e la sua fonte.
     * Prezzo a zero o fonte non dichiarata: niente euro (stessa regola del
     * prezzo della CO2, un valore economico senza il "come" non si pubblica).
     *
     * @return array{euro: float, prezzo: float, fonte: string}|null
     */
    private static function euro(float $quantita, string $chiavePrezzo, string $chiaveFonte): ?array
    {
        $prezzo = (float) config($chiavePrezzo, 0);
        $fonte = trim((string) config($chiaveFonte, ''));

        if ($prezzo <= 0 || $fonte === '') {
            return null;
        }

        return ['euro' => round($quantita * $prezzo, 2), 'prezzo' => $prezzo, 'fonte' => $fonte];
    }
}
