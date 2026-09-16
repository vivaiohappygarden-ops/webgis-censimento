<?php

namespace App\Services\Demo;

use App\Models\Area;
use App\Models\Asset;
use App\Models\CatalogObjectType;
use App\Models\Client;
use App\Models\Locality;
use App\Models\Organization;
use App\Models\Tree;
use App\Models\TreeAssessment;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkType;
use App\Services\Portale\PortalStats;
use App\Support\Geometry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Patrimonio dimostrativo per il Comune Demo.
 *
 * Serve a far vedere il portale (e il gestionale) con dentro i numeri che
 * avrebbe un Comune vero, prima di avere i dati di quel Comune: specie
 * comuni del verde urbano, misure verosimili, date di rilievo sparse su due
 * anni, i quattro stati pubblici con proporzioni da patrimonio curato, e
 * qualche lavoro concluso perche' i conteggi "curati" e "potati" non
 * restino a zero.
 *
 * Scrive SOLO nell'organizzazione con slug "demo", quella creata da db:seed:
 * su qualunque altra si rifiuta, qualunque cosa gli si chieda. Un dato
 * inventato dentro un censimento vero sarebbe un danno che non si vede
 * finche' non lo legge un cittadino. E non si somma a un patrimonio gia'
 * popolato: oltre SOGLIA_GIA_POPOLATO elementi si ferma.
 *
 * Anteprima ed esecuzione passano dallo stesso metodo ($prova), come le
 * azioni multiple: il conteggio che si mostra prima e' quello che si scrive.
 *
 * La casualita' ha un seme fisso: due lanci sullo stesso stato producono lo
 * stesso patrimonio, e un difetto visto in demo si rivede uguale.
 */
final class PatrimonioDimostrativo
{
    public const SLUG_DEMO = 'demo';

    /** Oltre questo numero di elementi il Comune Demo e' gia' popolato. */
    public const SOGLIA_GIA_POPOLATO = 50;

    private const SEME = 20260914;

    private const NOTA = 'Elemento dimostrativo generato da demo:patrimonio.';

    /**
     * Le specie che si trovano davvero nel verde urbano del nord Italia:
     * genere, binomio, nome comune, e le taglie massime plausibili
     * (altezza m, diametro tronco cm, chioma m, eta' anni).
     */
    private const SPECIE = [
        ['Platanus', 'Platanus x acerifolia', 'Platano comune', 22, 95, 30, 150],
        ['Tilia', 'Tilia cordata', 'Tiglio selvatico', 16, 70, 22, 120],
        ['Tilia', 'Tilia platyphyllos', 'Tiglio nostrano', 18, 75, 24, 120],
        ['Celtis', 'Celtis australis', 'Bagolaro', 15, 65, 20, 110],
        ['Quercus', 'Quercus ilex', 'Leccio', 12, 60, 16, 130],
        ['Quercus', 'Quercus robur', 'Farnia', 20, 90, 26, 180],
        ['Acer', 'Acer campestre', 'Acero campestre', 9, 40, 12, 80],
        ['Acer', 'Acer platanoides', 'Acero riccio', 13, 55, 18, 90],
        ['Aesculus', 'Aesculus hippocastanum', 'Ippocastano', 15, 70, 20, 110],
        ['Carpinus', 'Carpinus betulus', 'Carpino bianco', 11, 45, 15, 90],
        ['Prunus', 'Prunus cerasifera', 'Mirabolano', 6, 28, 8, 45],
        ['Cupressus', 'Cupressus sempervirens', 'Cipresso comune', 12, 45, 18, 120],
        ['Pinus', 'Pinus pinea', 'Pino domestico', 18, 80, 22, 140],
        ['Liquidambar', 'Liquidambar styraciflua', 'Liquidambar', 12, 45, 16, 70],
        ['Fraxinus', 'Fraxinus excelsior', 'Frassino maggiore', 16, 65, 22, 110],
        ['Ginkgo', 'Ginkgo biloba', 'Ginkgo', 14, 50, 18, 90],
        ['Betula', 'Betula pendula', 'Betulla bianca', 10, 35, 12, 60],
        ['Magnolia', 'Magnolia grandiflora', 'Magnolia', 10, 45, 14, 80],
    ];

    /**
     * Tre aree: il parco del seed (che esiste gia'), un viale e un giardino
     * scolastico. Codice, nome, riquadro [lon1, lat1, lon2, lat2] e peso con
     * cui si dividono gli alberi.
     */
    private const AREE = [
        ['AREA-001', 'Parco Demo - settore nord', [9.1890, 45.4640, 9.1930, 45.4665], 180],
        ['AREA-002', 'Viale della Stazione', [9.1935, 45.4640, 9.1990, 45.4650], 140],
        ['AREA-003', 'Giardino della scuola Carducci', [9.1860, 45.4668, 9.1900, 45.4685], 95],
    ];

    private function __construct(private readonly Organization $organizzazione) {}

    /** L'organizzazione dimostrativa, se db:seed l'ha creata. */
    public static function organizzazioneDemo(): ?Organization
    {
        return Organization::query()->where('slug', self::SLUG_DEMO)->first();
    }

    /**
     * L'unica porta d'ingresso: rifiuta qualunque organizzazione che non sia
     * quella dimostrativa.
     */
    public static function per(Organization $organizzazione): self
    {
        if ($organizzazione->slug !== self::SLUG_DEMO) {
            throw new \DomainException(
                'Il patrimonio dimostrativo si genera solo nell\'organizzazione "'.self::SLUG_DEMO
                .'": "'.$organizzazione->slug.'" non lo e\'.'
            );
        }

        return new self($organizzazione);
    }

    /** Quanti elementi ha gia' l'organizzazione dimostrativa. */
    public function elementiEsistenti(): int
    {
        return Asset::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->organizzazione->id)
            ->count();
    }

    /**
     * Genera (o, con $prova, conta soltanto) il patrimonio.
     *
     * @return array{alberi: int, aree: int, valutazioni: int, lavori: int, prova: bool}
     */
    public function genera(int $alberi, bool $prova = false): array
    {
        if ($alberi < 1) {
            throw new \InvalidArgumentException('Il numero di alberi deve essere almeno 1.');
        }

        $esistenti = $this->elementiEsistenti();
        if ($esistenti > self::SOGLIA_GIA_POPOLATO) {
            throw new \DomainException(
                "Il Comune Demo ha gia' {$esistenti} elementi: il patrimonio dimostrativo non si somma a uno gia' popolato."
            );
        }

        $client = Client::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->organizzazione->id)->orderBy('created_at')->first()
            ?? throw new \DomainException('L\'organizzazione demo non ha un committente: lanciare prima db:seed.');
        $locality = Locality::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->organizzazione->id)->orderBy('created_at')->first()
            ?? throw new \DomainException('L\'organizzazione demo non ha una localita\': lanciare prima db:seed.');
        $tipoAlbero = CatalogObjectType::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->organizzazione->id)->where('code', 'P103108')->first()
            ?? throw new \DomainException('Manca il tipo di catalogo P103108 (albero): il catalogo non e\' installato.');
        $autore = User::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->organizzazione->id)->orderBy('created_at')->value('id');

        // gli alberi si dividono fra le aree secondo i pesi; l'ultima prende
        // il resto, cosi' la somma e' esattamente quella chiesta
        $pesoTotale = array_sum(array_column(self::AREE, 3));
        $quote = [];
        $assegnati = 0;
        foreach (self::AREE as $indice => $area) {
            $quote[$indice] = $indice === count(self::AREE) - 1
                ? $alberi - $assegnati
                : (int) round($alberi * $area[3] / $pesoTotale);
            $assegnati += $quote[$indice];
        }

        $esegui = function () use ($prova, $client, $locality, $tipoAlbero, $autore, $quote): array {
            mt_srand(self::SEME);

            $conteggio = ['alberi' => 0, 'aree' => 0, 'valutazioni' => 0, 'lavori' => 0, 'prova' => $prova];
            $progressivo = Asset::query()->withoutGlobalScopes()
                ->where('tenant_id', $this->organizzazione->id)
                ->where('census_code', 'like', 'ALB-%')->count();
            $idAlberi = [];

            foreach (self::AREE as $indice => [$codice, $nome, $quadro, $peso]) {
                $area = Area::query()->withoutGlobalScopes()
                    ->where('tenant_id', $this->organizzazione->id)->where('code', $codice)->first();

                if ($area === null) {
                    $conteggio['aree']++;
                    if (! $prova) {
                        $area = $this->creaArea($locality, $codice, $nome, $quadro, $autore);
                    }
                }

                [$x1, $y1, $x2, $y2] = $quadro;

                for ($i = 0; $i < $quote[$indice]; $i++) {
                    $pianta = $this->pianta();
                    $progressivo++;
                    $conteggio['alberi']++;
                    if ($pianta['esito'] !== null) {
                        $conteggio['valutazioni']++;
                    }

                    if ($prova) {
                        continue;
                    }

                    $asset = Asset::create([
                        'tenant_id' => $this->organizzazione->id,
                        'area_id' => $area->id,
                        'object_type_id' => $tipoAlbero->id,
                        'census_code' => sprintf('ALB-%04d', $progressivo),
                        'status' => 'active',
                        'geom' => Geometry::toEwkb(['type' => 'Point', 'coordinates' => [
                            round($x1 + ($x2 - $x1) * $pianta['px'], 6),
                            round($y1 + ($y2 - $y1) * $pianta['py'], 6),
                        ]]),
                        'survey_method' => 'gps',
                        'surveyed_at' => now()->subDays($pianta['giorni'])->toDateString(),
                        'notes' => self::NOTA,
                        'created_by' => $autore,
                        'updated_by' => $autore,
                    ]);

                    Tree::create([
                        'asset_id' => $asset->id,
                        'tenant_id' => $this->organizzazione->id,
                        'genus' => $pianta['genere'],
                        'species' => $pianta['binomio'],
                        'common_name' => $pianta['comune'],
                        'height_m' => $pianta['altezza'],
                        'dbh_cm' => $pianta['diametro'],
                        'crown_diameter_m' => $pianta['chioma'],
                        'age_years_est' => $pianta['eta'],
                        'vegetative_state' => $pianta['vigore'],
                    ]);

                    if ($pianta['esito'] !== null) {
                        TreeAssessment::create([
                            'tenant_id' => $this->organizzazione->id,
                            'tree_id' => $asset->id,
                            'assessment_type' => 'vta_visual',
                            'assessed_on' => now()->subDays($pianta['giorniValutazione'])->toDateString(),
                            'assessor_external' => 'Tecnico dimostrativo',
                            'failure_class' => $pianta['classe'],
                            'outcome' => $pianta['esito'],
                            'created_by' => $autore,
                            'updated_by' => $autore,
                        ]);
                    }

                    $idAlberi[] = $asset->id;
                }
            }

            // lavori conclusi: circa un albero su quattro potato, uno su sette
            // curato, cosi' i due conteggi del portale non restano a zero
            $potati = (int) round($conteggio['alberi'] * 0.23);
            $curati = (int) round($conteggio['alberi'] * 0.14);
            $conteggio['lavori'] = ($potati > 0 ? 1 : 0) + ($curati > 0 ? 1 : 0);

            if (! $prova && $idAlberi !== []) {
                shuffle($idAlberi);
                $this->creaLavoro($client, 'POT-01', 'Potatura di formazione', 'ODS-DEMO-0001', array_slice($idAlberi, 0, $potati), $autore);
                $this->creaLavoro($client, 'CUR-01', 'Trattamento fitosanitario', 'ODS-DEMO-0002', array_slice($idAlberi, $potati, $curati), $autore);
                PortalStats::dimentica($client);
            }

            return $conteggio;
        };

        return $prova ? $esegui() : DB::transaction($esegui);
    }

    /**
     * Una pianta verosimile. Le piante grandi sono meno delle piccole: si
     * pesca una taglia e tutte le misure la seguono, o uscirebbero alberi
     * impossibili (alti trenta metri con il tronco di un ombrello).
     *
     * @return array<string, mixed>
     */
    private function pianta(): array
    {
        [$genere, $binomio, $comune, $hMax, $dMax, $cMax, $etaMax] = self::SPECIE[mt_rand(0, count(self::SPECIE) - 1)];

        $taglia = (mt_rand(20, 100) / 100) ** 1.4;

        // lo stato pubblico nasce dall'ultima valutazione: si distribuisce
        // come in un patrimonio curato, non a caso. Le regole sono quelle di
        // PortalState::sql(): 'fell' o classe D -> in verifica,
        // 'prescriptions' -> da potare, 'monitor' -> in cura.
        $sorte = mt_rand(1, 100);
        [$esito, $classe] = match (true) {
            $sorte <= 5 => ['fell', 'D'],
            $sorte <= 18 => ['prescriptions', 'B'],
            $sorte <= 32 => ['monitor', 'B'],
            default => [null, null],
        };

        return [
            'genere' => $genere,
            'binomio' => $binomio,
            'comune' => $comune,
            'altezza' => round(max(3, $hMax * $taglia * (0.85 + mt_rand(0, 30) / 100)), 1),
            'diametro' => (int) max(8, $dMax * $taglia * (0.8 + mt_rand(0, 40) / 100)),
            'chioma' => round(max(1.5, $cMax * $taglia * (0.8 + mt_rand(0, 40) / 100)), 1),
            'eta' => (int) max(4, $etaMax * $taglia * (0.7 + mt_rand(0, 50) / 100)),
            'vigore' => ['buono', 'buono', 'buono', 'discreto', 'scarso'][mt_rand(0, 4)],
            'px' => mt_rand(0, 1000) / 1000,
            'py' => mt_rand(0, 1000) / 1000,
            'giorni' => mt_rand(20, 700),
            'giorniValutazione' => mt_rand(10, 500),
            'esito' => $esito,
            'classe' => $classe,
        ];
    }

    private function creaArea(Locality $locality, string $codice, string $nome, array $quadro, ?string $autore): Area
    {
        [$x1, $y1, $x2, $y2] = $quadro;

        return Area::create([
            'tenant_id' => $this->organizzazione->id,
            'locality_id' => $locality->id,
            'code' => $codice,
            'name' => $nome,
            'manager' => $this->organizzazione->name,
            'geom' => Geometry::toEwkb(['type' => 'Polygon', 'coordinates' => [[
                [$x1, $y1], [$x2, $y1], [$x2, $y2], [$x1, $y2], [$x1, $y1],
            ]]], forceMultiPolygon: true),
            'created_by' => $autore,
            'updated_by' => $autore,
        ]);
    }

    /** Un ordine di lavoro concluso e pubblico, con gli alberi su cui e' stato fatto. */
    private function creaLavoro(Client $client, string $codiceTipo, string $nomeTipo, string $codiceOrdine, array $idAlberi, ?string $autore): void
    {
        if ($idAlberi === []) {
            return;
        }

        $tipo = WorkType::query()->withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $this->organizzazione->id, 'code' => $codiceTipo],
            ['name' => $nomeTipo, 'category' => 'arboricoltura', 'unit' => 'cad', 'is_active' => true],
        );

        $ordine = WorkOrder::query()->withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $this->organizzazione->id, 'code' => $codiceOrdine],
            [
                'client_id' => $client->id,
                'work_type_id' => $tipo->id,
                'title' => $nomeTipo.' - stagione dimostrativa',
                'description' => self::NOTA,
                'status' => 'completed',
                'priority' => 'normal',
                'origin' => 'manual',
                'completed_at' => now()->subDays(mt_rand(30, 300)),
                'is_public' => true,
                'created_by' => $autore,
                'updated_by' => $autore,
            ],
        );

        $adesso = now();
        DB::table('work_order_assets')->insertOrIgnore(array_map(fn (string $id) => [
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->organizzazione->id,
            'work_order_id' => $ordine->id,
            'asset_id' => $id,
            'status' => 'done',
            'created_at' => $adesso,
            'updated_at' => $adesso,
        ], $idAlberi));
    }
}
