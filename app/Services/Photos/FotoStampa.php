<?php

namespace App\Services\Photos;

use App\Models\Photo;

/**
 * Le fotografie di un elemento pronte per una stampa PDF.
 *
 * Regola delle stampe "vive" (la scheda dell'elemento): entrano TUTTE le
 * fotografie in ordine di scatto fino a un tetto; oltre, si stampano le più
 * recenti e il documento dichiara il totale. Niente esclusioni silenziose:
 * un file illeggibile o una foto rimandata per tempo finiscono nel conteggio
 * della nota, non nel dimenticatoio. La perizia ha regole sue (vicinanza al
 * sopralluogo, atti chiusi alla validazione) e le tiene in PeriziaController.
 */
class FotoStampa
{
    /**
     * Oltre questo numero il documento dichiara il totale e stampa le più
     * recenti: ogni immagine entra ricodificata e in base64, e un PDF con
     * centinaia di foto diventerebbe impossibile da aprire e da inviare.
     */
    public const MASSIMO = 24;

    /**
     * Quante foto si esaminano al più: un margine sopra il tetto, così un
     * file illeggibile fra le più recenti non ruba il posto a una foto
     * leggibile un po' più vecchia. Limita anche la lettura dal database:
     * un elemento seguito per anni può avere centinaia di foto e qui ne
     * servono solo le ultime.
     */
    public const CANDIDATE = self::MASSIMO * 2;

    /**
     * Tempo massimo per preparare le immagini. Le copie ridotte si fanno di
     * norma al caricamento della foto; questo è il paracadute per gli archivi
     * caricati prima: la stampa esce comunque (con la nota), le derivate già
     * scritte restano su disco e la ristampa successiva completa l'opera.
     */
    public const SECONDI_MASSIMI = 20;

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
     * @param  float|null  $scadenza  istante (microtime) oltre il quale non si preparano altre immagini
     * @return array{foto: list<array{data: string, scattata: ?string, categoria: ?string}>, nota: ?string}
     */
    public static function perScheda(string $assetId, ?float $scadenza = null): array
    {
        $scadenza ??= microtime(true) + self::SECONDI_MASSIMI;

        $totale = Photo::query()->where('asset_id', $assetId)->count();

        // Dalla più recente: sono quelle che raccontano lo stato attuale
        // dell'elemento, che è ciò che la scheda descrive
        $candidate = Photo::query()
            ->where('asset_id', $assetId)
            ->orderByRaw('COALESCE(taken_at, created_at) DESC, id DESC')
            ->limit(self::CANDIDATE)
            ->get();

        $quando = fn (Photo $p) => $p->taken_at ?? $p->created_at;

        $nonLeggibili = 0;
        $rimandate = 0;
        $foto = [];
        foreach ($candidate as $indice => $p) {
            if (count($foto) >= self::MASSIMO) {
                break;
            }
            if (microtime(true) > $scadenza) {
                // Il tempo è finito: si dichiara quante ne restano, la
                // ristampa ripartirà dalle derivate già pronte e completerà
                $rimandate = min(self::MASSIMO - count($foto), $candidate->count() - $indice);
                break;
            }

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

        // Raccolte dalla più recente, si stampano in ordine di scatto
        $foto = array_reverse($foto);

        return ['foto' => $foto, 'nota' => self::nota($totale, count($foto), $nonLeggibili, $rimandate)];
    }

    /** Che cosa non è finito in stampa, e perché. Se non manca niente, niente nota. */
    private static function nota(int $totale, int $allegate, int $nonLeggibili, int $rimandate): ?string
    {
        $parti = [];

        if ($totale - $allegate - $nonLeggibili - $rimandate > 0) {
            $parti[] = "Sull'elemento risultano {$totale} fotografie: ne sono stampate {$allegate}, le più recenti.";
        }

        if ($nonLeggibili === 1) {
            $parti[] = 'Una fotografia non è stata stampata perché il file non è leggibile.';
        } elseif ($nonLeggibili > 1) {
            $parti[] = "{$nonLeggibili} fotografie non sono state stampate perché i file non sono leggibili.";
        }

        if ($rimandate === 1) {
            $parti[] = 'Una fotografia non è entrata per il tempo massimo di preparazione: ristampando la scheda uscirà anche quella.';
        } elseif ($rimandate > 1) {
            $parti[] = "{$rimandate} fotografie non sono entrate per il tempo massimo di preparazione: ristampando la scheda usciranno anche quelle.";
        }

        return $parti === [] ? null : implode(' ', $parti);
    }
}
