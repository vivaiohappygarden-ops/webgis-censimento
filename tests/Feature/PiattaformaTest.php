<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\Piattaforma\ConsolePiattaforma;
use App\Services\Sicurezza\DueFattori;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\Support\RaccoglitorePdf;
use Tests\Support\TsaDiProva;
use Tests\TestCase;

/**
 * Console della piattaforma: la qualifica si da' dal terminale; la console
 * vuole la verifica in due passaggi; elenca le organizzazioni con i numeri
 * per fatturare; ne crea di nuove pronte all'uso; sospende (nessuno entra,
 * gettoni via, portali spenti) e riattiva; l'assistenza entra con un utente
 * proprio, a tempo, e torna indietro.
 */
class PiattaformaTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        config(['inertia.pages.paths' => [resource_path('js/Pages')]]);
    }

    /** @return array{0: Organization, 1: User} il gestore della piattaforma, con la verifica in due passaggi accesa */
    private function gestore(): array
    {
        [$organizzazione, $utente] = $this->createTenantUser();
        $utente->forceFill(['is_platform_manager' => true])->save();
        $avvio = DueFattori::avvia($utente);
        DueFattori::conferma($utente, Totp::codice($avvio['segreto'], Totp::passo()));

        return [$organizzazione, $utente->refresh()];
    }

    public function test_la_qualifica_si_da_e_si_toglie_dal_terminale(): void
    {
        [, $utente] = $this->createTenantUser();

        $this->artisan('piattaforma:gestore', ['email' => $utente->email])->assertSuccessful();
        $this->assertTrue($utente->refresh()->is_platform_manager);

        $this->artisan('piattaforma:gestore', ['email' => $utente->email, '--togli' => true])->assertSuccessful();
        $this->assertFalse($utente->refresh()->is_platform_manager);

        $this->artisan('piattaforma:gestore', ['email' => 'nessuno@example.com'])->assertFailed();
    }

    public function test_senza_qualifica_o_senza_verifica_la_console_e_chiusa(): void
    {
        [, $amministratore] = $this->createTenantUser();
        $this->actingAsTenantUser($amministratore);
        $this->getJson('/api/v1/piattaforma/organizzazioni')->assertForbidden();
        $this->get('/piattaforma')->assertForbidden();

        // Gestore senza verifica in due passaggi: la pagina spiega, le chiamate no
        $amministratore->forceFill(['is_platform_manager' => true])->save();
        $this->getJson('/api/v1/piattaforma/organizzazioni')->assertForbidden()
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'verifica in due passaggi'));
        $this->get('/piattaforma')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Piattaforma')->where('dueFattoriAttiva', false));
    }

    public function test_la_console_elenca_le_organizzazioni_con_i_numeri(): void
    {
        [$mia, $gestore] = $this->gestore();

        // Un'altra organizzazione con un po' di patrimonio
        [$altra, $tecnico] = $this->createTenantUser(role: 'tecnico');
        User::factory()->create(['tenant_id' => $altra->id, 'is_active' => false]);
        $area = $this->createArea($altra);
        $tipoAlbero = $this->makeObjectType($altra, 'P', 'P103108');
        $tipoPanchina = $this->makeObjectType($altra, 'P');
        $this->actingAsTenantUser($tecnico);
        $albero = $this->postJson('/api/v1/assets', ['area_id' => $area->id, 'object_type_id' => $tipoAlbero->id, 'geometry' => $this->pointGeometry()])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/assets', ['area_id' => $area->id, 'object_type_id' => $tipoPanchina->id, 'geometry' => $this->pointGeometry(9.191, 45.465)])->assertCreated();
        $this->postJson("/api/v1/assets/{$albero}/photos", ['photo' => UploadedFile::fake()->image('a.jpg', 640, 480)])->assertCreated();

        $this->actingAsTenantUser($gestore);
        $elenco = collect($this->getJson('/api/v1/piattaforma/organizzazioni')->assertOk()->json('data'));
        $this->assertCount(2, $elenco);

        $riga = $elenco->firstWhere('id', $altra->id);
        $this->assertTrue($riga['is_active']);
        $this->assertSame(1, $riga['numeri']['utenti'], 'contano solo gli utenti attivi');
        $this->assertSame(2, $riga['numeri']['elementi']);
        $this->assertSame(1, $riga['numeri']['alberi']);
        $this->assertSame(1, $riga['numeri']['foto']);
        $this->assertGreaterThan(0, $riga['numeri']['spazio_byte']);
        $this->assertSame(1, $riga['numeri']['aree']);
        $this->assertSame(1, $riga['numeri']['committenti']);
        $this->assertSame(0, $riga['numeri']['portali']);
        // Le marche temporali sono per organizzazione: la console dice quante ne ha apposte e se ha un account suo
        $this->assertSame(0, $riga['numeri']['marche']);
        $this->assertFalse($riga['marche_configurate']);
        $this->assertNull($riga['marche_pacchetto']);
        $this->assertNull($riga['ultimo_accesso']);
        $this->assertNull($riga['assistenza']);

        $this->assertSame(0, $elenco->firstWhere('id', $mia->id)['numeri']['elementi']);
    }

    public function test_crea_una_nuova_organizzazione_pronta_all_uso(): void
    {
        [, $gestore] = $this->gestore();
        $this->actingAsTenantUser($gestore);

        $this->postJson('/api/v1/piattaforma/organizzazioni', [
            'name' => 'Comune di Prova', 'slug' => 'Comune di Prova', 'admin_email' => 'ufficio@prova.it',
        ])->assertUnprocessable()->assertJsonValidationErrors('slug');

        $risposta = $this->postJson('/api/v1/piattaforma/organizzazioni', [
            'name' => 'Comune di Prova', 'slug' => 'comune-di-prova', 'vat_number' => '00000000001',
            'admin_name' => 'Ufficio Verde', 'admin_email' => 'Ufficio@Prova.it',
        ])->assertCreated()->json('data');
        $this->assertSame('ufficio@prova.it', $risposta['admin_email']);
        $this->assertSame(16, strlen($risposta['temporary_password']));
        $this->assertSame(387, $risposta['catalogo']['object_types']);

        $organizzazione = Organization::query()->where('slug', 'comune-di-prova')->firstOrFail();
        $this->assertSame('00000000001', $organizzazione->vat_number);
        $admin = User::query()->withoutGlobalScopes()->where('tenant_id', $organizzazione->id)->where('email', 'ufficio@prova.it')->firstOrFail();
        app(PermissionRegistrar::class)->setPermissionsTeamId($organizzazione->id);
        $this->assertTrue($admin->hasRole('amministratore'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'piattaforma.organizzazione_creata', 'subject_id' => $organizzazione->id]);

        // Lo slug non si ripete e il gestore continua a lavorare nel suo contesto
        $this->postJson('/api/v1/piattaforma/organizzazioni', [
            'name' => 'Altro', 'slug' => 'comune-di-prova', 'admin_email' => 'altro@prova.it',
        ])->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->getJson('/api/v1/piattaforma/organizzazioni')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/users')->assertOk();

        // Il nuovo amministratore entra con la password provvisoria
        Auth::forgetGuards();
        app('auth')->shouldUse('web');
        $this->post('/login', ['email' => 'ufficio@prova.it', 'password' => $risposta['temporary_password']])->assertRedirect(route('oggi'));
    }

    public function test_la_sospensione_chiude_la_porta_e_la_riattivazione_la_riapre(): void
    {
        [$mia, $gestore] = $this->gestore();
        [$altra, $tecnico] = $this->createTenantUser(role: 'tecnico');
        $tecnico->createToken('telefono');

        $this->actingAsTenantUser($gestore);
        $this->postJson("/api/v1/piattaforma/organizzazioni/{$mia->id}/sospendi")->assertUnprocessable();
        $this->postJson("/api/v1/piattaforma/organizzazioni/{$altra->id}/sospendi", ['motivo' => 'canone non rinnovato'])->assertOk();

        $altra->refresh();
        $this->assertFalse($altra->is_active);
        $this->assertSame('canone non rinnovato', $altra->settings['piattaforma']['sospensione']['motivo']);
        $this->assertSame($gestore->email, $altra->settings['piattaforma']['sospensione']['da']);
        $this->assertSame(0, $tecnico->tokens()->count(), 'i gettoni API decadono subito');
        $this->assertDatabaseHas('audit_logs', ['action' => 'piattaforma.sospesa', 'subject_id' => $altra->id]);
        $riga = collect($this->getJson('/api/v1/piattaforma/organizzazioni')->json('data'))->firstWhere('id', $altra->id);
        $this->assertFalse($riga['is_active']);
        $this->assertSame('canone non rinnovato', $riga['sospensione']['motivo']);

        // Chi ha la password giusta si sente dire che l'organizzazione e' sospesa
        Auth::forgetGuards();
        app('auth')->shouldUse('web');
        $this->from('/login')->post('/login', ['email' => $tecnico->email, 'password' => 'password'])
            ->assertRedirect('/login')->assertSessionHasErrors(['email' => ConsolePiattaforma::MESSAGGIO_SOSPESA]);
        $this->assertGuest();
        $this->postJson('/api/v1/auth/login', ['email' => $tecnico->email, 'password' => 'password'])
            ->assertUnprocessable()->assertJsonPath('errors.email.0', ConsolePiattaforma::MESSAGGIO_SOSPESA);
        // Con la password sbagliata il messaggio resta quello generico
        $this->from('/login')->post('/login', ['email' => $tecnico->email, 'password' => 'errata'])
            ->assertSessionHasErrors(['email' => 'Credenziali non valide.']);

        // La riattivazione riapre
        $this->actingAsTenantUser($gestore);
        $this->postJson("/api/v1/piattaforma/organizzazioni/{$altra->id}/riattiva")->assertOk();
        $this->assertTrue($altra->refresh()->is_active);
        $this->assertArrayNotHasKey('sospensione', $altra->settings['piattaforma']);
        Auth::forgetGuards();
        app('auth')->shouldUse('web');
        $this->post('/login', ['email' => $tecnico->email, 'password' => 'password'])->assertRedirect(route('oggi'));
    }

    public function test_una_sessione_aperta_si_chiude_quando_l_organizzazione_viene_sospesa(): void
    {
        [$altra, $tecnico] = $this->createTenantUser(role: 'tecnico');
        [, $gestore] = $this->gestore();

        $this->post('/login', ['email' => $tecnico->email, 'password' => 'password'])->assertRedirect(route('oggi'));
        Auth::forgetGuards();
        $this->get('/oggi')->assertOk();

        app(ConsolePiattaforma::class)->sospendi($altra, $gestore, null);

        Auth::forgetGuards();
        $this->get('/oggi')->assertRedirect(route('login'))->assertSessionHasErrors('email');
        Auth::forgetGuards();
        $this->get('/oggi')->assertRedirect(route('login'));
    }

    public function test_l_assistenza_entra_con_il_proprio_utente_e_torna_indietro(): void
    {
        [$mia, $gestore] = $this->gestore();
        [$altra] = $this->createTenantUser(role: 'tecnico');
        $this->actingAs($gestore);

        $this->post("/piattaforma/assistenza/{$mia->id}")->assertSessionHasErrors('organizzazione');
        $this->post("/piattaforma/assistenza/{$altra->id}")->assertRedirect(route('oggi'));

        $assistenza = User::query()->withoutGlobalScopes()->where('tenant_id', $altra->id)
            ->where('email', ConsolePiattaforma::emailAssistenza($altra))->firstOrFail();
        $this->assertAuthenticatedAs($assistenza);
        $this->assertSame(ConsolePiattaforma::NOME_ASSISTENZA, $assistenza->name);
        $this->assertTrue($assistenza->is_active);
        app(PermissionRegistrar::class)->setPermissionsTeamId($altra->id);
        $this->assertTrue($assistenza->hasRole('amministratore'));
        $this->assertSame($gestore->email, $assistenza->settings['assistenza']['gestore']);
        // La nota sta solo nel registro di chi gestisce la piattaforma:
        // nell'organizzazione assistita non resta traccia (decisione 27/09/2026)
        $this->assertDatabaseHas('audit_logs', ['action' => 'piattaforma.assistenza_inizio', 'tenant_id' => $mia->id, 'user_id' => $gestore->id]);
        $this->assertDatabaseMissing('audit_logs', ['tenant_id' => $altra->id, 'action' => 'piattaforma.assistenza_inizio']);
        $this->assertDatabaseMissing('audit_logs', ['tenant_id' => $altra->id, 'user_id' => $assistenza->id]);

        // Si lavora nell'altra organizzazione, con la fascia che lo ricorda
        Auth::forgetGuards();
        $this->get('/oggi')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('assistenza.organizzazione', $altra->name)
            ->where('auth.user.tenant_id', $altra->id));
        // ... e l'utente di assistenza non compare nella pagina Utenti dell'organizzazione
        Auth::forgetGuards();
        $this->getJson('/api/v1/users')->assertOk()->assertJsonMissing(['email' => $assistenza->email]);
        // Nella console figura l'assistenza in corso
        $this->assertNotNull(collect(app(ConsolePiattaforma::class)->organizzazioni())->firstWhere('id', $altra->id)['assistenza']);

        // Si termina: si torna gestore, l'utente di assistenza si spegne
        // (la chiamata API di prima ha reso predefinito il guard sanctum,
        // che tiene in memoria l'utente della richiesta: si riparte dal web)
        Auth::forgetGuards();
        app('auth')->shouldUse('web');
        $this->post('/piattaforma/assistenza/termina')->assertRedirect(route('piattaforma'));
        Auth::forgetGuards();
        $this->assertAuthenticatedAs($gestore);
        $this->assertFalse($assistenza->refresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['action' => 'piattaforma.assistenza_fine', 'tenant_id' => $mia->id, 'user_id' => $gestore->id]);
        $this->assertSame(0, \App\Models\AuditLog::query()->withoutGlobalScopes()->where('tenant_id', $altra->id)->count(), 'Nel registro dell\'organizzazione assistita non deve restare niente');
        Auth::forgetGuards();
        $this->get('/piattaforma')->assertOk()->assertInertia(fn (Assert $p) => $p->where('assistenza', null));

        // Senza assistenza in corso il pulsante non fa niente di strano
        Auth::forgetGuards();
        $this->post('/piattaforma/assistenza/termina')->assertForbidden();
    }

    public function test_esci_durante_l_assistenza_la_chiude_senza_lasciare_traccia(): void
    {
        [$mia, $gestore] = $this->gestore();
        [$altra] = $this->createTenantUser(role: 'tecnico');
        $this->actingAs($gestore);
        $this->post("/piattaforma/assistenza/{$altra->id}")->assertRedirect(route('oggi'));
        $assistenza = User::query()->withoutGlobalScopes()->where('tenant_id', $altra->id)
            ->where('email', ConsolePiattaforma::emailAssistenza($altra))->firstOrFail();

        Auth::forgetGuards();
        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertFalse($assistenza->refresh()->is_active);
        $this->assertSame(0, \App\Models\AuditLog::query()->withoutGlobalScopes()->where('tenant_id', $altra->id)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'piattaforma.assistenza_fine', 'tenant_id' => $mia->id, 'user_id' => $gestore->id]);
    }

    public function test_l_assistenza_scade_da_sola_e_non_entra_in_un_organizzazione_sospesa(): void
    {
        [, $gestore] = $this->gestore();
        [$altra] = $this->createTenantUser(role: 'tecnico');
        $this->actingAs($gestore);

        $this->post("/piattaforma/assistenza/{$altra->id}")->assertRedirect(route('oggi'));
        Auth::forgetGuards();
        $this->get('/oggi')->assertOk();

        $this->travel(ConsolePiattaforma::ORE_ASSISTENZA + 1)->hours();
        Auth::forgetGuards();
        $this->get('/oggi')->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->assertGuest();

        // Sospesa: prima si riattiva
        $this->actingAs($gestore);
        app(ConsolePiattaforma::class)->sospendi($altra, $gestore, null);
        $this->post("/piattaforma/assistenza/{$altra->id}")->assertSessionHasErrors('organizzazione');
        $this->assertAuthenticatedAs($gestore);
    }

    public function test_l_assistenza_entra_anche_dove_la_verifica_e_obbligatoria_per_tutti(): void
    {
        [, $gestore] = $this->gestore();
        [$altra] = $this->createTenantUser(role: 'tecnico');
        $altra->forceFill(['settings' => ['sicurezza' => ['due_fattori' => 'tutti']]])->save();
        $this->actingAs($gestore);

        $this->post("/piattaforma/assistenza/{$altra->id}")->assertRedirect(route('oggi'));
        // L'utente di assistenza non ha un telefono: non viene mandato a
        // "Il mio accesso", il gestore la verifica l'ha gia' superata
        Auth::forgetGuards();
        $this->get('/oggi')->assertOk();
        Auth::forgetGuards();
        $this->withHeader('Referer', 'http://localhost/oggi')->getJson('/api/v1/users')->assertOk();
    }

    public function test_il_pacchetto_di_marche_si_assegna_dalla_console_e_si_esaurisce_da_solo(): void
    {
        if (! TsaDiProva::disponibile()) {
            $this->markTestSkipped('openssl non disponibile.');
        }
        Storage::fake('local');
        $this->app->instance(\App\Services\Pdf\PdfRenderer::class, new RaccoglitorePdf);
        config(['marche.url' => 'https://tsa.prova.test/tsr']);
        \Illuminate\Support\Facades\Http::fake([
            'tsa.prova.test/*' => fn ($r) => \Illuminate\Support\Facades\Http::response(TsaDiProva::rispondi($r->body()), 200),
            '*' => \Illuminate\Support\Facades\Http::response('', 404),
        ]);
        [$miaOrganizzazione, $gestore] = $this->gestore();
        [$cliente, $amministratoreCliente] = $this->createTenantUser();

        // DAMA vende un pacchetto di una marca e imposta il proprio account per il cliente, dalla console
        $this->actingAsTenantUser($gestore);
        $this->putJson("/api/v1/piattaforma/organizzazioni/{$cliente->id}/marche", ['pacchetto' => 1, 'utente' => 'dama-marche', 'password' => 'segreta'])
            ->assertOk()->assertJsonPath('data.marche_pacchetto', 1)->assertJsonPath('data.marche_configurate', true)->assertJsonPath('data.restanti', 1);
        $riga = collect($this->getJson('/api/v1/piattaforma/organizzazioni')->json('data'))->firstWhere('id', $cliente->id);
        $this->assertSame([1, true, 'da*******he'], [$riga['marche_pacchetto'], $riga['marche_configurate'], $riga['marche_utente']]);
        // Il registro resta nel tenant del gestore, non in quello del cliente
        $this->assertDatabaseHas('audit_logs', ['action' => 'piattaforma.marche', 'tenant_id' => $miaOrganizzazione->id, 'subject_id' => $cliente->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'piattaforma.marche', 'tenant_id' => $cliente->id]);

        // Il cliente vede il pacchetto, non lo cambia, lo consuma e poi la pagina rimanda all'assistenza
        $this->actingAsTenantUser($amministratoreCliente);
        $stato = $this->getJson('/api/v1/documenti/marche')->assertOk()->json('stato');
        $this->assertSame(['attiva' => true, 'totale' => 0, 'pacchetto' => 1, 'restanti' => 1, 'esaurito' => false],
            collect($stato)->only(['attiva', 'totale', 'pacchetto', 'restanti', 'esaurito'])->all());
        $this->putJson('/api/v1/documenti/marche/configurazione', ['url' => 'https://tsa.prova.test/tsr', 'utente' => 'dama-marche', 'pacchetto' => 500])
            ->assertOk()->assertJsonPath('data.pacchetto', 1);
        $this->postJson('/api/v1/documenti/marche', ['tipo' => 'registro_fitosanitari', 'parametri' => ['anno' => 2026]])->assertCreated();
        $messaggio = $this->postJson('/api/v1/documenti/marche', ['tipo' => 'registro_fitosanitari', 'parametri' => ['anno' => 2026]])
            ->assertUnprocessable()->json('errors.marca.0');
        $this->assertStringContainsString('esaurito (1 su 1)', $messaggio);
        $this->assertStringContainsString('assistenza', $messaggio);
        $stato = $this->getJson('/api/v1/documenti/marche')->json('stato');
        $this->assertSame([1, 0, true], [$stato['totale'], $stato['restanti'], $stato['esaurito']]);

        // Tolte le credenziali, il pacchetto assegnato resta; la console lo rinnova
        $this->deleteJson('/api/v1/documenti/marche/configurazione')->assertOk()->assertJsonPath('data.pacchetto', 1);
        $this->actingAsTenantUser($gestore);
        $this->putJson("/api/v1/piattaforma/organizzazioni/{$cliente->id}/marche", ['pacchetto' => 3])->assertOk()->assertJsonPath('data.restanti', 2);
        $this->assertSame(1, collect($this->getJson('/api/v1/piattaforma/organizzazioni')->json('data'))->firstWhere('id', $cliente->id)['numeri']['marche']);
        // Un pacchetto senza numero e' "nessun tetto"; un valore fuori misura si rifiuta
        $this->putJson("/api/v1/piattaforma/organizzazioni/{$cliente->id}/marche", ['pacchetto' => null])->assertOk()->assertJsonPath('data.marche_pacchetto', null);
        $this->putJson("/api/v1/piattaforma/organizzazioni/{$cliente->id}/marche", ['pacchetto' => -1])->assertUnprocessable();
    }

    public function test_le_note_della_piattaforma_restano_solo_nella_console(): void
    {
        [, $gestore] = $this->gestore();
        [$altra] = $this->createTenantUser(role: 'tecnico');
        $this->actingAsTenantUser($gestore);

        $this->patchJson("/api/v1/piattaforma/organizzazioni/{$altra->id}", ['note' => 'Canone annuale, referente geom. Bianchi'])->assertOk();
        $this->assertSame('Canone annuale, referente geom. Bianchi', $altra->refresh()->settings['piattaforma']['note']);
        $riga = collect($this->getJson('/api/v1/piattaforma/organizzazioni')->json('data'))->firstWhere('id', $altra->id);
        $this->assertSame('Canone annuale, referente geom. Bianchi', $riga['note']);
    }
}
