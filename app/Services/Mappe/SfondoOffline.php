<?php

namespace App\Services\Mappe;

use App\Models\Organization;
use App\Services\Mappe\PmTiles\Estrattore;
use App\Services\Mappe\PmTiles\LettorePmTiles;
use App\Services\Mappe\PmTiles\SorgenteByte;
use App\Services\Mappe\PmTiles\SorgenteFile;
use App\Services\Mappe\PmTiles\SorgenteHttp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Lo sfondo cartografico di un'organizzazione per l'uso senza rete: un
 * archivio PMTiles del suo territorio (aree ed elementi censiti, con un
 * margine) ritagliato dalle costruzioni Protomaps e conservato sul disco
 * privato. L'app di campo lo scarica una volta e lo legge anche offline.
 *
 * La logica sta qui una volta sola: la usano il comando sfondo:prepara, la
 * programmazione giornaliera e le chiamate che l'app di campo interroga.
 */
final class SfondoOffline
{
    public const NOME_FILE = 'territorio.pmtiles';

    public const NOME_META = 'territorio.json';

    public function __construct(private readonly Estrattore $estrattore = new Estrattore) {}

    /** Il riquadro [minLon, minLat, maxLon, maxLat] del territorio con il margine, o null se non c'e' niente di posizionato. */
    public function riquadroTerritorio(string $tenantId, ?float $margineKm = null): ?array
    {
        $riga = DB::selectOne(
            'SELECT ST_XMin(e) AS x1, ST_YMin(e) AS y1, ST_XMax(e) AS x2, ST_YMax(e) AS y2
             FROM (SELECT ST_Extent(g) AS e FROM (
                 SELECT geom::geometry AS g FROM areas WHERE tenant_id = ? AND deleted_at IS NULL AND geom IS NOT NULL
                 UNION ALL
                 SELECT geom::geometry FROM assets WHERE tenant_id = ? AND deleted_at IS NULL AND geom IS NOT NULL
             ) AS t) AS r',
            [$tenantId, $tenantId],
        );
        if ($riga?->x1 === null) {
            return null;
        }
        $margineKm ??= (float) config('sfondo.margine_km', 2.0);
        $latMedia = ((float) $riga->y1 + (float) $riga->y2) / 2;
        $dLat = $margineKm / 111.32;
        $dLon = $margineKm / max(0.1, 111.32 * cos(deg2rad($latMedia)));

        return [
            round((float) $riga->x1 - $dLon, 6),
            round((float) $riga->y1 - $dLat, 6),
            round((float) $riga->x2 + $dLon, 6),
            round((float) $riga->y2 + $dLat, 6),
        ];
    }

    /**
     * Ritaglia e conserva lo sfondo dell'organizzazione.
     *
     * @param  string|null  $sorgente  indirizzo https, percorso di un file, o null per l'ultima costruzione Protomaps
     * @param  callable(string):void|null  $log
     * @return array lo stato salvato
     */
    public function prepara(Organization $organizzazione, ?string $sorgente = null, ?callable $log = null, ?int $zoomMax = null): array
    {
        $log ??= static fn (string $riga) => null;
        $riquadro = $this->riquadroTerritorio($organizzazione->id);
        if ($riquadro === null) {
            throw new \RuntimeException("L'organizzazione {$organizzazione->name} non ha aree ne' elementi posizionati: niente territorio da ritagliare.");
        }
        [$byteSorgente, $versione] = $this->sorgente($sorgente);
        $log("Sorgente: $versione");
        $log('Territorio: '.implode(', ', $riquadro));

        $disco = Storage::disk();
        $cartella = $this->cartella($organizzazione->id);
        $disco->makeDirectory($cartella);
        $temporaneo = $disco->path($cartella.'/territorio.in-corso.pmtiles');
        $esito = $this->estrattore->estrai(
            new LettorePmTiles($byteSorgente),
            $riquadro,
            $zoomMax ?? (int) config('sfondo.zoom_max', 15),
            $temporaneo,
            (int) config('sfondo.tessere_massime', 6000),
            $log,
        );

        $definitivo = $disco->path($cartella.'/'.self::NOME_FILE);
        if (! rename($temporaneo, $definitivo)) {
            throw new \RuntimeException('Impossibile salvare lo sfondo nella sua cartella.');
        }
        $stato = [
            'disponibile' => true,
            'versione' => $versione,
            'generato_il' => now()->toIso8601String(),
            'byte' => $esito['byte'],
            'tessere' => $esito['tessere'],
            'zoom_min' => $esito['zoom_min'],
            'zoom_max' => $esito['zoom_max'],
            'riquadro' => $esito['riquadro'],
            'impronta' => hash_file('sha256', $definitivo),
        ];
        $disco->put($cartella.'/'.self::NOME_META, json_encode($stato, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $log('Sfondo pronto: '.$esito['tessere'].' tessere, '.number_format($esito['byte'] / 1048576, 1, ',', '.').' MB.');

        return $stato;
    }

    /** Lo stato salvato (null se lo sfondo non e' mai stato preparato). */
    public function stato(string $tenantId): ?array
    {
        $disco = Storage::disk();
        $cartella = $this->cartella($tenantId);
        if (! $disco->exists($cartella.'/'.self::NOME_META) || ! $disco->exists($cartella.'/'.self::NOME_FILE)) {
            return null;
        }
        $stato = json_decode($disco->get($cartella.'/'.self::NOME_META), true);

        return is_array($stato) ? $stato : null;
    }

    public function percorsoFile(string $tenantId): string
    {
        return Storage::disk()->path($this->cartella($tenantId).'/'.self::NOME_FILE);
    }

    /** Vero se lo sfondo c'e' ed e' stato rifatto da meno di tanti giorni. */
    public function eRecente(string $tenantId, ?int $giorni = null): bool
    {
        $stato = $this->stato($tenantId);
        if ($stato === null || empty($stato['generato_il'])) {
            return false;
        }
        $giorni ??= (int) config('sfondo.giorni_validita', 30);

        return \Carbon\CarbonImmutable::parse($stato['generato_il'])->addDays($giorni)->isFuture();
    }

    /**
     * La sorgente dei byte e il nome della versione: un file sul disco, un
     * indirizzo dato, oppure l'ultima costruzione Protomaps dall'elenco.
     *
     * @return array{0:SorgenteByte,1:string}
     */
    public function sorgente(?string $richiesta): array
    {
        $richiesta ??= config('sfondo.sorgente.fissa') ?: null;
        if ($richiesta !== null) {
            if (str_starts_with($richiesta, 'http://') || str_starts_with($richiesta, 'https://')) {
                return [new SorgenteHttp($richiesta, (int) config('sfondo.sorgente.timeout', 120)), basename(parse_url($richiesta, PHP_URL_PATH) ?: $richiesta)];
            }
            $percorso = str_starts_with($richiesta, 'file://') ? substr($richiesta, 7) : $richiesta;

            return [new SorgenteFile($percorso), basename($percorso).'@'.date('Y-m-d', (int) filemtime($percorso))];
        }

        $elenco = Http::timeout(30)->retry(2, 1000, throw: false)->get(config('sfondo.sorgente.elenco'));
        if (! $elenco->ok()) {
            throw new \RuntimeException('Elenco delle costruzioni Protomaps non raggiungibile ('.$elenco->status().').');
        }
        $chiavi = collect($elenco->json() ?: [])
            ->pluck('key')
            ->filter(fn ($k) => is_string($k) && str_ends_with($k, '.pmtiles'))
            ->sort()
            ->values();
        if ($chiavi->isEmpty()) {
            throw new \RuntimeException('L\'elenco delle costruzioni Protomaps e\' vuoto o illeggibile.');
        }
        $ultima = $chiavi->last();

        return [new SorgenteHttp(rtrim(config('sfondo.sorgente.base'), '/').'/'.$ultima, (int) config('sfondo.sorgente.timeout', 120)), $ultima];
    }

    private function cartella(string $tenantId): string
    {
        return trim(config('sfondo.cartella', 'sfondi'), '/').'/'.$tenantId;
    }
}
