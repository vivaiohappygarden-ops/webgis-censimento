<?php

namespace App\Support;

use App\Models\User;

/** Ognuno atterra dove può lavorare: staff sulla mappa, cliente sul portale. */
class HomeRoute
{
    public static function for(User $user): string
    {
        return match (true) {
            $user->can('assets.view') => 'mappa',
            $user->can('portal.view') => 'portale',
            default => 'guida',
        };
    }
}
