<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Scadenzario di patentini e certificati: abilitazioni personali
     * (patentino fitosanitario, trattore, corsi sicurezza) e documenti
     * aziendali o di attrezzatura (taratura irroratrice, assicurazioni).
     * L'intestatario può essere un utente dell'app (user_id) o un nome
     * libero; holder_name resta comunque il nome mostrato, così la riga
     * sopravvive anche a un utente rimosso.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE certificates (
              id           uuid PRIMARY KEY,
              tenant_id    uuid NOT NULL REFERENCES organizations(id),
              user_id      uuid REFERENCES users(id),
              holder_name  text NOT NULL,
              title        text NOT NULL,
              number       text,
              issued_by    text,
              issued_on    date,
              expires_on   date,
              notes        text,
              version      int NOT NULL DEFAULT 1,
              created_by   uuid REFERENCES users(id),
              updated_by   uuid REFERENCES users(id),
              created_at   timestamptz NOT NULL DEFAULT now(),
              updated_at   timestamptz NOT NULL DEFAULT now(),
              deleted_at   timestamptz,
              CONSTRAINT ck_certificates_dates CHECK (
                issued_on IS NULL OR expires_on IS NULL OR expires_on >= issued_on)
            );
        SQL);
        DB::statement(<<<'SQL'
            CREATE INDEX ix_certificates_tenant_expiry ON certificates (tenant_id, expires_on)
              WHERE deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};
