<?php

namespace Tests\Feature;

use App\Models\Certificate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class CertificateTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organization;

    private $user;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->organization, $this->user] = $this->createTenantUser();
        $this->actingAsTenantUser($this->user);
    }

    private function validPayload(array $overrides = []): array
    {
        return [
            'holder_name' => 'Mario Verdi',
            'title' => 'Patentino fitosanitario (utilizzatore professionale)',
            'number' => 'PF-12345',
            'issued_by' => 'Regione Lombardia',
            'issued_on' => now('Europe/Rome')->subYears(4)->toDateString(),
            'expires_on' => now('Europe/Rome')->addYear()->toDateString(),
            ...$overrides,
        ];
    }

    public function test_crud_states_ordering_and_versioning(): void
    {
        // Valido (scade tra un anno), in scadenza (30 giorni), scaduto (ieri),
        // senza scadenza: lo stato e i giorni residui sono calcolati dal server
        $valid = $this->postJson('/api/v1/certificates', $this->validPayload())
            ->assertCreated()->json('data');
        $this->assertSame('valid', $valid['state']);

        $dueSoon = $this->postJson('/api/v1/certificates', $this->validPayload([
            'holder_name' => 'Anna Bianchi', 'title' => 'Corso primo soccorso',
            'expires_on' => now('Europe/Rome')->addDays(30)->toDateString(),
        ]))->assertCreated()->json('data');
        $this->assertSame('due_soon', $dueSoon['state']);
        $this->assertSame(30, $dueSoon['days_left']);

        $expired = $this->postJson('/api/v1/certificates', $this->validPayload([
            'holder_name' => 'Luca Neri', 'title' => 'Corso antincendio',
            'expires_on' => now('Europe/Rome')->subDay()->toDateString(),
        ]))->assertCreated()->json('data');
        $this->assertSame('expired', $expired['state']);
        $this->assertSame(-1, $expired['days_left']);

        $noExpiry = $this->postJson('/api/v1/certificates', $this->validPayload([
            'holder_name' => 'Azienda', 'title' => 'Attestato formazione', 'expires_on' => null,
        ]))->assertCreated()->json('data');
        $this->assertSame('no_expiry', $noExpiry['state']);

        // L'elenco mette prima le scadenze piu' vicine, in fondo chi non scade
        $rows = $this->getJson('/api/v1/certificates')->assertOk()->json('data');
        $this->assertSame(
            [$expired['id'], $dueSoon['id'], $valid['id'], $noExpiry['id']],
            array_column($rows, 'id'),
        );

        // Rinnovo: si aggiorna la scadenza con controllo di versione
        $this->patchJson("/api/v1/certificates/{$expired['id']}", [
            'version' => 1, 'expires_on' => now('Europe/Rome')->addYears(5)->toDateString(),
        ])->assertOk()->assertJsonPath('data.state', 'valid');
        $this->patchJson("/api/v1/certificates/{$expired['id']}", [
            'version' => 1, 'notes' => 'x',
        ])->assertStatus(409);

        // Eliminazione con soft delete
        $this->deleteJson("/api/v1/certificates/{$noExpiry['id']}")->assertNoContent();
        $this->assertCount(3, $this->getJson('/api/v1/certificates')->json('data'));
        $this->assertNotNull(Certificate::withTrashed()->find($noExpiry['id'])->deleted_at);
    }

    public function test_validation_and_user_link(): void
    {
        // Scadenza prima del rilascio rifiutata
        $this->postJson('/api/v1/certificates', $this->validPayload([
            'issued_on' => now('Europe/Rome')->toDateString(),
            'expires_on' => now('Europe/Rome')->subYear()->toDateString(),
        ]))->assertUnprocessable();

        // Intestatario e titolo obbligatori
        $this->postJson('/api/v1/certificates', $this->validPayload(['holder_name' => '']))
            ->assertUnprocessable();
        $this->postJson('/api/v1/certificates', $this->validPayload(['title' => '']))
            ->assertUnprocessable();

        // Collegamento a una persona dell'app (anche con uuid maiuscolo)
        $person = \App\Models\User::factory()->create(['tenant_id' => $this->organization->id]);
        $created = $this->postJson('/api/v1/certificates', $this->validPayload([
            'user_id' => strtoupper($person->id),
        ]))->assertCreated()->json('data');
        $this->assertSame($person->id, $created['user_id']);
        $this->assertSame($person->name, $created['user']['name']);

        // Un utente di un altro tenant: come inesistente
        [, $foreignUser] = $this->createTenantUser();
        $this->actingAsTenantUser($this->user);
        $this->postJson('/api/v1/certificates', $this->validPayload([
            'user_id' => $foreignUser->id,
        ]))->assertNotFound();
    }

    public function test_partial_patch_still_checks_date_order(): void
    {
        // Rilasciato 4 anni fa, scade tra un anno
        $created = $this->postJson('/api/v1/certificates', $this->validPayload())
            ->assertCreated()->json('data');

        // Rinnovo con errore di battitura sull'anno: scadenza prima del
        // rilascio memorizzato -> 422 in italiano, non 500 dal CHECK del DB
        $this->patchJson("/api/v1/certificates/{$created['id']}", [
            'version' => 1,
            'expires_on' => now('Europe/Rome')->subYears(10)->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors('expires_on');

        // Speculare: solo il rilascio, spostato oltre la scadenza memorizzata
        $this->patchJson("/api/v1/certificates/{$created['id']}", [
            'version' => 1,
            'issued_on' => now('Europe/Rome')->addYears(2)->toDateString(),
        ])->assertUnprocessable();

        // Il certificato e' rimasto intatto e ancora aggiornabile
        $this->patchJson("/api/v1/certificates/{$created['id']}", [
            'version' => 1,
            'expires_on' => now('Europe/Rome')->addYears(5)->toDateString(),
        ])->assertOk()->assertJsonPath('data.state', 'valid');
    }

    public function test_dashboard_today_lists_expiring_certificates(): void
    {
        $this->postJson('/api/v1/certificates', $this->validPayload([
            'holder_name' => 'Luca Neri', 'title' => 'Corso antincendio',
            'issued_on' => null,
            'expires_on' => now('Europe/Rome')->subDays(3)->toDateString(),
        ]))->assertCreated();
        $this->postJson('/api/v1/certificates', $this->validPayload([
            'holder_name' => 'Anna Bianchi', 'title' => 'Corso primo soccorso',
            'expires_on' => now('Europe/Rome')->addDays(15)->toDateString(),
        ]))->assertCreated();
        // Lontano dalla soglia dei 60 giorni: non deve comparire
        $this->postJson('/api/v1/certificates', $this->validPayload())->assertCreated();

        $section = $this->getJson('/api/v1/dashboard/today')->assertOk()
            ->json('data.certificates');
        $this->assertSame(1, $section['expired_count']);
        $this->assertSame(1, $section['due_soon_count']);
        $this->assertCount(2, $section['rows']);
        $this->assertSame('Luca Neri', $section['rows'][0]['holder_name']);
        $this->assertSame('expired', $section['rows'][0]['state']);
    }

    public function test_permissions_and_tenant_isolation(): void
    {
        $created = $this->postJson('/api/v1/certificates', $this->validPayload())
            ->assertCreated()->json('data');

        // L'operatore consulta lo scadenzario ma non scrive
        $operator = \App\Models\User::factory()->create(['tenant_id' => $this->organization->id]);
        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
        $operator->assignRole('operatore');
        $this->actingAsTenantUser($operator);
        $this->getJson('/api/v1/certificates')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/certificates', $this->validPayload())->assertForbidden();

        // Un altro tenant non vede e non tocca nulla
        [, $foreign] = $this->createTenantUser();
        $this->actingAsTenantUser($foreign);
        $this->assertSame([], $this->getJson('/api/v1/certificates')->assertOk()->json('data'));
        $this->patchJson("/api/v1/certificates/{$created['id']}", ['notes' => 'x'])
            ->assertNotFound();
        $this->deleteJson("/api/v1/certificates/{$created['id']}")->assertNotFound();
    }
}
