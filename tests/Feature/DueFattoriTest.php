<?php

namespace Tests\Feature;

use App\Http\Controllers\Web\WebAuthController;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Sicurezza\DueFattori;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Verifica in due passaggi: il codice a tempo dell'app del telefono oltre
 * alla password. L'utente la attiva dalla sua pagina e riceve i codici di
 * recupero; l'accesso web e quello con gettone la chiedono; un codice vale
 * una volta sola; la regola dell'organizzazione puo' obbligarla, e chi e'
 * obbligato senza averla trova solo la pagina dove attivarla;
 * l'amministratore la azzera a chi ha perso il telefono.
 */
class DueFattoriTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['inertia.pages.paths' => [resource_path('js/Pages')]]);
    }

    /** Accende la verifica per l'utente come farebbe la pagina, senza passare dal browser. */
    private function accendi(User $user): array
    {
        $avvio = DueFattori::avvia($user);
        $codici = DueFattori::conferma($user, Totp::codice($avvio['segreto'], Totp::passo()));
        $this->assertNotNull($codici);

        return [$avvio['segreto'], $codici];
    }

    public function test_i_codici_a_tempo_seguono_la_rfc_6238(): void
    {
        $segreto = Totp::base32('12345678901234567890');
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $segreto);
        $this->assertSame('12345678901234567890', Totp::decodifica($segreto));

        // I vettori di prova della RFC 6238 (SHA1), le ultime sei delle otto cifre
        $this->assertSame('287082', Totp::codice($segreto, Totp::passo(59)));
        $this->assertSame('081804', Totp::codice($segreto, Totp::passo(1111111109)));
        $this->assertSame('050471', Totp::codice($segreto, Totp::passo(1111111111)));
        $this->assertSame('005924', Totp::codice($segreto, Totp::passo(1234567890)));
        $this->assertSame('279037', Totp::codice($segreto, Totp::passo(2000000000)));

        // Finestra di un passo (l'orologio del telefono puo' essere indietro)
        // e rifiuto di un codice gia' speso
        $this->assertSame(1, Totp::verifica($segreto, '287082', 1, null, 59));
        $this->assertSame(1, Totp::verifica($segreto, '287 082', 1, null, 89));
        $this->assertNull(Totp::verifica($segreto, '287082', 1, null, 119));
        $this->assertNull(Totp::verifica($segreto, '287082', 1, 1, 59));
        $this->assertNull(Totp::verifica($segreto, '000000', 1, null, 59));
        $this->assertNull(Totp::verifica($segreto, '28708', 1, null, 59));

        $this->assertSame(
            'otpauth://totp/WebGIS%20Censimento:a%40b.it?secret='.$segreto.'&issuer=WebGIS%20Censimento&algorithm=SHA1&digits=6&period=30',
            Totp::uri($segreto, 'WebGIS Censimento', 'a@b.it'),
        );
    }

    public function test_l_utente_la_attiva_dalla_sua_pagina_e_riceve_i_codici_di_recupero(): void
    {
        [, $user] = $this->createTenantUser();
        $this->actingAsTenantUser($user);
        $user->createToken('telefono');

        $this->postJson('/api/v1/profilo/due-fattori/avvia', ['password' => 'sbagliata'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $avvio = $this->postJson('/api/v1/profilo/due-fattori/avvia', ['password' => 'password'])->assertOk()->json('data');
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $avvio['segreto']);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $avvio['qr']);
        $this->assertStringContainsString('secret='.$avvio['segreto'], $avvio['uri']);
        $this->assertStringContainsString(rawurlencode($user->email), $avvio['uri']);
        $this->assertSame(trim(chunk_split($avvio['segreto'], 4, ' ')), $avvio['segreto_leggibile']);

        // Avviata ma non confermata: l'accesso non cambia
        $stato = $this->getJson('/api/v1/profilo/sicurezza')->assertOk()->json('data.due_fattori');
        $this->assertFalse($stato['attiva']);
        $this->assertTrue($stato['in_attesa']);

        $this->postJson('/api/v1/profilo/due-fattori/conferma', ['codice' => '000000'])
            ->assertUnprocessable()->assertJsonValidationErrors('codice');
        $this->assertFalse($user->refresh()->mfa_enabled);

        $codici = $this->postJson('/api/v1/profilo/due-fattori/conferma', ['codice' => Totp::codice($avvio['segreto'], Totp::passo())])
            ->assertOk()->json('data.codici_recupero');
        $this->assertCount(DueFattori::CODICI_RECUPERO, $codici);
        foreach ($codici as $codice) {
            $this->assertMatchesRegularExpression('/^[A-Z2-9]{5}-[A-Z2-9]{5}$/', $codice);
        }

        $stato = $this->getJson('/api/v1/profilo/sicurezza')->assertOk()->json('data.due_fattori');
        $this->assertTrue($stato['attiva']);
        $this->assertSame(DueFattori::CODICI_RECUPERO, $stato['codici_recupero']);
        $this->assertNotNull($stato['attiva_dal']);

        // I gettoni rilasciati prima non sono passati dal secondo passaggio
        $this->assertSame(0, $user->tokens()->count());
        // Il segreto non esce mai e nella tabella e' cifrato; i codici sono solo impronte
        $user->refresh();
        $this->assertArrayNotHasKey('mfa_secret', $user->toArray());
        $this->assertArrayNotHasKey('mfa_recovery_codes', $user->toArray());
        $this->assertNotSame($avvio['segreto'], DB::table('users')->where('id', $user->id)->value('mfa_secret'));
        $this->assertSame($avvio['segreto'], $user->mfa_secret);
        $this->assertNotContains($codici[0], $user->mfa_recovery_codes);
        $this->assertDatabaseHas('audit_logs', ['action' => 'mfa.enabled', 'user_id' => $user->id]);

        // Codici di recupero nuovi: quelli di prima non valgono piu'
        $nuovi = $this->postJson('/api/v1/profilo/due-fattori/codici', ['password' => 'password'])->assertOk()->json('data.codici_recupero');
        $this->assertCount(DueFattori::CODICI_RECUPERO, $nuovi);
        $this->assertNull(DueFattori::verifica($user->refresh(), $codici[0]));
        $this->assertSame('recupero', DueFattori::verifica($user->refresh(), $nuovi[0]));

        // Si spegne con la password (la regola dell'organizzazione qui non obbliga)
        $this->deleteJson('/api/v1/profilo/due-fattori', ['password' => 'password'])->assertOk();
        $this->assertFalse($user->refresh()->mfa_enabled);
        $this->assertNull($user->mfa_secret);
        $this->assertDatabaseHas('audit_logs', ['action' => 'mfa.disabled', 'user_id' => $user->id]);
    }

    public function test_con_la_verifica_accesa_l_accesso_web_chiede_il_codice(): void
    {
        [, $user] = $this->createTenantUser();
        [$segreto] = $this->accendi($user);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('login.codice'));
        $this->assertGuest();

        $this->get('/login/codice')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Auth/Codice')->where('email', $user->email)->where('ritorno', route('login')));

        // Codice sbagliato: si resta al secondo passaggio, da ospiti
        $this->from('/login/codice')->post('/login/codice', ['codice' => '000000'])
            ->assertRedirect('/login/codice')->assertSessionHasErrors('codice');
        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.mfa_failed', 'user_id' => $user->id]);

        // Codice giusto: si entra e si atterra dove si atterrava prima
        $this->travel(30)->seconds();
        $this->post('/login/codice', ['codice' => Totp::codice($segreto, Totp::passo())])->assertRedirect(route('oggi'));
        $this->assertAuthenticatedAs($user);
        $this->assertNull(session('due_fattori'));
        $accesso = AuditLog::query()->withoutGlobalScopes()
            ->where('action', 'auth.login')->where('user_id', $user->id)->latest('created_at')->first();
        $this->assertSame('codice', $accesso->payload['due_fattori'] ?? null);

        Auth::forgetGuards();
        $this->get('/oggi')->assertOk();
    }

    public function test_un_codice_gia_speso_non_vale_una_seconda_volta(): void
    {
        [, $user] = $this->createTenantUser();
        // L'attivazione ha appena speso il codice di questo passo
        [$segreto] = $this->accendi($user);

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->post('/login/codice', ['codice' => Totp::codice($segreto, Totp::passo())])->assertSessionHasErrors('codice');
        $this->assertGuest();

        $this->travel(30)->seconds();
        $this->post('/login/codice', ['codice' => Totp::codice($segreto, Totp::passo())])->assertRedirect(route('oggi'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_il_codice_di_recupero_entra_una_volta_sola_e_porta_alla_pagina_del_proprio_accesso(): void
    {
        [, $user] = $this->createTenantUser();
        [, $codici] = $this->accendi($user);

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        // Minuscolo e con lo spazio al posto del trattino: si ricopia a mano
        $this->post('/login/codice', ['codice' => strtolower(str_replace('-', ' ', $codici[0]))])
            ->assertRedirect(route('sicurezza', ['recupero' => 1]));
        $this->assertAuthenticatedAs($user);
        $this->assertSame(DueFattori::CODICI_RECUPERO - 1, DueFattori::codiciRimasti($user->refresh()));
        $this->assertDatabaseHas('audit_logs', ['action' => 'mfa.recovery_used', 'user_id' => $user->id]);

        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->post('/login/codice', ['codice' => $codici[0]])->assertSessionHasErrors('codice');
        $this->assertGuest();
        $this->post('/login/codice', ['codice' => $codici[1]])->assertRedirect(route('sicurezza', ['recupero' => 1]));
        $this->assertAuthenticatedAs($user);
    }

    public function test_dopo_cinque_codici_sbagliati_si_ricomincia_dalla_password(): void
    {
        [, $user] = $this->createTenantUser();
        $this->accendi($user);

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        for ($i = 1; $i < WebAuthController::TENTATIVI; $i++) {
            $this->post('/login/codice', ['codice' => '000000'])->assertSessionHasErrors('codice');
        }
        $this->post('/login/codice', ['codice' => '000000'])->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->assertNull(session('due_fattori'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.mfa_locked', 'user_id' => $user->id]);

        // Senza il primo passaggio la pagina del codice rimanda all'accesso
        $this->get('/login/codice')->assertRedirect(route('login'));
        $this->post('/login/codice', ['codice' => '123456'])->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_il_secondo_passaggio_scade(): void
    {
        [, $user] = $this->createTenantUser();
        [$segreto] = $this->accendi($user);

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->travel(WebAuthController::MINUTI_ATTESA + 1)->minutes();
        $this->post('/login/codice', ['codice' => Totp::codice($segreto, Totp::passo())])
            ->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_l_accesso_con_gettone_api_richiede_il_codice(): void
    {
        [, $user] = $this->createTenantUser();
        [$segreto, $codici] = $this->accendi($user);
        $dati = ['email' => $user->email, 'password' => 'password'];

        $this->postJson('/api/v1/auth/login', $dati)->assertUnprocessable()->assertJsonValidationErrors('codice');
        $this->postJson('/api/v1/auth/login', $dati + ['codice' => '000000'])->assertUnprocessable()->assertJsonValidationErrors('codice');
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.mfa_failed', 'user_id' => $user->id]);

        $this->travel(30)->seconds();
        $this->postJson('/api/v1/auth/login', $dati + ['codice' => Totp::codice($segreto, Totp::passo())])
            ->assertCreated()->assertJsonStructure(['token']);
        // Anche un codice di recupero apre la porta del gettone, e si consuma
        $this->postJson('/api/v1/auth/login', $dati + ['codice' => $codici[0]])->assertCreated();
        $this->postJson('/api/v1/auth/login', $dati + ['codice' => $codici[0]])->assertUnprocessable();
    }

    public function test_la_regola_dell_organizzazione_la_decide_chi_gestisce_gli_utenti(): void
    {
        [$organizzazione, $admin] = $this->createTenantUser();
        $tecnico = User::factory()->create(['tenant_id' => $organizzazione->id]);
        $tecnico->assignRole('tecnico');

        $this->actingAsTenantUser($tecnico);
        $this->putJson('/api/v1/sicurezza/regola', ['regola' => 'tutti'])->assertForbidden();
        $this->getJson('/api/v1/sicurezza/regola')->assertForbidden();

        $this->actingAsTenantUser($admin);
        $regola = $this->getJson('/api/v1/sicurezza/regola')->assertOk()->json('data');
        $this->assertSame('nessuno', $regola['regola']);
        $this->assertSame(2, $regola['utenti_attivi']);
        $this->assertSame(0, $regola['con_verifica']);
        $this->assertSame(['amministratori' => 1, 'tutti' => 2], $regola['scoperti']);

        $this->putJson('/api/v1/sicurezza/regola', ['regola' => 'chiunque'])->assertUnprocessable();
        $this->putJson('/api/v1/sicurezza/regola', ['regola' => 'amministratori'])->assertOk()->assertJsonPath('data.regola', 'amministratori');
        $this->assertDatabaseHas('audit_logs', ['action' => 'sicurezza.due_fattori', 'subject_id' => $organizzazione->id]);
        // Le altre impostazioni dell'organizzazione restano
        $organizzazione->refresh();
        $this->assertSame('amministratori', $organizzazione->settings['sicurezza']['due_fattori']);

        // Con "amministratori" il tecnico non e' obbligato, l'amministratore si'
        $this->assertFalse(DueFattori::obbligatoriaPer($tecnico->refresh()));
        $this->assertTrue(DueFattori::obbligatoriaPer($admin->refresh()));

        // L'amministratore senza verifica trova chiuse le chiamate del
        // gestionale e aperte quelle della sua pagina (dove puo' anche
        // cambiare idea sulla regola)
        $this->getJson('/api/v1/assets')->assertForbidden();
        $this->getJson('/api/v1/users')->assertForbidden();
        $this->getJson('/api/v1/profilo/sicurezza')->assertOk()->assertJsonPath('data.due_fattori.obbligatoria', true);
        $this->getJson('/api/v1/sicurezza/regola')->assertOk();
        // ... e il gettone API non gli viene rilasciato finche' non la attiva
        $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertUnprocessable()->assertJsonValidationErrors('codice');
        // Il tecnico, non obbligato, lavora come prima
        $this->actingAsTenantUser($tecnico);
        $this->getJson('/api/v1/profilo/sicurezza')->assertOk()->assertJsonPath('data.due_fattori.obbligatoria', false);
        $this->getJson('/api/v1/assets')->assertOk();
    }

    public function test_chi_e_obbligato_e_non_ha_la_verifica_trova_solo_la_pagina_del_proprio_accesso(): void
    {
        [$organizzazione, $user] = $this->createTenantUser(role: 'tecnico');
        $organizzazione->forceFill(['settings' => ['sicurezza' => ['due_fattori' => 'tutti']]])->save();
        // Le chiamate della pagina arrivano dal browser con la sessione
        $this->withHeader('Referer', 'http://localhost/sicurezza');

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('oggi'));
        Auth::forgetGuards();
        $this->get('/oggi')->assertRedirect(route('sicurezza', ['obbligatoria' => 1]));
        Auth::forgetGuards();
        $this->get('/mappa')->assertRedirect(route('sicurezza', ['obbligatoria' => 1]));
        Auth::forgetGuards();
        $this->get('/sicurezza')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Sicurezza'));

        // Le chiamate della pagina passano, le altre no
        $this->getJson('/api/v1/profilo/sicurezza')->assertOk()->assertJsonPath('data.due_fattori.obbligatoria', true);
        $this->getJson('/api/v1/assets')->assertForbidden();

        // Attivata la verifica tutto riapre, e non si puo' piu' spegnere
        $avvio = $this->postJson('/api/v1/profilo/due-fattori/avvia', ['password' => 'password'])->assertOk()->json('data');
        $this->postJson('/api/v1/profilo/due-fattori/conferma', ['codice' => Totp::codice($avvio['segreto'], Totp::passo())])->assertOk();
        Auth::forgetGuards();
        $this->get('/oggi')->assertOk();
        $this->getJson('/api/v1/assets')->assertOk();
        $this->deleteJson('/api/v1/profilo/due-fattori', ['password' => 'password'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertTrue($user->refresh()->mfa_enabled);
    }

    public function test_l_amministratore_azzera_la_verifica_di_chi_ha_perso_il_telefono(): void
    {
        [$organizzazione, $admin] = $this->createTenantUser();
        $tecnico = User::factory()->create(['tenant_id' => $organizzazione->id]);
        $tecnico->assignRole('tecnico');
        $this->accendi($tecnico);
        $tecnico->createToken('telefono');

        $this->actingAsTenantUser($admin);
        $elenco = collect($this->getJson('/api/v1/users')->assertOk()->json('data'));
        $this->assertTrue($elenco->firstWhere('id', $tecnico->id)['mfa_enabled']);
        $this->assertFalse($elenco->firstWhere('id', $admin->id)['mfa_enabled']);

        $this->postJson("/api/v1/users/{$tecnico->id}/reset-due-fattori")->assertOk()->assertJsonPath('data.mfa_enabled', false);
        $tecnico->refresh();
        $this->assertFalse($tecnico->mfa_enabled);
        $this->assertNull($tecnico->mfa_secret);
        $this->assertSame(0, $tecnico->tokens()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.mfa_reset', 'subject_id' => $tecnico->id]);

        // Rientra con la sola password
        $this->assertNull(DueFattori::verifica($tecnico, '123456'));
        $this->assertFalse(DueFattori::attiva($tecnico));

        // Un tecnico non azzera la verifica degli altri
        $this->actingAsTenantUser($tecnico);
        $this->postJson("/api/v1/users/{$admin->id}/reset-due-fattori")->assertForbidden();
    }

    public function test_l_utente_cambia_la_propria_password(): void
    {
        [, $user] = $this->createTenantUser();
        $this->actingAsTenantUser($user);
        $nuova = 'nuova-password-lunga';

        $this->putJson('/api/v1/profilo/password', ['password_attuale' => 'sbagliata', 'password' => $nuova, 'password_confirmation' => $nuova])
            ->assertUnprocessable()->assertJsonValidationErrors('password_attuale');
        $this->putJson('/api/v1/profilo/password', ['password_attuale' => 'password', 'password' => 'corta', 'password_confirmation' => 'corta'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->putJson('/api/v1/profilo/password', ['password_attuale' => 'password', 'password' => $nuova, 'password_confirmation' => 'altra-password-lunga'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertTrue(Hash::check('password', $user->refresh()->password));

        $this->putJson('/api/v1/profilo/password', ['password_attuale' => 'password', 'password' => $nuova, 'password_confirmation' => $nuova])->assertOk();
        $this->assertTrue(Hash::check($nuova, $user->refresh()->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.password_changed', 'user_id' => $user->id]);
    }
}
