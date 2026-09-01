<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\RicercaTestuale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Asset extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'area_id', 'object_type_id', 'census_code', 'status', 'geom',
        'gps_accuracy_m', 'survey_method', 'surveyed_at', 'surveyed_by',
        'valid_from', 'valid_to', 'attributes', 'notes', 'created_by', 'updated_by',
        'public_hidden',
    ];

    protected function casts(): array
    {
        return [
            'attributes' => 'array',
            'surveyed_at' => 'date',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'gps_accuracy_m' => 'decimal:2',
            'public_hidden' => 'boolean',
            'computed_area_sqm' => 'decimal:2',
            'computed_length_m' => 'decimal:2',
            'computed_perimeter_m' => 'decimal:2',
            'version' => 'integer',
        ];
    }

    /**
     * Ricerca a parole sull'elemento.
     *
     * Non solo codice e note: si cerca anche per specie e nome comune
     * dell'albero, per tipo di catalogo, per area, località e committente.
     * La pagina VTA prometteva "cerca per codice, specie o note" mentre la
     * specie non veniva cercata affatto; e chi ha in testa "la quercia del
     * parco Rossi" scrive quello, non un codice di censimento.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     */
    public function scopeCercaTesto($query, ?string $testo): void
    {
        RicercaTestuale::applica(
            $query,
            $testo,
            ['assets.census_code', 'assets.notes'],
            function ($gruppo, string $parola) {
                $gruppo
                    ->orWhereHas('tree', fn ($t) => RicercaTestuale::inColonne(
                        $t, $parola, ['genus', 'species', 'cultivar', 'common_name'],
                    ))
                    ->orWhereHas('objectType', fn ($t) => RicercaTestuale::inColonne(
                        $t, $parola, ['code', 'name'],
                    ))
                    ->orWhereHas('area', fn ($a) => RicercaTestuale::inColonne(
                        $a, $parola, ['name', 'code'],
                    ))
                    ->orWhereHas('area.locality', fn ($l) => RicercaTestuale::inColonne(
                        $l, $parola, ['name', 'code'],
                    ))
                    // Solo i nomi, che compaiono gia' nella scheda dell'elemento:
                    // codice cliente, partita IVA e comune della sede sono
                    // anagrafica, e chi non puo' aprirla non deve poterla
                    // indovinare cercando
                    ->orWhereHas('area.locality.site', fn ($s) => RicercaTestuale::inColonne(
                        $s, $parola, ['name'],
                    ))
                    ->orWhereHas('area.locality.site.client', fn ($c) => RicercaTestuale::inColonne(
                        $c, $parola, ['name'],
                    ));
            },
        );
    }

    public function area()
    {
        return $this->belongsTo(Area::class);
    }

    public function objectType()
    {
        return $this->belongsTo(CatalogObjectType::class, 'object_type_id');
    }

    public function photos()
    {
        return $this->hasMany(Photo::class);
    }

    public function documents()
    {
        return $this->hasMany(Document::class);
    }

    public function tags()
    {
        return $this->hasMany(AssetTag::class);
    }

    public function versions()
    {
        return $this->hasMany(AssetVersion::class);
    }

    public function tree()
    {
        return $this->hasOne(Tree::class, 'asset_id');
    }

    public function plantingSite()
    {
        return $this->hasOne(PlantingSite::class, 'asset_id');
    }

    /**
     * Solo il patrimonio in gestione: fuori le schede in archivio
     * (abbattute/rimosse e dismesse). La lista degli stati sta in
     * AssetStatus::ARCHIVIO, non qui.
     */
    public function scopeFuoriArchivio(Builder $query): Builder
    {
        return $query->whereNotIn($this->qualifyColumn('status'), \App\Support\AssetStatus::ARCHIVIO);
    }

    /** Solo l'archivio: le schede abbattute/rimosse e dismesse. */
    public function scopeInArchivio(Builder $query): Builder
    {
        return $query->whereIn($this->qualifyColumn('status'), \App\Support\AssetStatus::ARCHIVIO);
    }

    /** Aggiunge la geometria come GeoJSON alla select (colonna geom_geojson). */
    public function scopeWithGeoJson(Builder $query): Builder
    {
        return $query
            ->select($query->getQuery()->columns ?? [$this->qualifyColumn('*')])
            ->selectRaw('ST_AsGeoJSON('.$this->qualifyColumn('geom').')::json AS geom_geojson');
    }

    /** Filtro bounding box lon/lat: [minLon, minLat, maxLon, maxLat]. */
    public function scopeInBbox(Builder $query, array $bbox): Builder
    {
        return $query->whereRaw(
            $this->qualifyColumn('geom').' && ST_MakeEnvelope(?, ?, ?, ?, 4326)',
            $bbox
        );
    }
}
