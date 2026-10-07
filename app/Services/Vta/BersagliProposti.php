<?php

namespace App\Services\Vta;

use App\Models\Area;
use App\Models\Asset;
use Illuminate\Support\Str;

/**
 * I bersagli che il censimento propone da solo a chi compila una valutazione
 * di stabilita' (richiesta del committente 07/10/2026: "un albero che sta
 * dentro a un parco giochi, quel parco giochi lo deve consigliare in
 * automatico").
 *
 * Il bersaglio di una VTA e' cio' che un cedimento dell'albero puo' colpire:
 * persone e cose. Qui si propongono due famiglie, tutte e due dai dati gia'
 * censiti, senza inventare niente:
 *  - le aree del territorio in cui l'albero sta (il poligono dell'area lo
 *    contiene) o a cui la scheda e' assegnata: il parco giochi, il giardino
 *    della scuola, il parcheggio;
 *  - gli elementi censiti entro il raggio di caduta: panchine, giochi,
 *    percorsi, recinzioni, edifici, impianti, cavi. La vegetazione no (un
 *    altro albero non e' un bersaglio, e un prato e' il contesto, non un
 *    rischio), e nemmeno le aree amministrative del catalogo e i fattori
 *    ambientali (malattie, analisi, eventi), tranne le infrastrutture.
 *
 * Il raggio e' l'altezza dell'albero, che e' fin dove arriva se cade intero
 * (minimo RAGGIO_MINIMO per gli alberi giovani); senza altezza nota vale
 * RAGGIO_PREDEFINITO. Il tecnico puo' allargarlo o stringerlo dalla pagina.
 *
 * Le interrogazioni passano dai modelli (Asset, Area) cosi' valgono gli
 * scope di organizzazione e di zona: un tecnico di zona non si vede
 * proporre il territorio di un altro.
 */
final class BersagliProposti
{
    public const RAGGIO_PREDEFINITO = 15.0;

    public const RAGGIO_MINIMO = 5.0;

    public const RAGGIO_MASSIMO = 100.0;

    /** Quanti elementi al massimo si propongono: oltre, la risposta dice quanti restano. */
    public const LIMITE = 30;

    /**
     * I tipi del catalogo che possono essere un bersaglio, letti dal codice
     * (posizione 2 il tipo principale, posizioni 2-4 il sottotipo):
     * fuori la vegetazione (1), le aree di gestione (325), le informazioni
     * geodetiche (399) e i fattori ambientali (4) salvo le infrastrutture
     * (441: cavi elettrici, filo-tramviari).
     */
    private const SQL_TIPI_BERSAGLIO = "substr(code, 2, 1) <> '1'"
        ." AND substr(code, 2, 3) NOT IN ('325', '399')"
        ." AND (substr(code, 2, 1) <> '4' OR substr(code, 2, 3) = '441')";

    /**
     * @return array{raggio_m: float, raggio_origine: string, altezza_m: ?float, senza_posizione: bool,
     *     aree: list<array<string, mixed>>, elementi: list<array<string, mixed>>, altri: int}
     */
    public function per(Asset $albero, ?float $raggioRichiesto = null): array
    {
        $albero->loadMissing('tree');
        $altezza = $albero->tree?->height_m !== null ? (float) $albero->tree->height_m : null;
        [$raggio, $origine] = $this->raggio($altezza, $raggioRichiesto);

        $base = [
            'raggio_m' => $raggio,
            'raggio_origine' => $origine,
            'altezza_m' => $altezza,
            'senza_posizione' => false,
            'aree' => [],
            'elementi' => [],
            'altri' => 0,
        ];

        if ($albero->geom === null) {
            return ['senza_posizione' => true] + $base;
        }

        return array_merge($base, [
            'aree' => $this->aree($albero),
            ...$this->elementi($albero, $raggio),
        ]);
    }

    /** @return array{0: float, 1: string} */
    private function raggio(?float $altezza, ?float $richiesto): array
    {
        if ($richiesto !== null) {
            return [round(max(1.0, min(self::RAGGIO_MASSIMO, $richiesto)), 1), 'richiesto'];
        }
        if ($altezza !== null && $altezza > 0) {
            return [(float) max(self::RAGGIO_MINIMO, min(self::RAGGIO_MASSIMO, ceil($altezza))), 'altezza'];
        }

        return [self::RAGGIO_PREDEFINITO, 'predefinito'];
    }

    /** Il centro dell'albero, letto dalla sua riga: vale per punti e per chiome disegnate. */
    private function centro(): string
    {
        return '(SELECT ST_Centroid(a0.geom) FROM assets a0 WHERE a0.id = ?)';
    }

    /** @return list<array<string, mixed>> */
    private function aree(Asset $albero): array
    {
        $centro = $this->centro();

        return Area::query()
            ->whereNotIn('areas.status', ['planned', 'dismissed'])
            ->where(function ($q) use ($centro, $albero) {
                $q->whereRaw("ST_Intersects(areas.geom, {$centro})", [$albero->id]);
                if ($albero->area_id !== null) {
                    $q->orWhere('areas.id', $albero->area_id);
                }
            })
            ->selectRaw("areas.id, areas.name, areas.area_type, ST_Intersects(areas.geom, {$centro}) AS contiene", [$albero->id])
            ->orderByRaw('contiene DESC')
            ->orderByRaw("CASE areas.area_type WHEN 'functional' THEN 0 WHEN 'temporary' THEN 1 ELSE 2 END")
            ->orderBy('areas.name')
            ->get()
            ->map(fn (Area $area) => [
                'id' => $area->id,
                'nome' => $area->name,
                'tipo_area' => $area->area_type,
                'etichetta' => $area->name,
                'relazione' => $area->contiene ? 'contiene' : 'assegnata',
            ])
            ->values()
            ->all();
    }

    /** @return array{elementi: list<array<string, mixed>>, altri: int} */
    private function elementi(Asset $albero, float $raggio): array
    {
        $centro = $this->centro();
        // Prefiltro sul riquadro con l'indice spaziale (gradi: un metro e' circa
        // 1/111320 di grado di latitudine, in longitudine di piu'; il fattore 2
        // copre fino a 60 gradi di latitudine), poi la distanza vera sul geoide
        $gradi = $raggio / 111320 * 2;

        $candidati = fn () => Asset::query()
            ->fuoriArchivio()
            ->whereKeyNot($albero->id)
            ->whereNotNull('assets.geom')
            ->whereHas('objectType', fn ($q) => $q->whereRaw(self::SQL_TIPI_BERSAGLIO))
            ->whereRaw("assets.geom && ST_Expand({$centro}, ?)", [$albero->id, $gradi])
            ->whereRaw("ST_DWithin(assets.geom::geography, ({$centro})::geography, ?)", [$albero->id, $raggio]);

        $righe = $candidati()
            ->with(['objectType:id,code,name', 'area:id,name'])
            ->selectRaw(
                'assets.id, assets.census_code, assets.object_type_id, assets.area_id, assets.status,'
                ." ST_Distance(assets.geom::geography, ({$centro})::geography) AS distanza_m,"
                ." ST_Intersects(assets.geom, {$centro}) AS contiene,"
                .' GeometryType(assets.geom) AS forma',
                [$albero->id, $albero->id],
            )
            ->orderByRaw('contiene DESC, distanza_m ASC, census_code ASC')
            ->limit(self::LIMITE)
            ->get();

        // Quanti restano fuori dall'elenco: si contano solo se l'elenco e' pieno
        $altri = $righe->count() < self::LIMITE ? 0 : max(0, $candidati()->count() - self::LIMITE);

        $elementi = $righe->map(function (Asset $a) {
            $tipo = self::nomeTipo($a->objectType?->name);
            $contiene = (bool) $a->contiene;

            return [
                'id' => $a->id,
                'census_code' => $a->census_code,
                'tipo' => $tipo,
                'codice_tipo' => $a->objectType?->code,
                'forma' => match (strtoupper((string) $a->forma)) {
                    'POINT', 'MULTIPOINT' => 'punto',
                    'LINESTRING', 'MULTILINESTRING' => 'linea',
                    default => 'area',
                },
                'area_nome' => $a->area?->name,
                'distanza_m' => $contiene ? 0.0 : round((float) $a->distanza_m, 1),
                'contiene' => $contiene,
                'etichetta' => implode(' · ', array_filter([$a->census_code ?: 'senza cartellino', $tipo])),
            ];
        })->values()->all();

        return ['elementi' => $elementi, 'altri' => $altri];
    }

    /**
     * Il nome del tipo di catalogo senza la coda della geometria
     * ("panchina in legno - punto" diventa "Panchina in legno"): nel
     * documento conta che cosa e', non come e' disegnato.
     */
    public static function nomeTipo(?string $nome): string
    {
        $pulito = trim((string) preg_replace('/\s*-\s*(punto|linea|areale|area)\s*$/iu', '', (string) $nome));

        return Str::ucfirst($pulito);
    }
}
