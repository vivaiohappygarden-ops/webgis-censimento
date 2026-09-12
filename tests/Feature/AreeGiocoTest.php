<?php

namespace Tests\Feature;

use App\Models\CatalogObjectType;
use App\Models\ChecklistItem;
use App\Models\CustomField;
use App\Models\InspectionTemplate;
use App\Services\Playgrounds\ModelloAreeGioco;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Il corredo pronto per le aree gioco: campi della scheda dell'attrezzo e
 * liste di controllo impostate sulla UNI EN 1176-7.
 *
 * Si installa con un gesto, si adatta dopo, e rilanciarlo non sovrascrive
 * mai quello che il tecnico ha cambiato: quel che c'e' si dichiara presente
 * e si lascia stare.
 */
class AreeGiocoTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organizzazione;

    private $utente;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->organizzazione, $this->utente] = $this->createTenantUser();
        $this->actingAsTenantUser($this->utente);

        // I tipi di catalogo del gioco: nel programma vero li porta il
        // catalogo Modello Dati v2.1
        foreach ([
            ['P214250', 'Gioco singolo', 'P'],
            ['P214251', 'Gioco complesso', 'P'],
            ['S327552', 'Area gioco', 'S'],
        ] as [$codice, $nome, $geo]) {
            $this->makeObjectType($this->organizzazione, $geo, $codice, ['name' => $nome]);
        }
    }

    private function tipo(string $codice): CatalogObjectType
    {
        return CatalogObjectType::query()->where('code', $codice)->firstOrFail();
    }

    public function test_l_anteprima_non_scrive_niente(): void
    {
        $risposta = $this->postJson('/api/v1/aree-gioco/modello', ['prova' => 1])->assertOk();

        $this->assertNotEmpty($risposta->json('data.campi'));
        $this->assertCount(3, $risposta->json('data.modelli'));
        $this->assertSame(0, CustomField::query()->count());
        $this->assertSame(0, InspectionTemplate::query()->count());
    }

    public function test_installa_campi_della_scheda_e_liste_di_controllo(): void
    {
        $this->postJson('/api/v1/aree-gioco/modello', ['prova' => 0])->assertOk();

        // I campi dell'attrezzo, su tutti e due i tipi di gioco
        foreach (ModelloAreeGioco::CODICI_ATTREZZO as $codice) {
            $chiavi = CustomField::query()->where('object_type_id', $this->tipo($codice)->id)->pluck('key');
            $this->assertContains('produttore', $chiavi);
            $this->assertContains('altezza_caduta_m', $chiavi);
            $this->assertContains('superficie_attenuazione', $chiavi);
            $this->assertContains('fascia_eta', $chiavi);
            $this->assertContains('installato_il', $chiavi);
        }

        // E i campi dell'area
        $chiaviArea = CustomField::query()->where('object_type_id', $this->tipo('S327552')->id)->pluck('key');
        $this->assertContains('recinzione', $chiaviArea);
        $this->assertContains('cartello_informativo', $chiaviArea);

        // Le tre ispezioni previste, con le loro domande e la loro cadenza
        $modelli = InspectionTemplate::query()->orderBy('code')->get()->keyBy('code');
        $this->assertSame(['GIOCO-ANN', 'GIOCO-FUN', 'GIOCO-VIS'], $modelli->keys()->all());
        $this->assertSame(7, $modelli['GIOCO-VIS']->frequency_days);
        $this->assertSame('area', $modelli['GIOCO-VIS']->target);
        $this->assertSame('asset', $modelli['GIOCO-FUN']->target);
        $this->assertSame(365, $modelli['GIOCO-ANN']->frequency_days);
        $this->assertStringContainsString('1176-7', (string) $modelli['GIOCO-VIS']->standard_ref);

        $domande = ChecklistItem::query()->where('template_id', $modelli['GIOCO-FUN']->id)->get();
        $this->assertGreaterThanOrEqual(8, $domande->count());
        // "Non applicabile" serve: non ogni domanda vale per ogni attrezzo
        $this->assertSame('ok_ko_na', $domande->first()->answer_type);
        // Un KO apre la non conformita' e chiede la foto
        $this->assertTrue((bool) $domande->first()->ko_creates_nc);
        $this->assertTrue((bool) $domande->first()->photo_required_on_ko);
    }

    public function test_rilanciare_non_duplica_e_non_sovrascrive(): void
    {
        $this->postJson('/api/v1/aree-gioco/modello', ['prova' => 0])->assertOk();

        // Il tecnico adatta: cambia un'etichetta e una domanda
        $campo = CustomField::query()->where('key', 'produttore')->firstOrFail();
        $campo->update(['label' => 'Ditta costruttrice']);
        $modello = InspectionTemplate::query()->where('code', 'GIOCO-VIS')->firstOrFail();
        $modello->update(['frequency_days' => 14]);

        $campiPrima = CustomField::query()->count();
        $secondo = $this->postJson('/api/v1/aree-gioco/modello', ['prova' => 0])->assertOk();

        $this->assertSame($campiPrima, CustomField::query()->count(), 'Nessun doppione');
        $this->assertSame(3, InspectionTemplate::query()->count());
        $this->assertSame('Ditta costruttrice', $campo->fresh()->label, 'L adattamento del tecnico resta');
        $this->assertSame(14, $modello->fresh()->frequency_days);
        // E lo dichiara, invece di far finta di aver fatto qualcosa
        $this->assertSame('presente', collect($secondo->json('data.campi'))->firstWhere('chiave', 'produttore')['stato']);
    }

    public function test_un_catalogo_senza_i_tipi_del_gioco_lo_dice(): void
    {
        CatalogObjectType::query()->whereIn('code', ['P214250', 'P214251', 'S327552'])->delete();

        $risposta = $this->postJson('/api/v1/aree-gioco/modello', ['prova' => 1])->assertOk();

        $this->assertCount(3, $risposta->json('data.mancanti'));
        $this->assertSame([], $risposta->json('data.campi'));
        // I modelli di ispezione non dipendono dal catalogo: quelli si fanno
        $this->assertCount(3, $risposta->json('data.modelli'));
    }

    public function test_i_campi_installati_si_compilano_sulla_scheda(): void
    {
        $this->postJson('/api/v1/aree-gioco/modello', ['prova' => 0])->assertOk();

        $area = $this->createArea($this->organizzazione);
        $id = $this->postJson('/api/v1/assets', [
            'area_id' => $area->id,
            'object_type_id' => $this->tipo('P214250')->id,
            'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/assets/{$id}", [
            'attributes' => [
                'produttore' => 'Giochi Sicuri srl',
                'altezza_caduta_m' => 1.6,
                'fascia_eta' => '3-6 anni',
                'superficie_attenuazione' => 'gomma colata',
            ],
        ])->assertOk();

        $scheda = $this->getJson("/api/v1/assets/{$id}")->assertOk()->json('data.attributes');
        $this->assertSame('Giochi Sicuri srl', $scheda['produttore']);
        $this->assertSame(1.6, (float) $scheda['altezza_caduta_m']);
    }

    public function test_serve_il_permesso_sul_catalogo_e_sui_lavori(): void
    {
        [$organizzazione, $tecnico] = $this->createTenantUser([], 'operatore');
        $this->actingAsTenantUser($tecnico);

        $this->postJson('/api/v1/aree-gioco/modello', ['prova' => 1])->assertForbidden();
    }
}
