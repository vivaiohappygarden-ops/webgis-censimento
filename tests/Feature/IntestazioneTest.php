<?php

namespace Tests\Feature;

use App\Mail\DailyDigestMail;
use App\Models\Organization;
use App\Models\User;
use App\Services\Pdf\PdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\Support\RaccoglitorePdf;
use Tests\TestCase;

/**
 * L'organizzazione affittata sulla piattaforma e' del cliente in tutto: sui
 * suoi documenti compaiono la sua ragione sociale, i suoi recapiti e il suo
 * logo, regolati da lei, e niente della piattaforma.
 */
class IntestazioneTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organizzazione;

    private $utente;

    private RaccoglitorePdf $stampe;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        [$this->organizzazione, $this->utente] = $this->createTenantUser();
        $this->actingAsTenantUser($this->utente);
        $this->stampe = new RaccoglitorePdf;
        $this->app->instance(PdfRenderer::class, $this->stampe);
        config(['services.osm.tiles_enabled' => false]);
    }

    private function schedaElemento(?Organization $organizzazione = null): string
    {
        $organizzazione ??= $this->organizzazione;
        $area = $this->createArea($organizzazione);
        $tipo = $this->makeObjectType($organizzazione, 'P', 'P103108');

        return $this->postJson('/api/v1/assets', ['area_id' => $area->id, 'object_type_id' => $tipo->id, 'geometry' => $this->pointGeometry()])
            ->assertCreated()->json('data.id');
    }

    public function test_l_intestazione_si_imposta_per_organizzazione_ed_esce_in_cima_ai_documenti(): void
    {
        // Di serie: solo il nome, niente etichette vuote
        $prima = $this->getJson('/api/v1/intestazione')->assertOk()->json('data');
        $this->assertSame($this->organizzazione->name, $prima['nome']);
        $this->assertNull($prima['logo_url']);

        $this->putJson('/api/v1/intestazione', ['nome' => 'Savet Servizi del Verde S.r.l.', 'partita_iva' => '01234567890',
            'indirizzo' => 'via dei Giardini 3', 'comune' => '00012 Guidonia Montecelio (RM)', 'telefono' => '0774 000000',
            'pec' => 'savet@pec.it', 'sito' => 'www.savet.it', 'email' => ''])->assertOk()
            ->assertJsonPath('data.nome', 'Savet Servizi del Verde S.r.l.')->assertJsonPath('data.pec', 'savet@pec.it')->assertJsonPath('data.email', null);
        $organizzazione = Organization::query()->findOrFail($this->organizzazione->id);
        $this->assertSame(['Savet Servizi del Verde S.r.l.', '01234567890', 'via dei Giardini 3'], [$organizzazione->name, $organizzazione->vat_number, $organizzazione->branding['indirizzo']]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'intestazione.aggiornata', 'subject_id' => $organizzazione->id]);

        // Il nome arriva anche nel menu (prop condivisa)
        $this->get('/utenti')->assertOk()->assertInertia(fn ($p) => $p->where('auth.user.organization.name', 'Savet Servizi del Verde S.r.l.'));

        // Sulla scheda dell'elemento e sul registro fitosanitari: nome, sede, recapiti e dati fiscali
        $asset = $this->schedaElemento();
        $this->get("/api/v1/assets/{$asset}/pdf")->assertOk();
        $scheda = $this->stampe->html['pdf.asset'];
        $this->assertStringContainsString('Savet Servizi del Verde S.r.l.', $scheda);
        $this->assertStringContainsString('via dei Giardini 3 - 00012 Guidonia Montecelio (RM)', $scheda);
        $this->assertStringContainsString('tel. 0774 000000 - PEC savet@pec.it - www.savet.it', $scheda);
        $this->assertStringContainsString('P. IVA 01234567890', $scheda);
        $this->assertStringNotContainsString('C.F.', $scheda);
        $this->get('/api/v1/phyto-treatments/register-pdf?year=2026')->assertOk();
        $this->assertStringContainsString('PEC savet@pec.it', $this->stampe->html['pdf.phyto-register']);

        // Un'altra organizzazione stampa la sua intestazione, non questa
        [$altra, $altroUtente] = $this->createTenantUser();
        $this->actingAsTenantUser($altroUtente);
        $altroAsset = $this->schedaElemento($altra);
        $this->get("/api/v1/assets/{$altroAsset}/pdf")->assertOk();
        $this->assertStringContainsString($altra->name, $this->stampe->html['pdf.asset']);
        $this->assertStringNotContainsString('Savet', $this->stampe->html['pdf.asset']);
        $this->assertStringNotContainsString('P. IVA', $this->stampe->html['pdf.asset']);
    }

    public function test_il_logo_si_carica_ricodificato_esce_nei_documenti_e_si_toglie(): void
    {
        $this->post('/api/v1/intestazione/logo', ['logo' => UploadedFile::fake()->image('logo.jpg', 900, 300)])
            ->assertOk()->assertJsonPath('data.logo_url', fn ($u) => str_starts_with($u, '/api/v1/intestazione/logo?v='));
        $percorso = Organization::query()->findOrFail($this->organizzazione->id)->branding['logo_path'];
        Storage::disk('local')->assertExists($percorso);
        $this->assertStringStartsWith("intestazioni/{$this->organizzazione->id}/", $percorso);
        $this->assertSame('image/png', $this->get('/api/v1/intestazione/logo')->assertOk()->headers->get('Content-Type'));
        [$larghezza] = getimagesizefromstring(Storage::disk('local')->get($percorso));
        $this->assertLessThanOrEqual(600, $larghezza, 'il logo viene ridotto');

        $asset = $this->schedaElemento();
        $this->get("/api/v1/assets/{$asset}/pdf")->assertOk();
        $this->assertStringContainsString('data:image/png;base64,', $this->stampe->html['pdf.asset']);

        // Un logo nuovo prende il posto del vecchio, senza lasciare file
        $this->post('/api/v1/intestazione/logo', ['logo' => UploadedFile::fake()->image('nuovo.png', 200, 200)])->assertOk();
        Storage::disk('local')->assertMissing($percorso);
        $this->assertCount(1, Storage::disk('local')->allFiles("intestazioni/{$this->organizzazione->id}"));

        // Il logo e' della propria organizzazione: un'altra non lo vede
        [, $altroUtente] = $this->createTenantUser();
        $this->actingAsTenantUser($altroUtente);
        $this->get('/api/v1/intestazione/logo')->assertNotFound();

        $this->actingAsTenantUser($this->utente);
        $this->deleteJson('/api/v1/intestazione/logo')->assertOk()->assertJsonPath('data.logo_url', null);
        $this->assertSame([], Storage::disk('local')->allFiles("intestazioni/{$this->organizzazione->id}"));
        $this->get('/api/v1/intestazione/logo')->assertNotFound();
    }

    public function test_solo_chi_gestisce_gli_utenti_cambia_l_intestazione_e_i_campi_si_controllano(): void
    {
        $this->putJson('/api/v1/intestazione', ['nome' => ''])->assertUnprocessable()->assertJsonValidationErrors('nome');
        $this->putJson('/api/v1/intestazione', ['nome' => 'Studio', 'pec' => 'non-una-pec'])->assertUnprocessable()->assertJsonValidationErrors('pec');
        $this->post('/api/v1/intestazione/logo', ['logo' => UploadedFile::fake()->create('logo.pdf', 100, 'application/pdf')])->assertUnprocessable();

        $this->postJson('/api/v1/roles', ['nome' => 'Tecnico senza utenti', 'permessi' => ['assets.view', 'assets.update']])->assertCreated();
        $tecnico = User::factory()->create(['tenant_id' => $this->organizzazione->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->organizzazione->id);
        $tecnico->assignRole('Tecnico senza utenti');
        $this->actingAsTenantUser($tecnico->fresh());
        $this->getJson('/api/v1/intestazione')->assertForbidden();
        $this->putJson('/api/v1/intestazione', ['nome' => 'Altro nome'])->assertForbidden();
        $this->post('/api/v1/intestazione/logo', ['logo' => UploadedFile::fake()->image('logo.png')])->assertForbidden();
        $this->assertSame($this->organizzazione->name, Organization::query()->findOrFail($this->organizzazione->id)->name);
    }

    public function test_il_riepilogo_email_parte_a_nome_dell_organizzazione(): void
    {
        $organizzazione = Organization::query()->findOrFail($this->organizzazione->id);
        $busta = (new DailyDigestMail($organizzazione, ['date' => now()->toDateString(), 'sections' => []]))->envelope();
        $this->assertSame($organizzazione->name, $busta->from->name);
        $this->assertSame(config('mail.from.address'), $busta->from->address);
        $this->assertStringContainsString($organizzazione->name, $busta->subject);
    }
}
