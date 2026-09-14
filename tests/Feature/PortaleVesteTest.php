<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Client;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Veste del portale pubblico: le decisioni che non devono tornare indietro.
 *
 * Il registro e' quello istituzionale scelto dal committente il 14/09/2026.
 * Qui non si collauda il gusto - quello si guarda nel browser - ma le poche
 * regole che, se saltassero, farebbero danno: la ricerca sotto la piega, un
 * carattere chiesto a Google, un disegno al posto di una fotografia che non
 * c'e', il testo che torna piccolo sul telefono.
 */
class PortaleVesteTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private Organization $organization;

    private $user;

    private Area $area;

    private Client $client;

    private $type;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        [$this->organization, $this->user] = $this->createTenantUser();
        $this->area = $this->createArea($this->organization, ['name' => 'Parco Cinque Pini']);
        $this->client = Client::withoutGlobalScopes()->where('tenant_id', $this->organization->id)->firstOrFail();
        $this->client->forceFill([
            'name' => 'Comune di Mentana',
            'public_slug' => 'mentana',
            'public_enabled' => true,
            'label_prefix' => 'MEN',
            'public_profile' => ['display_name' => 'Comune di Mentana'],
        ])->save();
        $this->type = $this->makeObjectType($this->organization, 'P', 'P103108');
        $this->actingAsTenantUser($this->user);
    }

    private function creaAlbero(array $albero = []): string
    {
        $id = $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->type->id,
            'geometry' => $this->pointGeometry(),
            'surveyed_at' => '2026-05-12',
        ])->assertCreated()->json('data.id');

        if ($albero !== []) {
            $this->patchJson("/api/v1/assets/{$id}", ['tree' => $albero])->assertOk();
        }

        return $id;
    }

    public function test_il_campo_del_cartellino_viene_prima_di_tutto_il_resto(): void
    {
        $this->creaAlbero(['common_name' => 'Tiglio']);

        $html = $this->get('/comune/mentana')->assertOk()->getContent();

        // Chi apre il portale ha in mano un numero letto sul cartellino: il
        // campo sta nel primo schermo, prima della mappa e prima di qualunque
        // racconto. Nella versione precedente stava a 971px, cioe' sotto la
        // piega di uno schermo da 900.
        $campo = strpos($html, 'id="cartellino"');
        $mappa = strpos($html, 'La mappa pubblica');
        // non la voce di menu, che sta in testata: la sezione vera
        $racconto = strpos($html, 'Chi controlla, che cosa cambia lo stato');

        $this->assertNotFalse($campo, 'Il campo del cartellino non c\'e\' piu\'');
        $this->assertLessThan($mappa, $campo, 'La mappa viene prima del campo di ricerca');
        $this->assertLessThan($racconto, $mappa, 'Il racconto viene prima della mappa');
    }

    public function test_un_solo_carattere_e_nessuna_richiesta_a_terzi(): void
    {
        $this->creaAlbero(['common_name' => 'Tiglio']);
        $pagine = ['/comune/mentana', '/comune/mentana/mappa', '/comune/mentana/privacy'];

        foreach ($pagine as $pagina) {
            $html = $this->get($pagina)->assertOk()->getContent();

            // Un portale civico non manda il browser del cittadino a chiedere
            // i caratteri a un terzo: per il GDPR sarebbe un trasferimento di
            // dati, ed e' la ragione per cui l'informativa puo' dire che non
            // c'e' niente da accettare
            $this->assertStringNotContainsString('fonts.googleapis.com', $html, "Carattere esterno in {$pagina}");
            $this->assertStringNotContainsString('fonts.gstatic.com', $html, "Carattere esterno in {$pagina}");
        }

        $html = $this->get('/comune/mentana')->assertOk()->getContent();

        // Una sola famiglia, e sta sul nostro server
        $this->assertStringNotContainsString('Fraunces', $html);
        $this->assertStringContainsString("/portale/font/inter-tondo-latin.woff2", $html);
        $this->assertStringContainsString("/portale/font/inter-corsivo-latin.woff2", $html);

        // I file dichiarati esistono davvero: un carattere rinominato o
        // cancellato non si vede finche' non lo apre un cittadino
        preg_match_all("#/portale/font/([a-z0-9.-]+\.woff2)#", $html, $trovati);
        $this->assertNotEmpty($trovati[1]);
        foreach (array_unique($trovati[1]) as $file) {
            $this->assertFileExists(public_path('portale/font/'.$file));
        }
    }

    public function test_senza_fotografia_la_scheda_non_disegna_una_pianta(): void
    {
        $this->creaAlbero(['common_name' => 'Tiglio selvatico', 'species' => 'Tilia cordata']);

        $html = $this->get('/comune/mentana/elemento/MEN-0001')->assertOk()->getContent();

        // Prima al posto della fotografia mancante c'era il disegno di una
        // pianta qualsiasi, alto fino a 560px: mezzo schermo di telefono da
        // scorrere prima di leggere la specie. Un disegno che non e' quella
        // pianta non e' un dato.
        $this->assertStringNotContainsString('class="disegno"', $html);
        $this->assertStringContainsString('pubblicata una fotografia', $html);

        // La specie viene subito dopo il numero del cartellino. Si guarda
        // dentro il foglio della scheda: nel titolo della finestra i due nomi
        // stanno nell'ordine opposto, ed e' giusto cosi'
        $foglio = substr($html, strpos($html, '<article'));
        $cartellino = strpos($foglio, 'MEN-0001');
        $specie = strpos($foglio, 'Tiglio selvatico');
        $this->assertNotFalse($specie);
        $this->assertLessThan($specie, $cartellino);
    }

    public function test_il_testo_non_scende_sotto_i_diciassette_pixel(): void
    {
        $html = $this->get('/comune/mentana')->assertOk()->getContent();

        // Il corpo e' dichiarato due volte: prima in pixel secchi, poi con
        // clamp(). Un browser che non conosce clamp() tiene il primo valore,
        // e quel primo valore non deve tornare piccolo: il difetto che il
        // committente voleva battere era "sul telefono si legge male".
        $this->assertStringContainsString('--t-corpo: 17px;', $html);
        $this->assertStringContainsString('--t-corpo: clamp(17px', $html);
    }

    public function test_i_quattro_colori_di_stato_non_prendono_la_tinta_del_comune(): void
    {
        $this->client->forceFill(['public_profile' => [
            'display_name' => 'Comune di Mentana', 'color' => '#7f1d1d',
        ]])->save();

        $html = $this->get('/comune/mentana')->assertOk()->getContent();

        // La tinta la sceglie il Comune; il codice dei quattro stati no, o lo
        // stesso pallino direbbe cose diverse da un Comune all'altro
        foreach (\App\Services\Portale\PortalState::COLORI as $stato => $colore) {
            $this->assertStringContainsString("--stato-{$stato}: {$colore};", $html);
        }
    }
}
