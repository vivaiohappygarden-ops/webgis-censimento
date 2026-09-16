<?php

namespace App\Console\Commands;

use App\Services\Demo\PatrimonioDimostrativo;
use Illuminate\Console\Command;

/**
 * Riempie il Comune Demo con un patrimonio verosimile, per far vedere il
 * portale e il gestionale a un Comune prima di avere i suoi dati.
 *
 *   php artisan demo:patrimonio --prova          conta senza scrivere
 *   php artisan demo:patrimonio --alberi=400     crea 400 alberi
 *
 * Scrive solo nell'organizzazione "demo" (App\Services\Demo\PatrimonioDimostrativo):
 * su un server con clienti veri non puo' toccare nessun altro dato.
 */
class DemoPatrimonio extends Command
{
    protected $signature = 'demo:patrimonio
        {--alberi=400 : Quanti alberi creare}
        {--prova : Mostra che cosa verrebbe creato, senza scrivere niente}
        {--si : Non chiedere conferma}';

    protected $description = 'Riempie il Comune Demo con alberi, valutazioni e lavori verosimili (solo organizzazione "demo")';

    public function handle(): int
    {
        $organizzazione = PatrimonioDimostrativo::organizzazioneDemo();
        if ($organizzazione === null) {
            $this->error('Non esiste l\'organizzazione "demo": lanciare prima php artisan db:seed.');

            return self::FAILURE;
        }

        $alberi = (int) $this->option('alberi');
        $prova = (bool) $this->option('prova');

        try {
            $generatore = PatrimonioDimostrativo::per($organizzazione);

            // l'anteprima passa dallo stesso metodo dell'esecuzione: il
            // conteggio che si mostra e' quello che si scriverebbe
            $anteprima = $generatore->genera($alberi, prova: true);
            $this->line(sprintf(
                'Comune Demo: %d elementi presenti. Verrebbero creati %d alberi (%d con valutazione), %d aree nuove, %d ordini di lavoro conclusi.',
                $generatore->elementiEsistenti(), $anteprima['alberi'], $anteprima['valutazioni'], $anteprima['aree'], $anteprima['lavori'],
            ));

            if ($prova) {
                $this->info('Prova: non e\' stato scritto niente.');

                return self::SUCCESS;
            }

            if (! $this->option('si') && ! $this->confirm('Procedere?', true)) {
                $this->line('Annullato.');

                return self::SUCCESS;
            }

            $esito = $generatore->genera($alberi);
        } catch (\DomainException|\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Fatto: %d alberi, %d valutazioni, %d aree nuove, %d ordini di lavoro. Il portale del Comune Demo li mostra subito.',
            $esito['alberi'], $esito['valutazioni'], $esito['aree'], $esito['lavori'],
        ));

        return self::SUCCESS;
    }
}
