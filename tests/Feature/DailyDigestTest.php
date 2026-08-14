<?php

namespace Tests\Feature;

use App\Mail\DailyDigestMail;
use App\Models\Certificate;
use App\Models\Issue;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class DailyDigestTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organization;

    private $admin;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->organization, $this->admin] = $this->createTenantUser();
    }

    private function makeUser(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(['tenant_id' => $this->organization->id, ...$attributes]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
        $user->assignRole($role);

        return $user;
    }

    private function makeExpiredCertificate(): Certificate
    {
        return Certificate::withoutGlobalScopes()->create([
            'tenant_id' => $this->organization->id,
            'holder_name' => 'Mario Verdi',
            'title' => 'Patentino fitosanitario',
            'expires_on' => now('Europe/Rome')->subDays(5)->toDateString(),
        ]);
    }

    public function test_digest_reaches_only_opted_in_admins_and_technicians(): void
    {
        Mail::fake();

        $tecnicoNoMail = $this->makeUser('tecnico', ['notify_email' => false]);
        $tecnico = $this->makeUser('tecnico');
        $operatore = $this->makeUser('operatore');
        $this->makeExpiredCertificate();

        // Un lavoro in ritardo popola una seconda sezione
        $area = $this->createArea($this->organization);
        $this->actingAsTenantUser($this->admin);
        WorkOrder::create([
            'tenant_id' => $this->organization->id,
            'code' => WorkOrder::nextCode($this->organization->id),
            'title' => 'Potatura in ritardo',
            'status' => 'planned',
            'area_id' => $area->id,
            'planned_start' => now('Europe/Rome')->subDays(10)->toDateString(),
            'planned_end' => now('Europe/Rome')->subDays(3)->toDateString(),
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        $this->artisan('notifications:daily')->assertSuccessful();

        // Admin e tecnico con preferenza accesa: una email a testa
        Mail::assertSent(DailyDigestMail::class, 2);
        Mail::assertSent(DailyDigestMail::class, fn ($mail) => $mail->hasTo($this->admin->email));
        Mail::assertSent(DailyDigestMail::class, fn ($mail) => $mail->hasTo($tecnico->email));
        Mail::assertNotSent(DailyDigestMail::class, fn ($mail) => $mail->hasTo($tecnicoNoMail->email));
        Mail::assertNotSent(DailyDigestMail::class, fn ($mail) => $mail->hasTo($operatore->email));
    }

    public function test_digest_content_and_subject(): void
    {
        $this->makeExpiredCertificate();

        $digest = app(\App\Services\Notifications\DailyDigest::class);
        $data = $digest->build($this->organization, $this->admin);

        $this->assertNotNull($data);
        $this->assertArrayHasKey('certificates', $data['sections']);
        $this->assertSame(1, $data['sections']['certificates']['count']);

        $mail = new DailyDigestMail($this->organization, $data);
        $html = $mail->render();
        $this->assertStringContainsString('Mario Verdi', $html);
        $this->assertStringContainsString('Patentino fitosanitario', $html);
        $this->assertStringContainsString('scaduto il', $html);
        $this->assertStringContainsString('cruscotto Oggi', $html);
        $this->assertStringContainsString('1 scadenza da attenzionare', $mail->envelope()->subject);
    }

    public function test_no_mail_when_nothing_to_report_and_tenants_are_isolated(): void
    {
        Mail::fake();

        // Il tenant A ha una scadenza; il tenant B e' pulito
        $this->makeExpiredCertificate();
        [, $foreignAdmin] = $this->createTenantUser();

        $this->artisan('notifications:daily')->assertSuccessful();

        Mail::assertSent(DailyDigestMail::class, 1);
        Mail::assertSent(DailyDigestMail::class, fn ($mail) => $mail->hasTo($this->admin->email));
        Mail::assertNotSent(DailyDigestMail::class, fn ($mail) => $mail->hasTo($foreignAdmin->email));

        // Nessuna scadenza da nessuna parte: nessuna email
        Mail::fake();
        Certificate::withoutGlobalScopes()
            ->where('tenant_id', $this->organization->id)->forceDelete();
        $this->artisan('notifications:daily')->assertSuccessful();
        Mail::assertNothingSent();

        // La sezione segnalazioni compare quando un SLA e' fuori tempo
        Mail::fake();
        $this->actingAsTenantUser($this->admin);
        Issue::create([
            'tenant_id' => $this->organization->id,
            'code' => Issue::nextCode($this->organization->id),
            'status' => 'open',
            'severity' => 'critical',
            'reporter_type' => 'internal',
            'channel' => 'backoffice',
            'description' => 'Ramo pericolante sul vialetto.',
        ])->forceFill(['taken_charge_due_at' => now()->subDay(), 'sla_due_at' => now()->addDay()])->save();
        $this->artisan('notifications:daily')->assertSuccessful();
        Mail::assertSent(DailyDigestMail::class, fn ($mail) => $mail->hasTo($this->admin->email)
            && str_contains($mail->render(), 'Ramo pericolante'));
    }
}
