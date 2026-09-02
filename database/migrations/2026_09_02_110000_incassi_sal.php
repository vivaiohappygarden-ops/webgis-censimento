<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Incassi sui SAL.
 *
 * Il programma REGISTRA fatture e incassi, non li emette: qui si scrivono
 * gli estremi della fattura (numero, data, scadenza di pagamento) e il
 * fatto contabile dell'incasso (data e nota). Gli stati del documento
 * restano tre (bozza/validato/fatturato): "incassato" NON e' un nuovo
 * stato ma paid_at valorizzata, e "in ritardo" e' una condizione derivata
 * (fatturato, non incassato, scadenza passata). Meno stati, meno bugie:
 * l'incasso puo' arrivare in disordine rispetto al flusso del documento.
 */
return new class extends Migration
{
    public function up(): void
    {
        // La data della fattura e' un giorno di calendario scritto dal
        // commercialista, non l'istante del clic su "fatturato": il tipo
        // diventa date. Le righe esistenti tengono il giorno ITALIANO del
        // passaggio (il taglio in UTC sposterebbe le sere a fine mese)
        DB::statement("ALTER TABLE sals ALTER COLUMN invoiced_at TYPE date USING (invoiced_at AT TIME ZONE 'Europe/Rome')::date");

        DB::statement('ALTER TABLE sals ADD COLUMN payment_due_at date');
        DB::statement('ALTER TABLE sals ADD COLUMN paid_at date');
        DB::statement('ALTER TABLE sals ADD COLUMN paid_note text');

        // I fatti contabili devono restare veri anche per chi un domani
        // scrivesse a mano sul database: incasso solo su un SAL fatturato,
        // e mai con date che precedono la fattura
        DB::statement("ALTER TABLE sals ADD CONSTRAINT chk_sals_incasso_su_fatturato CHECK (paid_at IS NULL OR status = 'fatturato')");
        DB::statement('ALTER TABLE sals ADD CONSTRAINT chk_sals_incasso_dopo_fattura CHECK (paid_at IS NULL OR invoiced_at IS NULL OR paid_at >= invoiced_at)');
        DB::statement('ALTER TABLE sals ADD CONSTRAINT chk_sals_scadenza_dopo_fattura CHECK (payment_due_at IS NULL OR invoiced_at IS NULL OR payment_due_at >= invoiced_at)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sals DROP CONSTRAINT IF EXISTS chk_sals_scadenza_dopo_fattura');
        DB::statement('ALTER TABLE sals DROP CONSTRAINT IF EXISTS chk_sals_incasso_dopo_fattura');
        DB::statement('ALTER TABLE sals DROP CONSTRAINT IF EXISTS chk_sals_incasso_su_fatturato');
        DB::statement('ALTER TABLE sals DROP COLUMN IF EXISTS paid_note');
        DB::statement('ALTER TABLE sals DROP COLUMN IF EXISTS paid_at');
        DB::statement('ALTER TABLE sals DROP COLUMN IF EXISTS payment_due_at');
        DB::statement("ALTER TABLE sals ALTER COLUMN invoiced_at TYPE timestamptz USING (invoiced_at::timestamp AT TIME ZONE 'Europe/Rome')");
    }
};
