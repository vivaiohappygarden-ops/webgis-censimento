<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Avvisi al committente (richiesta del committente 07/10/2026): quando una
 * valutazione di stabilita' trova una propensione al cedimento elevata o
 * estrema su un albero che sta in un'area frequentata (un parco giochi, un
 * giardino scolastico), il tecnico avvisa il Comune perche' chiuda o
 * interdica l'area fino all'intervento. L'avviso parte via email, entra
 * fra le prescrizioni della valutazione e resta qui con la sua storia:
 * a chi e' stato mandato, quando il committente ne ha preso atto dal
 * portale riservato, quando e' rientrato.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('client_id')->index();
            $table->uuid('asset_id');
            $table->uuid('assessment_id')->nullable()->unique();
            $table->uuid('area_id')->nullable();
            $table->string('kind', 40)->default('area_closure');
            $table->string('failure_class', 5)->nullable();
            $table->text('message');
            $table->uuid('sent_by')->nullable();
            $table->timestampTz('sent_at');
            // Un elemento per destinatario: indirizzo, origine (utente del portale, PEC,
            // contatto), esito dell'invio e l'eventuale errore scritto dal server di posta
            $table->jsonb('recipients')->default('[]');
            $table->timestampTz('acknowledged_at')->nullable();
            $table->uuid('acknowledged_by')->nullable();
            $table->text('acknowledged_note')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->uuid('resolved_by')->nullable();
            $table->text('resolved_note')->nullable();
            $table->timestampsTz();
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
            $table->foreign('asset_id')->references('id')->on('assets')->cascadeOnDelete();
            $table->foreign('assessment_id')->references('id')->on('tree_assessments')->nullOnDelete();
            $table->foreign('area_id')->references('id')->on('areas')->nullOnDelete();
            $table->index(['tenant_id', 'resolved_at']);
            $table->index('asset_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_alerts');
    }
};
