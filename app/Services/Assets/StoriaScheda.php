<?php

namespace App\Services\Assets;

use App\Models\Area;
use App\Models\Asset;
use App\Models\CatalogObjectType;
use App\Models\User;
use App\Support\AssetStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * La storia delle modifiche di un elemento, raccontata per campi.
 *
 * Il database fotografa la scheda a ogni salvataggio (asset_versions); qui le
 * fotografie consecutive si confrontano e ne esce, per ogni revisione, "chi,
 * quando e che cosa e' cambiato: da X a Y". I nomi dei campi sono in
 * italiano e i valori gia' formattati: la pagina deve solo mostrarli.
 */
class StoriaScheda
{
    /** Oltre questo numero di revisioni si mostra solo il tratto piu' recente. */
    public const MASSIMO_REVISIONI = 50;

    private const CAMPI = [
        'census_code' => 'Codice censimento',
        'status' => 'Stato',
        'notes' => 'Note',
        'surveyed_at' => 'Data di rilievo',
        'area_id' => 'Area',
        'object_type_id' => 'Tipo di catalogo',
        'public_hidden' => 'Nascosto sul portale',
        'valid_from' => 'Inizio validità',
        'valid_to' => 'Fine validità',
    ];

    private const CAMPI_ALBERO = [
        'genus' => 'Genere',
        'species' => 'Specie',
        'cultivar' => 'Cultivar',
        'family' => 'Famiglia',
        'common_name' => 'Nome comune',
        'plant_number' => 'Numero pianta',
        'height_m' => 'Altezza (m)',
        'dbh_cm' => 'Diametro del fusto (cm)',
        'trunk_circumference_cm' => 'Circonferenza (cm)',
        'trunk_count' => 'Numero di fusti',
        'crown_diameter_m' => 'Diametro chioma (m)',
        'crown_insertion_m' => 'Inserzione chioma (m)',
        'age_years_est' => 'Età stimata (anni)',
        'age_class' => 'Classe di età',
        'vegetative_state' => 'Stato vegetativo',
        'is_monumental' => 'Monumentale',
        'monumental_ref' => 'Riferimento monumentale',
        'is_protected' => 'Tutelato',
        'protection_ref' => 'Riferimento tutela',
        'is_dedicated' => 'Dedicato',
        'dedicated_to' => 'Dedicato a',
        'has_stake' => 'Tutore',
        'has_bracing' => 'Consolidamento',
        'bracing_notes' => 'Note sul consolidamento',
        'planted_on' => 'Data di impianto',
    ];

    private const CAMPI_POSTO = [
        'status' => 'Stato del posto',
        'planned_species' => 'Specie prevista',
        'origin' => 'Origine',
        'target_season' => 'Stagione prevista',
        'notes' => 'Note del posto',
    ];

    public static function per(Asset $asset): array
    {
        $righe = DB::table('asset_versions')
            ->where('asset_id', $asset->id)
            ->orderByDesc('version')
            ->limit(self::MASSIMO_REVISIONI)
            ->get(['version', 'snapshot', 'changed_by', 'changed_at', 'change_source',
                DB::raw('ST_AsText(geom) AS geom_testo')])
            ->reverse()
            ->values();

        // La catena degli stati: le fotografie, poi la scheda attuale
        $stati = $righe->map(fn ($r) => (array) json_decode($r->snapshot, true))->all();
        $stati[] = self::statoAttuale($asset);

        $nomi = self::nomi($righe->pluck('changed_by')->filter()->unique()->all());
        [$aree, $tipi] = self::etichetteCollegate($stati);

        $revisioni = [];
        foreach ($righe as $i => $riga) {
            $modifiche = self::confronta($stati[$i], $stati[$i + 1], $aree, $tipi);

            $geomDopo = $righe[$i + 1]->geom_testo ?? self::geomAttuale($asset);
            if ($riga->geom_testo !== null && $geomDopo !== null && $riga->geom_testo !== $geomDopo) {
                $modifiche[] = ['campo' => 'Posizione o geometria', 'prima' => null, 'dopo' => 'modificata sulla mappa'];
            }

            // Un salvataggio senza differenze visibili (solo campi tecnici)
            // non racconta niente: non si mostra
            if ($modifiche === []) {
                continue;
            }

            $revisioni[] = [
                'versione' => $riga->version + 1,
                'quando' => Carbon::parse($riga->changed_at)->setTimezone('Europe/Rome')->format('d/m/Y H:i'),
                'chi' => $nomi[$riga->changed_by] ?? null,
                'origine' => $riga->change_source,
                'modifiche' => $modifiche,
            ];
        }

        return array_reverse($revisioni);
    }

    /** La scheda com'e' adesso, nella stessa forma delle fotografie. */
    private static function statoAttuale(Asset $asset): array
    {
        $stato = $asset->only(array_keys(self::CAMPI));
        $stato['attributes'] = $asset->attributes ?? [];

        if ($asset->tree) {
            $stato['albero'] = $asset->tree->only(array_keys(self::CAMPI_ALBERO));
        }
        if ($asset->plantingSite) {
            $stato['posto'] = $asset->plantingSite->only(array_keys(self::CAMPI_POSTO));
        }

        return $stato;
    }

    private static function geomAttuale(Asset $asset): ?string
    {
        return DB::selectOne('SELECT ST_AsText(geom) AS t FROM assets WHERE id = ?', [$asset->id])->t;
    }

    /** @return list<array{campo: string, prima: ?string, dopo: ?string}> */
    private static function confronta(array $prima, array $dopo, array $aree, array $tipi): array
    {
        $modifiche = [];

        foreach (self::CAMPI as $campo => $etichetta) {
            $a = $prima[$campo] ?? null;
            $b = $dopo[$campo] ?? null;
            if (self::normalizza($a) === self::normalizza($b)) {
                continue;
            }
            $modifiche[] = [
                'campo' => $etichetta,
                'prima' => self::formatta($campo, $a, $aree, $tipi),
                'dopo' => self::formatta($campo, $b, $aree, $tipi),
            ];
        }

        // Gli attributi del tipo (campi personalizzati), chiave per chiave
        $attrA = (array) ($prima['attributes'] ?? []);
        $attrB = (array) ($dopo['attributes'] ?? []);
        foreach (array_unique([...array_keys($attrA), ...array_keys($attrB)]) as $chiave) {
            $a = $attrA[$chiave] ?? null;
            $b = $attrB[$chiave] ?? null;
            if (self::normalizza($a) === self::normalizza($b)) {
                continue;
            }
            $modifiche[] = ['campo' => 'Attributo "'.$chiave.'"', 'prima' => self::valore($a), 'dopo' => self::valore($b)];
        }

        foreach (['albero' => self::CAMPI_ALBERO, 'posto' => self::CAMPI_POSTO] as $sezione => $campi) {
            $sa = (array) ($prima[$sezione] ?? []);
            $sb = (array) ($dopo[$sezione] ?? []);
            if ($sa === [] && $sb === []) {
                continue;
            }
            foreach ($campi as $campo => $etichetta) {
                $a = $sa[$campo] ?? null;
                $b = $sb[$campo] ?? null;
                if (self::normalizza($a) === self::normalizza($b)) {
                    continue;
                }
                // Le fotografie piu' vecchie di questo aggiornamento non hanno
                // la sezione: un confronto "da niente a tutto" mentirebbe
                if ($sa === [] && $sb !== []) {
                    continue;
                }
                $modifiche[] = ['campo' => $etichetta, 'prima' => self::valore($a), 'dopo' => self::valore($b)];
            }
        }

        return $modifiche;
    }

    /** Confronto indifferente a "12.50" contro 12.5 e a true contro "true". */
    private static function normalizza(mixed $valore): mixed
    {
        if (is_bool($valore)) {
            return $valore;
        }
        // La scheda attuale porta oggetti Carbon (cast "date"), la fotografia
        // jsonb stringhe: senza riportarli alla stessa forma ogni data
        // sembrerebbe cambiata a ogni salvataggio
        if ($valore instanceof \DateTimeInterface) {
            $valore = $valore->format('Y-m-d H:i');
        }
        if ($valore === null || $valore === '') {
            return null;
        }
        if (is_numeric($valore)) {
            return (float) $valore;
        }
        // "2026-08-18", "2026-08-18T00:00:00Z" e "2026-08-18 00:00" sono la
        // stessa data: a mezzanotte conta solo il giorno
        if (is_string($valore) && preg_match('/^(\d{4}-\d{2}-\d{2})(?:[T ](\d{2}:\d{2}))?/', $valore, $m)) {
            return ($m[2] ?? '00:00') === '00:00' ? $m[1] : $m[1].' '.$m[2];
        }

        return $valore;
    }

    private static function formatta(string $campo, mixed $valore, array $aree, array $tipi): ?string
    {
        if ($valore === null || $valore === '') {
            return null;
        }

        return match ($campo) {
            'status' => AssetStatus::label($valore),
            'area_id' => $aree[$valore] ?? $valore,
            'object_type_id' => $tipi[$valore] ?? $valore,
            'surveyed_at', 'valid_from', 'valid_to' => Carbon::parse($valore)->format('d/m/Y'),
            default => self::valore($valore),
        };
    }

    private static function valore(mixed $valore): ?string
    {
        if ($valore instanceof \DateTimeInterface) {
            $valore = $valore->format('Y-m-d');
        }
        if ($valore === null || $valore === '') {
            return null;
        }
        if (is_bool($valore)) {
            return $valore ? 'sì' : 'no';
        }
        if (is_array($valore)) {
            return implode(', ', array_map(fn ($v) => (string) $v, $valore));
        }
        if (is_string($valore) && preg_match('/^(\d{4})-(\d{2})-(\d{2})([T ]|$)/', $valore, $m)) {
            return "{$m[3]}/{$m[2]}/{$m[1]}";
        }

        return (string) $valore;
    }

    /** @return array<string, string> id utente -> nome */
    private static function nomi(array $ids): array
    {
        return $ids === [] ? [] : User::query()->withoutGlobalScopes()
            ->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /** @return array{0: array<string,string>, 1: array<string,string>} */
    private static function etichetteCollegate(array $stati): array
    {
        $areaIds = [];
        $tipoIds = [];
        foreach ($stati as $stato) {
            if (! empty($stato['area_id'])) {
                $areaIds[] = $stato['area_id'];
            }
            if (! empty($stato['object_type_id'])) {
                $tipoIds[] = $stato['object_type_id'];
            }
        }

        return [
            Area::query()->withoutGlobalScopes()->whereIn('id', array_unique($areaIds))->pluck('name', 'id')->all(),
            CatalogObjectType::query()->withoutGlobalScopes()->whereIn('id', array_unique($tipoIds))
                ->get(['id', 'code', 'name'])->mapWithKeys(fn ($t) => [$t->id => "{$t->code} - {$t->name}"])->all(),
        ];
    }
}
