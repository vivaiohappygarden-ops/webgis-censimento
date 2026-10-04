<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Una voce del dizionario delle specie: genere, specie, cultivar, famiglia,
 * nome comune e altri nomi (anche regionali: "pino romano"). Le voci di serie
 * hanno tenant_id nullo e valgono per tutte le organizzazioni; quelle con
 * tenant_id le ha aggiunte (o imparate) un'organizzazione e le vede solo lei.
 * Niente TenantScope: la query filtra a mano le due famiglie di voci.
 */
class TreeSpecies extends Model
{
    use HasUuids;

    protected $table = 'tree_species';

    protected $fillable = [
        'tenant_id', 'genus', 'species', 'cultivar', 'family', 'common_name', 'synonyms', 'source', 'created_by',
    ];

    protected function casts(): array
    {
        return ['synonyms' => 'array'];
    }

    protected static function booted(): void
    {
        // Il testo su cui cerca la ricerca a parole: tutto quello che una
        // persona potrebbe scrivere per trovare la voce, in una colonna sola
        static::saving(function (TreeSpecies $voce) {
            $voce->search_text = mb_strtolower(implode(' ', array_filter([
                $voce->genus, $voce->species, $voce->cultivar, $voce->family, $voce->common_name,
                implode(' ', $voce->synonyms ?? []),
            ])));
        });
    }

    /** @return array{id: string, genus: ?string, species: string, cultivar: ?string, family: ?string, common_name: ?string, synonyms: list<string>, propria: bool} */
    public function voce(): array
    {
        return [
            'id' => $this->id,
            'genus' => $this->genus,
            'species' => $this->species,
            'cultivar' => $this->cultivar,
            'family' => $this->family,
            'common_name' => $this->common_name,
            'synonyms' => array_values($this->synonyms ?? []),
            'propria' => $this->tenant_id !== null,
        ];
    }
}
