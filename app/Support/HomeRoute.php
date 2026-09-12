<?php

namespace App\Support;

use App\Models\User;

/**
 * Ognuno atterra dove può lavorare: chi sta in campo sull'app di campo,
 * l'ufficio sulla mappa, il cliente e l'impresa sul loro portale.
 *
 * "Sta in campo" non è il nome del ruolo (dal blocco dei ruoli su misura ce
 * ne possono essere altri) ma quello che può fare: censisce, e non gestisce
 * né i lavori né gli utenti. Un caposquadra o un tecnico, che programmano i
 * lavori, atterrano dove lavorano loro; dalla barra laterale l'app di campo
 * resta a un clic, e dall'app di campo il gestionale pure.
 */
class HomeRoute
{
    public static function for(User $user): string
    {
        return match (true) {
            $user->can('assets.create')
                && ! $user->can('works.manage')
                && ! $user->can('users.manage') => 'operatore',
            $user->can('assets.view') => 'mappa',
            $user->can('portal.view') => 'portale',
            $user->can('impresa.view') => 'impresa',
            default => 'guida',
        };
    }
}
