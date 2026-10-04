<?php

namespace App\Models\Concerns;

use App\Models\Scopes\ZonaScope;

/**
 * Il modello sta nel perimetro territoriale (App\Support\PerimetroZone): per
 * l'utente di zona le sue query sono filtrate sui committenti delle sue zone.
 * Il modello dichiara come si lega al committente con la costante
 * PERIMETRO_ZONA (vedi ZonaScope).
 */
trait NelPerimetroZona
{
    public static function bootNelPerimetroZona(): void
    {
        static::addGlobalScope(new ZonaScope);
    }
}
