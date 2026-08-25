<?php

namespace App\Services\Photos;

use App\Models\Photo;

/**
 * Le fotografie di un elemento pronte per una stampa PDF.
 *
 * Regola delle stampe "vive" (la scheda dell'elemento): entrano TUTTE le
 * fotografie in ordine di scatto fino a un tetto; oltre, si stampano le più
 * recenti e il documento dichiara il totale. Niente esclusioni silenziose:
 * un file illeggibile finisce nel conteggio della nota, non nel dimenticatoio.
 * La perizia ha regole sue (vicinanza al sopralluogo, atti chiusi alla
 * validazione) e le tiene in PeriziaController.
 */
class FotoStampa
{
    /**
     * Oltre questo numero il documento dichiara il totale e stampa le più
     * recenti: ogni immagine entra ricodificata e in base64, e un PDF con
     * centinaia di foto diventerebbe impossibile da aprire e da inviare.
     */
    public const MASSIMO = 24;

    /** Etichette italiane delle categorie di fotografia. */
    public const ETICHETTE = [
        'census' => 'censimento',
        'reference' => 'riferimento',
        'before' => 'prima del lavoro',
        'during' => 'durante il lavoro',
        'after' => 'dopo il lavoro',
        'organ' => 'organo',
        'defect' => 'difetto',
        'issue' => 'segnalazione',
        'other' => 'altro',
    ];

    /**
     * @return array{foto: list<array{data: string, scattata: ?string, categoria: ?string}>, nota: ?string}
     */
    public static function perScheda(string $assetId): array
    {
        $quando = fn (Photo $p) => $p->taken_at ?? $p->created_at;

        $tutte = Photo::query()
            ->where('asset_id', $assetId)
            ->orderByRaw('COALESCE(taken_at, created_at), id')
            ->get();

        $totale = $tutte->count();

        // La scheda descrive lo stato attuale dell'elemento: oltre il tetto
        // si tengono le foto più recenti, che sono quelle che lo raccontano
        $scelte = $totale > self::MASSIMO ? $tutte->slice(-self::MASSIMO)->values() : $tutte;

        $nonLeggibili = 0;
        $foto = [];
        foreach ($scelte as $p) {
            // La copia ridotta si calcola una volta sola e resta su disco:
            // ristampare non deve ricodificare da capo due dozzine di scatti
            $jpeg = PublicPhotoCache::jpeg($p);

            if ($jpeg === null) {
                $nonLeggibili++;

                continue;
            }

            $foto[] = [
                'data' => 'data:image/jpeg;base64,'.base64_encode($jpeg),
                'scattata' => $quando($p)?->setTimezone('Europe/Rome')->format('d/m/Y'),
                'categoria' => self::ETICHETTE[$p->category] ?? null,
            ];
        }

        return ['foto' => $foto, 'nota' => self::nota($totale, count($foto), $nonLeggibili)];
    }

    /** Che cosa non è finito in stampa, e perché. Se non manca niente, niente nota. */
    private static function nota(int $totale, int $allegate, int $nonLeggibili): ?string
    {
        $parti = [];

        if ($totale - $allegate - $nonLeggibili > 0) {
            $parti[] = "Sull'elemento risultano {$totale} fotografie: ne sono stampate {$allegate}, le più recenti.";
        }

        if ($nonLeggibili === 1) {
            $parti[] = 'Una fotografia non è stata stampata perché il file non è leggibile.';
        } elseif ($nonLeggibili > 1) {
            $parti[] = "{$nonLeggibili} fotografie non sono state stampate perché i file non sono leggibili.";
        }

        return $parti === [] ? null : implode(' ', $parti);
    }
}
