<?php

namespace App\Services\Pdf;

use App\Models\Locality;
use App\Services\Territorio\PlanimetriaDati;

/**
 * Le planimetrie pronte per la scheda della località in PDF: quadro
 * d'insieme (dalle due aree in su) e una planimetria zoomata per area,
 * con didascalia e conteggi.
 */
class Planimetria
{
    public function __construct(private SfondoCartografico $sfondo) {}

    /**
     * @return array{
     *   sfondo_usato: bool, attribuzione: string,
     *   quadro: array{png: string}|null,
     *   aree: list<array<string, mixed>>
     * }|null null quando la località non ha aree da disegnare
     */
    public function perLocalita(Locality $localita): ?array
    {
        $dati = PlanimetriaDati::perLocalita($localita);
        if ($dati === null) {
            return null;
        }

        $disegno = new DisegnoPlanimetria($this->sfondo);
        $sfondoUsato = false;

        $quadro = null;
        if (count($dati['aree']) >= 2) {
            $esito = $disegno->quadro($dati);
            $sfondoUsato = $sfondoUsato || $esito['sfondo'];
            $quadro = ['png' => base64_encode($esito['png'])];
        }

        // Oltre il tetto le tavole si omettono e la stampa lo dichiara: un
        // comune con decine di aree non deve esaurire memoria e tempo PHP
        $massimo = max(1, (int) config('planimetrie.massimo_tavole', 20));
        $daDisegnare = array_slice($dati['aree'], 0, $massimo);
        $omesse = count($dati['aree']) - count($daDisegnare);

        $aree = [];
        foreach ($daDisegnare as $area) {
            $esito = $disegno->area($area);
            $sfondoUsato = $sfondoUsato || $esito['sfondo'];
            $aree[] = [
                'numero' => $area['numero'],
                'nome' => $area['nome'],
                'codice' => $area['codice'],
                'stato' => $area['stato'],
                'mq' => $area['mq'],
                'conteggi' => self::conteggi($area['elementi']),
                'etichette' => $esito['etichette'],
                'png' => base64_encode($esito['png']),
            ];
        }

        return [
            'sfondo_usato' => $sfondoUsato,
            'attribuzione' => (string) config('planimetrie.attribuzione'),
            'quadro' => $quadro,
            'aree' => $aree,
            'omesse' => $omesse,
        ];
    }

    /** "10 elementi (9 alberi, 1 superficie)" — i gruppi a zero si tacciono. */
    private static function conteggi(array $elementi): string
    {
        $alberi = count(array_filter($elementi, fn ($e) => $e['albero']));
        $linee = count(array_filter($elementi, fn ($e) => in_array($e['tipo'], ['LINESTRING', 'MULTILINESTRING'], true)));
        $superfici = count(array_filter($elementi, fn ($e) => in_array($e['tipo'], ['POLYGON', 'MULTIPOLYGON'], true)));
        $altri = count($elementi) - $alberi - $linee - $superfici;

        $pezzi = array_filter([
            $alberi ? ($alberi === 1 ? '1 albero' : "{$alberi} alberi") : null,
            $superfici ? ($superfici === 1 ? '1 superficie' : "{$superfici} superfici") : null,
            $linee ? ($linee === 1 ? '1 elemento lineare' : "{$linee} elementi lineari") : null,
            $altri > 0 ? ($altri === 1 ? '1 altro punto' : "{$altri} altri punti") : null,
        ]);

        $totale = count($elementi);
        $testa = $totale === 1 ? '1 elemento' : "{$totale} elementi";

        return $totale === 0 ? 'nessun elemento censito'
            : $testa.($pezzi !== [] ? ' ('.implode(', ', $pezzi).')' : '');
    }
}
