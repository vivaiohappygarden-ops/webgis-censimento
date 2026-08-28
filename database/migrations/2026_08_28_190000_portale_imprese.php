<?php

use App\Models\Organization;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Portale delle imprese appaltatrici.
 *
 * Una squadra puo' essere un'impresa esterna (is_external): i suoi membri
 * hanno il ruolo "impresa" e vedono, da una pagina dedicata, i soli ordini
 * di lavoro affidati alle loro squadre. Da li' possono chiedere formalmente
 * una riprogrammazione con motivo codificato: la richiesta resta agli atti
 * (tabella reschedule_requests) e il gestionale la accetta, spostando le
 * date, o la rifiuta con una risposta.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE teams ADD COLUMN IF NOT EXISTS is_external boolean NOT NULL DEFAULT false');

        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS reschedule_requests (
              id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              tenant_id    uuid NOT NULL REFERENCES organizations(id),
              work_order_id uuid NOT NULL REFERENCES work_orders(id) ON DELETE CASCADE,
              team_id      uuid NOT NULL REFERENCES teams(id),
              requested_by uuid NOT NULL REFERENCES users(id),
              reason       text NOT NULL,
              proposed_start date,
              notes        text,
              status       text NOT NULL DEFAULT 'aperta',
              response_note text,
              decided_by   uuid REFERENCES users(id),
              decided_at   timestamptz,
              created_at   timestamptz,
              updated_at   timestamptz
            )
        SQL);
        DB::statement('CREATE INDEX IF NOT EXISTS idx_reschedule_tenant_status ON reschedule_requests (tenant_id, status)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_reschedule_work_order ON reschedule_requests (work_order_id)');
        // Una sola richiesta aperta per ordine e squadra: la seconda si
        // scrive solo quando la prima e' stata decisa
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS uq_reschedule_aperta
              ON reschedule_requests (work_order_id, team_id)
              WHERE status = 'aperta'
        SQL);

        // Ruolo e permesso anche per le organizzazioni gia' esistenti,
        // come fu per il portale cliente
        app(TenantProvisioner::class)->ensurePermissions();
        // Il pacchetto dei permessi tiene una cache: senza svuotarla, il
        // permesso appena creato risulterebbe inesistente qui sotto
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();
        try {
            foreach (Organization::query()->pluck('id') as $tenantId) {
                $registrar->setPermissionsTeamId($tenantId);
                $role = Role::firstOrCreate([
                    'name' => 'impresa', 'guard_name' => 'web', 'tenant_id' => $tenantId,
                ]);
                $role->givePermissionTo('impresa.view');
            }
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
        }
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS reschedule_requests');
        DB::statement('ALTER TABLE teams DROP COLUMN IF EXISTS is_external');
    }
};
