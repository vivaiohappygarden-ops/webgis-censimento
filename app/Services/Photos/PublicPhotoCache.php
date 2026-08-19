<?php

namespace App\Services\Photos;

use App\Models\Photo;
use Illuminate\Support\Facades\Storage;

/**
 * Copia pubblica di una fotografia, calcolata una volta sola.
 *
 * La derivata (ridimensionata e ricodificata, quindi senza EXIF e senza
 * coordinate GPS) viene tenuta su disco accanto all'originale: senza questo,
 * ogni visita a una scheda del portale farebbe ricodificare l'immagine da
 * capo, e su un sito pubblico è il primo punto che si ingolfa.
 *
 * La risposta resta "no-store": spegnere la pubblicazione di un elemento deve
 * avere effetto subito, quindi il risparmio è di lavoro del server, non di
 * richieste. La derivata si cancella insieme alla foto.
 */
class PublicPhotoCache
{
    private const DIMENSIONE = 1200;

    private const QUALITA = 78;

    public static function jpeg(Photo $foto): ?string
    {
        $disk = Storage::disk();
        $derivata = self::percorso($foto);

        if ($disk->exists($derivata)) {
            return $disk->get($derivata);
        }

        $originale = $foto->getRawOriginal('s3_key');
        if (! $disk->exists($originale)) {
            return null;
        }

        $jpeg = ImageDerivative::jpeg($disk->get($originale), self::DIMENSIONE, self::QUALITA);
        if ($jpeg === null) {
            return null;
        }

        // Se la scrittura non riesce (disco pieno, permessi) la pagina deve
        // comunque mostrare la foto: si rinuncia al risparmio, non all'immagine
        try {
            $disk->put($derivata, $jpeg);
        } catch (\Throwable) {
            // nessuna azione: la derivata verrà ritentata alla prossima visita
        }

        return $jpeg;
    }

    public static function dimentica(Photo $foto): void
    {
        Storage::disk()->delete(self::percorso($foto));
    }

    private static function percorso(Photo $foto): string
    {
        return "derivate/{$foto->tenant_id}/{$foto->id}-".self::DIMENSIONE.'.jpg';
    }
}
