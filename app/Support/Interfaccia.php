<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Quale veste del gestionale vede un utente (dal 26/09/2026).
 *
 * La "nuova" e' la bozza A approvata dal committente: sei voci di menu che
 * seguono il lavoro (Oggi, Patrimonio, Lavori, Documenti, Committenti,
 * Impostazioni) e le pagine rifatte un blocco alla volta. La "precedente" e'
 * quella di prima, che resta in linea finche' non si decide per tutti: ogni
 * utente sceglie la sua e la scelta sta in users.settings['interfaccia'].
 *
 * Le pagine gia' rifatte si servono con pagina(): la nuova a chi la usa, la
 * precedente agli altri. Le pagine non ancora rifatte restano quelle di prima
 * per tutti, dentro il menu nuovo o vecchio a seconda della scelta.
 */
final class Interfaccia
{
    public const NUOVA = 'nuova';

    public const PRECEDENTE = 'precedente';

    public const MODI = [self::NUOVA, self::PRECEDENTE];

    public static function per(?User $user): string
    {
        $scelta = $user?->settings['interfaccia'] ?? null;
        if (in_array($scelta, self::MODI, true)) {
            return $scelta;
        }

        return self::predefinita();
    }

    public static function predefinita(): string
    {
        $valore = config('interfaccia.predefinita');

        return in_array($valore, self::MODI, true) ? $valore : self::NUOVA;
    }

    public static function nuova(?User $user): bool
    {
        return self::per($user) === self::NUOVA;
    }

    /** La pagina Inertia giusta per l'utente collegato: la nuova a chi la usa, la precedente agli altri. */
    public static function pagina(string $precedente, string $nuova, array $props = []): Response
    {
        return Inertia::render(self::nuova(Auth::user()) ? $nuova : $precedente, $props);
    }
}
