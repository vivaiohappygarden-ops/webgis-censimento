<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Chiusura della perizia: una volta validata non si tocca più.
 *
 * Una perizia di stabilità è un atto tecnico su cui altri prendono decisioni
 * di sicurezza. Se resta modificabile dopo la firma, non prova niente: chi
 * la contesta può sempre dire che il testo di oggi non è quello di allora.
 *
 * Il blocco sta nel database e non solo nel programma, perché è la garanzia
 * che si offre al committente: deve reggere anche a una modifica fatta per
 * sbaglio da un'altra strada.
 *
 * Resta modificabile solo la pubblicazione sul portale pubblico, che non è
 * contenuto tecnico ma una scelta del committente su cosa mostrare.
 *
 * Una perizia sbagliata non si cancella: si emette una perizia successiva
 * che la supera, come si fa con qualunque atto.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE tree_assessments ADD COLUMN validated_at timestamptz');
        DB::statement('ALTER TABLE tree_assessments ADD COLUMN validated_by uuid REFERENCES users(id)');
        // Impronta del contenuto tecnico al momento della validazione: si
        // stampa sul documento e permette di dimostrare, anche a distanza di
        // anni, che quella copia corrisponde a quanto fu validato
        DB::statement('ALTER TABLE tree_assessments ADD COLUMN content_hash text');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION trg_tree_assessments_immutable() RETURNS trigger AS $$
            BEGIN
              IF TG_OP = 'DELETE' THEN
                IF OLD.validated_at IS NOT NULL THEN
                  RAISE EXCEPTION 'La perizia è stata validata e non è più cancellabile.'
                    USING ERRCODE = 'PZ001';
                END IF;
                RETURN OLD;
              END IF;

              IF OLD.validated_at IS NOT NULL THEN
                -- Dopo la validazione cambia solo la pubblicazione sul portale.
                -- version, updated_at e updated_by seguono qualunque scrittura e
                -- non sono contenuto tecnico.
                IF (to_jsonb(NEW) - 'is_public' - 'version' - 'updated_at' - 'updated_by')
                   IS DISTINCT FROM
                   (to_jsonb(OLD) - 'is_public' - 'version' - 'updated_at' - 'updated_by') THEN
                  RAISE EXCEPTION 'La perizia è stata validata e non è più modificabile.'
                    USING ERRCODE = 'PZ001';
                END IF;
              END IF;

              RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER tree_assessments_immutable
              BEFORE UPDATE OR DELETE ON tree_assessments
              FOR EACH ROW EXECUTE FUNCTION trg_tree_assessments_immutable();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS tree_assessments_immutable ON tree_assessments');
        DB::unprepared('DROP FUNCTION IF EXISTS trg_tree_assessments_immutable()');
        DB::statement('ALTER TABLE tree_assessments DROP COLUMN content_hash');
        DB::statement('ALTER TABLE tree_assessments DROP COLUMN validated_by');
        DB::statement('ALTER TABLE tree_assessments DROP COLUMN validated_at');
    }
};
