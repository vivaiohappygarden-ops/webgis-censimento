<?php

namespace App\Services\Pdf;

use App\Models\Organization;
use Illuminate\Support\Carbon;

/**
 * La riga "Luogo, data" che precede la firma nei documenti stampati.
 *
 * Prima ogni stampa lasciava uno spazio da riempire a penna ("Luogo e data:
 * ______"). Il luogo si imposta una volta sola nelle impostazioni e vale per
 * tutti i documenti; la data e' quella di stampa, salvo che il documento non
 * abbia una sua data ufficiale (la perizia validata: quella e' un atto chiuso,
 * ristamparlo deve dare lo stesso foglio).
 *
 * Senza luogo impostato resta la sola data: meglio una data giusta e nessun
 * luogo che uno spazio vuoto.
 */
class LuogoFirma
{
    /** Il luogo impostato dal committente, oppure null se non e' stato scritto. */
    public static function luogo(?string $tenantId): ?string
    {
        if ($tenantId === null) {
            return null;
        }

        $salvato = Organization::query()->find($tenantId)?->settings['professionista']['luogo'] ?? null;
        $salvato = is_string($salvato) ? trim($salvato) : '';

        return $salvato !== '' ? $salvato : null;
    }

    /**
     * "Roma, 23/08/2026", o la sola data se il luogo non e' impostato.
     *
     * @param  \DateTimeInterface|string|null  $data  omessa = data di stampa
     */
    public static function riga(?string $tenantId, $data = null): string
    {
        $giorno = ($data === null ? Carbon::now() : Carbon::parse($data))
            ->setTimezone('Europe/Rome')
            ->format('d/m/Y');

        $luogo = self::luogo($tenantId);

        return $luogo === null ? $giorno : "{$luogo}, {$giorno}";
    }
}
