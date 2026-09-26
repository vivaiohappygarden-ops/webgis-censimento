<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * I salvataggi del server (deploy/backup.sh, dal 26/09/2026).
 *
 * Prima ogni notte si comprimeva l'intero archivio delle foto e se ne
 * tenevano 14 copie: con 36 GB di foto erano 500 GB. Ora la banca dati si
 * scarica e si verifica, i file vanno in istantanee incrementali con
 * collegamenti fisici (una copia piu' le sole novita'), le istantanee
 * vecchie si eliminano, la copia fuori dal server e' a portata di un file
 * di configurazione. Le prove lanciano davvero lo script su cartelle
 * temporanee, con la banca dati di prova al posto di quella vera.
 */
class SalvataggiTest extends TestCase
{
    private string $radice;

    private string $cartellaApp;

    private string $dest;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['rsync', 'pg_dump', 'pg_restore'] as $comando) {
            if (trim((string) shell_exec("command -v {$comando} 2>/dev/null")) === '') {
                $this->markTestSkipped("{$comando} non disponibile: la prova dei salvataggi non si puo' eseguire qui");
            }
        }

        $this->radice = sys_get_temp_dir().'/salvataggi-'.uniqid();
        $this->cartellaApp = $this->radice.'/app';
        $this->dest = $this->radice.'/backups';
        mkdir($this->cartellaApp.'/storage/app/photos', 0777, true);
        file_put_contents($this->cartellaApp.'/storage/app/photos/a.jpg', str_repeat('A', 4096));
        file_put_contents($this->cartellaApp.'/storage/app/photos/b.jpg', str_repeat('B', 2048));
    }

    protected function tearDown(): void
    {
        if (isset($this->radice) && is_dir($this->radice)) {
            exec('rm -rf '.escapeshellarg($this->radice));
        }
        parent::tearDown();
    }

    /** Il dump della banca dati di prova, con le credenziali della connessione dei test. */
    private function comandoDump(): string
    {
        $c = config('database.connections.pgsql');

        // Lo script esegue il comando spezzandolo sugli spazi, senza passare
        // da una shell: niente virgolette attorno ai singoli valori
        return sprintf('pg_dump --format=custom --no-owner -h %s -p %s -U %s %s',
            $c['host'], (string) $c['port'], $c['username'], $c['database']);
    }

    /** @return array{0: int, 1: string} esito e uscita dello script */
    private function esegui(string $stamp, array $ambiente = [], string $argomento = ''): array
    {
        $c = config('database.connections.pgsql');
        $env = array_merge([
            'WEBGIS_BACKUP_APP_DIR' => $this->cartellaApp,
            'WEBGIS_BACKUP_DEST' => $this->dest,
            'WEBGIS_BACKUP_CONF' => $this->radice.'/nessuna.conf',
            'WEBGIS_BACKUP_PG_DUMP' => $this->comandoDump(),
            'WEBGIS_BACKUP_STAMP' => $stamp,
            'WEBGIS_BACKUP_MB_MINIMI' => '1',
            'PGPASSWORD' => (string) $c['password'],
        ], $ambiente);

        $prefisso = '';
        foreach ($env as $k => $v) {
            $prefisso .= $k.'='.escapeshellarg($v).' ';
        }
        exec($prefisso.'bash '.escapeshellarg(base_path('deploy/backup.sh')).' '.$argomento.' 2>&1', $righe, $esito);

        return [$esito, implode("\n", $righe)];
    }

    public function test_salva_banca_dati_e_file_e_le_istantanee_condividono_i_file_immutati(): void
    {
        [$esito, $uscita] = $this->esegui('20260926-030000');
        $this->assertSame(0, $esito, $uscita);

        $dump = $this->dest.'/db/db-20260926-030000.dump';
        $this->assertFileExists($dump);
        $this->assertGreaterThan(1000, filesize($dump));
        // Il dump si rilegge davvero
        exec('pg_restore --list '.escapeshellarg($dump).' >/dev/null 2>&1', $o, $verifica);
        $this->assertSame(0, $verifica);

        $this->assertFileExists($this->dest.'/file/20260926-030000/photos/a.jpg');
        $this->assertFileExists($this->dest.'/file/20260926-030000/.completata');
        $this->assertSame('20260926-030000', readlink($this->dest.'/file/ultima'));

        // Seconda notte: un file nuovo, uno cancellato per sbaglio
        file_put_contents($this->cartellaApp.'/storage/app/photos/c.jpg', str_repeat('C', 1024));
        unlink($this->cartellaApp.'/storage/app/photos/b.jpg');
        [$esito, $uscita] = $this->esegui('20260927-030000');
        $this->assertSame(0, $esito, $uscita);

        $this->assertFileExists($this->dest.'/file/20260927-030000/photos/c.jpg');
        $this->assertFileDoesNotExist($this->dest.'/file/20260927-030000/photos/b.jpg');
        // ...ma la foto cancellata resta nell'istantanea di ieri
        $this->assertFileExists($this->dest.'/file/20260926-030000/photos/b.jpg');
        // Il file immutato e' lo stesso contenuto su disco (collegamento fisico), non una seconda copia
        $this->assertSame(
            fileinode($this->dest.'/file/20260926-030000/photos/a.jpg'),
            fileinode($this->dest.'/file/20260927-030000/photos/a.jpg'),
        );
        $this->assertSame('20260927-030000', readlink($this->dest.'/file/ultima'));
        $this->assertStringContainsString('copia fuori dal server non configurata', $uscita);
    }

    public function test_elimina_i_salvataggi_vecchi_compresi_quelli_nel_formato_di_prima(): void
    {
        mkdir($this->dest.'/db', 0700, true);
        mkdir($this->dest.'/file/20200101-000000/photos', 0700, true);
        file_put_contents($this->dest.'/file/20200101-000000/photos/vecchia.jpg', 'x');
        file_put_contents($this->dest.'/db/db-20200101-000000.dump', 'x');
        file_put_contents($this->dest.'/storage-20200101-0000.tar.gz', 'x');
        file_put_contents($this->dest.'/db-20200101-0000.dump', 'x');
        $vecchia = strtotime('2020-01-01');
        foreach (['/db/db-20200101-000000.dump', '/storage-20200101-0000.tar.gz', '/db-20200101-0000.dump'] as $f) {
            touch($this->dest.$f, $vecchia);
        }

        [$esito, $uscita] = $this->esegui('20260926-030000');
        $this->assertSame(0, $esito, $uscita);

        $this->assertDirectoryDoesNotExist($this->dest.'/file/20200101-000000');
        $this->assertFileDoesNotExist($this->dest.'/db/db-20200101-000000.dump');
        $this->assertFileDoesNotExist($this->dest.'/storage-20200101-0000.tar.gz');
        $this->assertFileDoesNotExist($this->dest.'/db-20200101-0000.dump');
        $this->assertFileExists($this->dest.'/db/db-20260926-030000.dump');
        $this->assertStringContainsString("eliminata l'istantanea 20200101-000000", $uscita);
    }

    public function test_senza_spazio_non_parte_e_lo_dice(): void
    {
        [$esito, $uscita] = $this->esegui('20260926-030000', ['WEBGIS_BACKUP_MB_MINIMI' => '999999999']);

        $this->assertNotSame(0, $esito);
        $this->assertStringContainsString('spazio insufficiente', $uscita);
        $this->assertDirectoryDoesNotExist($this->dest.'/file/20260926-030000');
        $this->assertFileDoesNotExist($this->dest.'/db/db-20260926-030000.dump');
    }

    public function test_senza_rsync_la_banca_dati_si_salva_lo_stesso_e_l_errore_si_vede(): void
    {
        [$esito, $uscita] = $this->esegui('20260926-030000', ['WEBGIS_BACKUP_RSYNC' => 'rsync-che-non-esiste']);

        $this->assertNotSame(0, $esito);
        $this->assertFileExists($this->dest.'/db/db-20260926-030000.dump');
        $this->assertDirectoryDoesNotExist($this->dest.'/file/20260926-030000');
        $this->assertStringContainsString('rsync non installato', $uscita);
        $this->assertStringContainsString('la banca dati e\' salvata', $uscita);
    }

    public function test_la_copia_fuori_dal_server_si_accende_dal_file_di_configurazione(): void
    {
        $remoto = $this->radice.'/altro-server';
        mkdir($remoto, 0700, true);
        file_put_contents($this->radice.'/webgis-backup.conf', 'BACKUP_REMOTO='.$remoto."\n");

        [$esito, $uscita] = $this->esegui('20260926-030000', ['WEBGIS_BACKUP_CONF' => $this->radice.'/webgis-backup.conf']);
        $this->assertSame(0, $esito, $uscita);

        $this->assertFileExists($remoto.'/db/db-20260926-030000.dump');
        $this->assertFileExists($remoto.'/file/20260926-030000/photos/a.jpg');
        $this->assertStringContainsString('copia fuori dal server aggiornata', $uscita);
    }

    public function test_lo_stato_racconta_l_ultimo_salvataggio(): void
    {
        [, $prima] = $this->esegui('20260926-030000', [], 'stato');
        $this->assertStringContainsString('nessun salvataggio trovato', $prima);

        $this->esegui('20260926-030000');
        [$esito, $dopo] = $this->esegui('20260926-030000', [], 'stato');

        $this->assertSame(0, $esito, $dopo);
        $this->assertStringContainsString('banca dati: db-20260926-030000.dump', $dopo);
        $this->assertStringContainsString('istantanea 20260926-030000', $dopo);
        $this->assertStringContainsString('NON configurata', $dopo);
    }
}
