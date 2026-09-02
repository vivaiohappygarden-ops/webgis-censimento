<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Piani di manutenzione pluriennali.
 *
 * Per ogni area si dichiara una ricorrenza ("potatura ogni 3 anni", "sfalcio
 * ogni mese da marzo a ottobre") e il programma genera da se' gli ordini di
 * lavoro del periodo, che poi si correggono in agenda.
 *
 * La frequenza e' "ogni interval_months mesi" (36 = ogni 3 anni, 1 = ogni
 * mese): una sola forma, non ambigua. La finestra stagionale e' opzionale e
 * puo' scavalcare il capodanno (month_from=11, month_to=2 = da novembre a
 * febbraio, per le potature invernali).
 *
 * La chiave di idempotenza della generazione sta sugli ordini: la colonna
 * plan_month (primo giorno del mese di scadenza) la scrive SOLO il
 * generatore e non si tocca dall'API. Cosi' spostare l'ordine in agenda
 * (planned_start) non fa perdere la memoria di quale scadenza copre, e
 * l'indice unico parziale garantisce sul database che due generazioni,
 * anche simultanee, non creino mai due ordini per la stessa scadenza
 * dello stesso piano.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE maintenance_plans (
              id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              tenant_id       uuid NOT NULL REFERENCES organizations(id),
              area_id         uuid NOT NULL REFERENCES areas(id),
              work_type_id    uuid NOT NULL REFERENCES work_types(id),
              interval_months integer NOT NULL CHECK (interval_months BETWEEN 1 AND 120),
              month_from      smallint CHECK (month_from BETWEEN 1 AND 12),
              month_to        smallint CHECK (month_to BETWEEN 1 AND 12),
              team_id         uuid REFERENCES teams(id),
              notes           text,
              is_active       boolean NOT NULL DEFAULT true,
              created_by      uuid REFERENCES users(id),
              updated_by      uuid REFERENCES users(id),
              created_at      timestamptz,
              updated_at      timestamptz,
              deleted_at      timestamptz,
              -- La finestra o c'e' tutta o non c'e': un solo estremo sarebbe
              -- interpretato in silenzio
              CHECK ((month_from IS NULL) = (month_to IS NULL))
            )
        SQL);

        // Un solo piano per area e lavorazione: due piani "sfalcio sul Parco
        // Nord" genererebbero doppioni per costruzione
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX uq_maintenance_plans_area_lavorazione
            ON maintenance_plans (tenant_id, area_id, work_type_id)
            WHERE deleted_at IS NULL
        SQL);
        DB::statement(<<<'SQL'
            CREATE INDEX idx_maintenance_plans_tenant
            ON maintenance_plans (tenant_id, is_active)
            WHERE deleted_at IS NULL
        SQL);

        // La scadenza che un ordine generato copre: la scrive solo il
        // generatore. L'indice unico e' la garanzia di idempotenza sul DB
        DB::statement('ALTER TABLE work_orders ADD COLUMN plan_month date');
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX uq_work_orders_piano_scadenza
            ON work_orders (origin_id, plan_month)
            WHERE origin = 'maintenance_plan' AND plan_month IS NOT NULL AND deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS uq_work_orders_piano_scadenza');
        DB::statement('ALTER TABLE work_orders DROP COLUMN IF EXISTS plan_month');
        DB::statement('DROP TABLE IF EXISTS maintenance_plans');
    }
};
