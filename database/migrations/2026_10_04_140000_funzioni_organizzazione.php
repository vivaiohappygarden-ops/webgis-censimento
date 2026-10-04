<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Le funzioni accese per organizzazione (settings['funzioni']). Il
 * collegamento al gestionale giardini nasce spento; chi lo aveva gia'
 * configurato (un indirizzo del gestionale nelle impostazioni) lo tiene
 * acceso, cosi' l'aggiornamento non spegne niente a chi lo usa.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('organizations')->select(['id', 'settings'])->get() as $riga) {
            $settings = json_decode($riga->settings ?? '{}', true) ?: [];
            if (isset($settings['funzioni']['gestionale_giardini'])) {
                continue;
            }
            $settings['funzioni'] = array_replace($settings['funzioni'] ?? [], [
                'gestionale_giardini' => ! empty($settings['gestionale']['endpoint']),
            ]);
            DB::table('organizations')->where('id', $riga->id)->update(['settings' => json_encode($settings)]);
        }
    }

    public function down(): void
    {
        // Lo stato delle funzioni resta: spegnere a mano e' una scelta, non un rollback
    }
};
