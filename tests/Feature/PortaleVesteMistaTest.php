<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Client;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Veste mista del portale (decisione committente 23/09/2026): il nostro
 * impianto piu' le idee del portale di Mentana. Qui si collaudano le regole
 * che non devono tornare indietro: la fotografia di copertina esce solo se
 * caricata (e senza non si mette niente al suo posto), i numeri grandi
 * stanno subito sotto l'apertura, i recapiti del pie' di pagina escono solo
 * se compilati, nella scheda i pulsanti vengono prima della cronologia.
 */
class PortaleVesteMistaTest extends TestCase
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

    public function test_senza_fotografia_l_apertura_resta_su_fondo_chiaro(): void
    {
        $this->creaAlbero(['common_name' => 'Tiglio']);

        $html = $this->get('/comune/mentana')->assertOk()->getContent();

        // Nessuna immagine di riempimento: senza la foto del Comune
        // l'apertura e' quella di sempre, e l'indirizzo della copertina
        // risponde "non trovato" invece di servire un segnaposto. (Il foglio
        // di stile porta sempre le regole della variante con foto: si guarda
        // la classe della sezione, non il testo della pagina.)
        $this->assertStringContainsString('class="apertura sc-sezione sc-carta"', $html);
        $this->assertStringNotContainsString('class="apertura sc-sezione apertura-foto"', $html);
        $this->assertStringNotContainsString('/comune/mentana/copertina', $html);
        $this->get('/comune/mentana/copertina')->assertNotFound();
    }

    public function test_la_copertina_caricata_apre_la_home_e_si_toglie(): void
    {
        $this->creaAlbero(['common_name' => 'Tiglio']);

        $this->post("/api/v1/clients/{$this->client->id}/copertina", [
            'copertina' => UploadedFile::fake()->image('paese.jpg', 1200, 800),
        ])->assertOk()->assertJsonPath('data.has_cover', true);

        $percorso = $this->client->fresh()->public_profile['cover_path'];
        Storage::disk()->assertExists($percorso);

        // Servita come JPEG, gia' ricodificata al caricamento
        $risposta = $this->get('/comune/mentana/copertina');
        $risposta->assertOk();
        $this->assertSame('image/jpeg', $risposta->headers->get('Content-Type'));

        $html = $this->get('/comune/mentana')->assertOk()->getContent();
        $this->assertStringContainsString('class="apertura sc-sezione apertura-foto"', $html);
        $this->assertStringContainsString('/comune/mentana/copertina', $html);
        // Il campo del cartellino resta il primo oggetto della pagina anche
        // sopra la fotografia
        $this->assertLessThan(strpos($html, 'La mappa pubblica'), strpos($html, 'id="cartellino"'));

        // Una seconda foto prende il posto della prima, che sparisce dal disco
        $this->post("/api/v1/clients/{$this->client->id}/copertina", [
            'copertina' => UploadedFile::fake()->image('parco.jpg', 1600, 900),
        ])->assertOk();
        $nuovo = $this->client->fresh()->public_profile['cover_path'];
        Storage::disk()->assertExists($nuovo);
        if ($nuovo !== $percorso) {
            Storage::disk()->assertMissing($percorso);
        }

        $this->delete("/api/v1/clients/{$this->client->id}/copertina")
            ->assertOk()->assertJsonPath('data.has_cover', false);
        Storage::disk()->assertMissing($nuovo);
        $this->get('/comune/mentana/copertina')->assertNotFound();
        $this->assertStringNotContainsString('class="apertura sc-sezione apertura-foto"', $this->get('/comune/mentana')->getContent());
    }

    public function test_i_numeri_grandi_stanno_subito_sotto_l_apertura(): void
    {
        $this->creaAlbero(['common_name' => 'Tiglio', 'species' => 'Tilia cordata']);
        $this->creaAlbero(['common_name' => 'Platano', 'species' => 'Platanus x acerifolia']);

        $html = $this->get('/comune/mentana')->assertOk()->getContent();

        $tessere = strpos($html, 'class="tessere"');
        $this->assertNotFalse($tessere, 'I riquadri dei numeri non ci sono');
        $this->assertLessThan(strpos($html, 'La mappa pubblica'), $tessere, 'I numeri grandi vengono dopo la mappa');
        $this->assertLessThan($tessere, strpos($html, 'id="cartellino"'), 'Il campo del cartellino deve venire prima dei numeri');

        // Quello che sale nei riquadri non si ripete piu' sotto
        $this->assertSame(1, substr_count($html, 'Elementi censiti'));
        $this->assertStringContainsString('Variet', $html);

        // La fila ha tante colonne quanti sono i riquadri (qui tre: elementi,
        // alberi, varieta'), e con tutti i conteggi saliti nei riquadri la
        // pagina NON dice che il rilievo e' "in corso": due alberi ci sono
        $this->assertStringContainsString('style="--tessere: 3"', $html);
        $this->assertStringNotContainsString('Il rilievo sul territorio è in corso', $html);

        // L'apertura e' su due colonne: testo da una parte, campo dall'altra,
        // con il campo del cartellino sempre prima della mappa
        $this->assertStringContainsString('class="sc-contenitore apertura-griglia"', $html);
        $this->assertStringContainsString('class="apertura-cerca"', $html);
        $this->assertLessThan(strpos($html, 'La mappa pubblica'), strpos($html, 'id="cartellino"'));
    }

    public function test_senza_elementi_la_pagina_dice_che_il_rilievo_e_in_corso(): void
    {
        $html = $this->get('/comune/mentana')->assertOk()->getContent();

        $this->assertStringContainsString('Il rilievo sul territorio è in corso', $html);
        $this->assertStringNotContainsString('class="tessera"', $html);
    }

    public function test_i_recapiti_del_pie_di_pagina_escono_solo_se_compilati(): void
    {
        $this->creaAlbero(['common_name' => 'Tiglio']);

        $html = $this->get('/comune/mentana')->assertOk()->getContent();
        $this->assertStringNotContainsString('tel:', $html);
        $this->assertStringNotContainsString("Orari dell'ufficio", $html);
        $this->assertStringNotContainsString('Telefono', $html);

        $this->client->forceFill(['public_profile' => [
            'display_name' => 'Comune di Mentana',
            'address' => "Ufficio Verde pubblico\nPiazza del Municipio 1, 00013 Mentana",
            'contact_phone' => '06 9090 1234',
            'contact_pec' => 'protocollo@pec.comune.mentana.it',
            'opening_hours' => "Lunedi' e mercoledi' 9:00-12:30\nGiovedi' 15:00-17:00",
        ]])->save();

        $html = $this->get('/comune/mentana')->assertOk()->getContent();
        // Il numero si tocca: il collegamento porta le sole cifre
        $this->assertStringContainsString('href="tel:0690901234"', $html);
        $this->assertStringContainsString('06 9090 1234', $html);
        $this->assertStringContainsString('Piazza del Municipio 1, 00013 Mentana', $html);
        $this->assertStringContainsString('protocollo@pec.comune.mentana.it', $html);
        $this->assertStringContainsString("Orari dell'ufficio", $html);
        $this->assertStringContainsString('15:00-17:00', $html);
        // E il telefono entra anche fra i modi di segnalare un problema
        $this->assertStringContainsString('telefonare al', $html);
    }

    public function test_un_telefono_scritto_a_parole_viene_rifiutato(): void
    {
        $this->patchJson("/api/v1/clients/{$this->client->id}", [
            'public_profile' => ['contact_phone' => 'chiamare il vigile'],
        ])->assertUnprocessable()->assertJsonValidationErrors('public_profile.contact_phone');

        $this->patchJson("/api/v1/clients/{$this->client->id}", [
            'public_profile' => ['contact_phone' => '+39 06 9090 1234', 'address' => 'Piazza del Municipio 1'],
        ])->assertOk()->assertJsonPath('data.public_profile.contact_phone', '+39 06 9090 1234');
    }

    public function test_nella_scheda_i_pulsanti_vengono_prima_della_cronologia_e_del_dove(): void
    {
        $this->client->forceFill(['public_profile' => [
            'display_name' => 'Comune di Mentana', 'contact_email' => 'verde@comune.mentana.it',
        ]])->save();
        $this->creaAlbero(['common_name' => 'Tiglio selvatico', 'species' => 'Tilia cordata', 'height_m' => 14.5, 'dbh_cm' => 38]);

        $html = $this->get('/comune/mentana/elemento/MEN-0001')->assertOk()->getContent();
        $foglio = substr($html, strpos($html, '<article'));

        $cartellino = strpos($foglio, 'MEN-0001');
        $misure = strpos($foglio, 'Diametro del tronco');
        $raggiungi = strpos($foglio, "Raggiungi l'elemento");
        $dove = strpos($foglio, 'Dove si trova');

        $this->assertNotFalse($raggiungi, 'Manca il pulsante per raggiungere l\'elemento');
        $this->assertLessThan($misure, $cartellino, 'Il cartellino deve stare prima delle misure');
        $this->assertLessThan($raggiungi, $misure, 'Le misure devono stare prima dei pulsanti');
        $this->assertLessThan($dove, $raggiungi, 'I pulsanti devono venire prima di "Dove si trova"');
        // Le misure stanno su due colonne anche nel pannello stretto: la
        // regola sta nel foglio di stile della scheda, che precede l'articolo
        $this->assertStringContainsString('grid-template-columns: repeat(2, minmax(0, 1fr))', $html);
    }

    public function test_il_pannello_della_mappa_porta_alla_scheda_completa(): void
    {
        $this->creaAlbero(['common_name' => 'Tiglio']);

        $html = $this->get('/comune/mentana/mappa')->assertOk()->getContent();

        $this->assertStringContainsString('id="pannello-apri"', $html);
        $this->assertStringContainsString('Scheda completa', $html);
    }
}
