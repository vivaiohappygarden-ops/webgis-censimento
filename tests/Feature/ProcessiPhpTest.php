<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Dimensionamento dei processi PHP del server.
 *
 * Il pacchetto di Ubuntu ne prevede cinque: una pagina del gestionale ne
 * chiede di più tutte insieme, le altre si accodano e a volte scadono.
 */
class ProcessiPhpTest extends TestCase
{
    public function test_su_un_server_piccolo_i_processi_salgono_almeno_a_otto(): void
    {
        $conf = $this->esegui(ramMb: 1024);

        $this->assertSame(8, $this->valore($conf, 'pm.max_children'));
        $this->assertSame('dynamic', (string) $this->valore($conf, 'pm'));
    }

    public function test_i_processi_crescono_con_la_memoria(): void
    {
        $piccolo = $this->valore($this->esegui(ramMb: 2048), 'pm.max_children');
        $grande = $this->valore($this->esegui(ramMb: 4096), 'pm.max_children');

        $this->assertGreaterThan($piccolo, $grande);
    }

    public function test_oltre_un_certo_punto_non_si_sale_piu(): void
    {
        // Più avanti il collo di bottiglia è il database, non PHP: continuare
        // ad aggiungere processi consumerebbe memoria senza servire a nulla
        $this->assertSame(48, $this->valore($this->esegui(ramMb: 65536), 'pm.max_children'));
    }

    public function test_le_scorte_restano_coerenti_fra_loro(): void
    {
        $conf = $this->esegui(ramMb: 4096);

        $massimo = $this->valore($conf, 'pm.max_children');
        $this->assertLessThanOrEqual($massimo, $this->valore($conf, 'pm.start_servers'));
        $this->assertLessThanOrEqual($massimo, $this->valore($conf, 'pm.max_spare_servers'));
        $this->assertLessThanOrEqual(
            $this->valore($conf, 'pm.max_spare_servers'),
            $this->valore($conf, 'pm.min_spare_servers'),
        );
    }

    public function test_rilanciarlo_non_duplica_le_righe(): void
    {
        $file = $this->configurazioneDiPartenza();
        $this->esegui(ramMb: 4096, file: $file);
        $conf = $this->esegui(ramMb: 4096, file: $file);

        $this->assertSame(1, substr_count($conf, "\npm.max_children ="));
        $this->assertSame(1, substr_count($conf, "\npm.max_requests ="));
    }

    private function configurazioneDiPartenza(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'webgis-fpm-');
        // Gli stessi valori con cui esce il pacchetto di Ubuntu
        file_put_contents($file, implode("\n", [
            '[www]',
            'user = www-data',
            'pm = dynamic',
            'pm.max_children = 5',
            'pm.start_servers = 2',
            'pm.min_spare_servers = 1',
            'pm.max_spare_servers = 3',
            ';pm.max_requests = 500',
            '',
        ]));

        return $file;
    }

    private function esegui(int $ramMb, ?string $file = null): string
    {
        $file ??= $this->configurazioneDiPartenza();

        $comando = sprintf(
            'WEBGIS_PROVA=1 WEBGIS_RAM_MB=%d WEBGIS_FPM_CONF=%s bash %s 2>&1',
            $ramMb,
            escapeshellarg($file),
            escapeshellarg(base_path('deploy/php-fpm-config.sh')),
        );

        exec($comando, $righe, $esito);
        $this->assertSame(0, $esito, "Lo script non è andato a buon fine:\n".implode("\n", $righe));

        return (string) file_get_contents($file);
    }

    private function valore(string $conf, string $chiave): int|string
    {
        preg_match('/^'.preg_quote($chiave, '/').'\s*=\s*(\S+)$/m', $conf, $trovato);
        $this->assertNotEmpty($trovato, "Chiave assente: {$chiave}");

        return is_numeric($trovato[1]) ? (int) $trovato[1] : $trovato[1];
    }
}
