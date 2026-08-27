<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Corrispondenze colonne->campi salvate per l'import generico: chi riceve
 * sempre lo stesso tracciato da un fornitore non deve rimappare ogni volta.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE import_mappings (
              id                      uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              tenant_id               uuid NOT NULL REFERENCES organizations(id),
              name                    text NOT NULL,
              mapping                 jsonb NOT NULL DEFAULT '{}'::jsonb,
              default_object_type_id  uuid REFERENCES catalog_object_types(id),
              created_by              uuid REFERENCES users(id),
              created_at              timestamptz NOT NULL DEFAULT now(),
              updated_at              timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT uq_import_mappings_tenant_name UNIQUE (tenant_id, name)
            );
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('import_mappings');
    }
};
