<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * L'aggiornamento automatico del server (deploy/aggiornamento-automatico.sh):
 * ogni cinque minuti guarda se sul ramo seguito c'e' una versione nuova e, se
 * c'e', lancia update.sh. Qui si prova la logica con due depositi git di
 * prova e un finto update.sh che lascia una traccia quando viene chiamato.
 */
class AggiornamentoAutomaticoTest extends TestCase
{
    private string $cartella;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cartella = sys_get_temp_dir().'/webgis-aggiornamento-'.uniqid();
        mkdir($this->cartella);
        // Un deposito "remoto" e due copie: il server e chi pubblica
        $this->git('init -q --bare remoto.git');
        $this->git('clone -q remoto.git server');
        $this->git('clone -q remoto.git autore');
        file_put_contents($this->cartella.'/autore/versione.txt', "1\n");
        $this->git('-C autore add versione.txt');
        $this->git('-C autore commit -q -m "Prima versione"');
        $this->git('-C autore push -q origin HEAD');
        $this->git('-C server pull -q origin '.$this->ramo());
        // Il finto update.sh: lascia una traccia e allinea il codice come farebbe quello vero
        file_put_contents($this->cartella.'/finto-update.sh', "#!/usr/bin/env bash\nset -e\necho chiamato >> '{$this->cartella}/traccia'\ngit pull -q --ff-only\n");
    }

    public function test_senza_novita_non_fa_niente(): void
    {
        [$esito, $uscita] = $this->lancia();

        $this->assertSame(0, $esito, $uscita);
        $this->assertFileDoesNotExist($this->cartella.'/traccia');
        $this->assertFileDoesNotExist($this->cartella.'/registro.log');
    }

    public function test_con_una_versione_nuova_lancia_l_aggiornamento_e_lo_registra(): void
    {
        file_put_contents($this->cartella.'/autore/versione.txt', "2\n");
        $this->git('-C autore commit -q -am "Sito: tavola delle quote ridisegnata"');
        $this->git('-C autore push -q origin HEAD');

        [$esito, $uscita] = $this->lancia();

        $this->assertSame(0, $esito, $uscita);
        $this->assertFileExists($this->cartella.'/traccia');
        $registro = file_get_contents($this->cartella.'/registro.log');
        $this->assertStringContainsString('nuova versione sul ramo', $registro);
        $this->assertStringContainsString('Sito: tavola delle quote ridisegnata', $registro);
        $this->assertStringContainsString('aggiornamento riuscito', $registro);
        // Il server e' arrivato alla versione nuova: un secondo giro non fa niente
        [$esito2] = $this->lancia();
        $this->assertSame(0, $esito2);
        $this->assertSame(1, substr_count(file_get_contents($this->cartella.'/traccia'), 'chiamato'));
    }

    public function test_con_storia_divergente_si_ferma_e_lo_scrive(): void
    {
        // Qualcuno ha toccato il codice sul server: un commit locale che il remoto non ha
        file_put_contents($this->cartella.'/server/locale.txt', "modifica a mano\n");
        $this->git('-C server add locale.txt');
        $this->git('-C server commit -q -m "Ritocco sul server"');
        file_put_contents($this->cartella.'/autore/versione.txt', "3\n");
        $this->git('-C autore commit -q -am "Versione nuova"');
        $this->git('-C autore push -q origin HEAD');

        [$esito, $uscita] = $this->lancia();

        $this->assertSame(1, $esito);
        $this->assertStringContainsString('storia divergente', $uscita);
        $this->assertFileDoesNotExist($this->cartella.'/traccia');
        $this->assertStringContainsString('storia divergente', file_get_contents($this->cartella.'/registro.log'));
    }

    public function test_se_l_aggiornamento_fallisce_il_registro_lo_dice(): void
    {
        file_put_contents($this->cartella.'/finto-update.sh', "#!/usr/bin/env bash\necho 'errore finto'\nexit 3\n");
        file_put_contents($this->cartella.'/autore/versione.txt', "4\n");
        $this->git('-C autore commit -q -am "Versione che non si installa"');
        $this->git('-C autore push -q origin HEAD');

        [$esito] = $this->lancia();

        $this->assertSame(3, $esito);
        $registro = file_get_contents($this->cartella.'/registro.log');
        $this->assertStringContainsString('errore finto', $registro);
        $this->assertStringContainsString('AGGIORNAMENTO FALLITO (codice 3)', $registro);
    }

    public function test_lo_script_di_abilitazione_scrive_il_timer_di_sistema(): void
    {
        $unita = $this->cartella.'/systemd';
        $comando = sprintf(
            'WEBGIS_PROVA=1 WEBGIS_APP_DIR=%s WEBGIS_SYSTEMD_DIR=%s bash %s 2>&1',
            escapeshellarg(base_path()), escapeshellarg($unita), escapeshellarg(base_path('deploy/abilita-aggiornamento-automatico.sh')),
        );
        exec($comando, $righe, $esito);
        $uscita = implode("\n", $righe);

        $this->assertSame(0, $esito, $uscita);
        $servizio = file_get_contents($unita.'/webgis-aggiornamento.service');
        $timer = file_get_contents($unita.'/webgis-aggiornamento.timer');
        $this->assertStringContainsString('ExecStart=/usr/bin/env bash '.base_path().'/deploy/aggiornamento-automatico.sh', $servizio);
        $this->assertStringContainsString('Type=oneshot', $servizio);
        $this->assertStringContainsString('OnUnitActiveSec=5min', $timer);
        $this->assertStringContainsString('Persistent=true', $timer);
        $this->assertStringContainsString('WantedBy=timers.target', $timer);
        // Spiega come controllare e come spegnere
        $this->assertStringContainsString('--stato', $uscita);
        $this->assertStringContainsString('--disabilita', $uscita);
    }

    /** @return array{0: int, 1: string} */
    private function lancia(): array
    {
        $comando = sprintf(
            'WEBGIS_APP_DIR=%s WEBGIS_REGISTRO=%s WEBGIS_LUCCHETTO=%s WEBGIS_COMANDO_AGGIORNAMENTO=%s bash %s 2>&1',
            escapeshellarg($this->cartella.'/server'),
            escapeshellarg($this->cartella.'/registro.log'),
            escapeshellarg($this->cartella.'/lucchetto'),
            escapeshellarg('bash '.$this->cartella.'/finto-update.sh'),
            escapeshellarg(base_path('deploy/aggiornamento-automatico.sh')),
        );
        exec($comando, $righe, $esito);

        return [$esito, implode("\n", $righe)];
    }

    private function git(string $argomenti): void
    {
        exec(sprintf('cd %s && git -c user.name=Prova -c user.email=prova@example.test -c init.defaultBranch=main %s 2>&1', escapeshellarg($this->cartella), $argomenti), $righe, $esito);
        $this->assertSame(0, $esito, "git {$argomenti}:\n".implode("\n", $righe));
    }

    private function ramo(): string
    {
        exec(sprintf('cd %s/autore && git rev-parse --abbrev-ref HEAD', escapeshellarg($this->cartella)), $righe);

        return trim($righe[0] ?? 'main');
    }
}
