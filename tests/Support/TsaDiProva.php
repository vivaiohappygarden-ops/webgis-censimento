<?php

namespace Tests\Support;

use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Una TSA di prova, finta ma vera: autorita' e certificato di marcatura
 * creati con openssl in una cartella temporanea (una volta per processo), e
 * risposte RFC 3161 firmate davvero. Le prove parlano il protocollo per
 * intero senza toccare un fornitore ne' consumare marche.
 */
final class TsaDiProva
{
    private static ?string $cartella = null;

    private static ?string $openssl = null;

    public static function disponibile(): bool
    {
        self::$openssl ??= (new ExecutableFinder)->find('openssl') ?? '';

        return self::$openssl !== '';
    }

    /** Il file PEM con la catena (qui la sola autorita') per la verifica della firma. */
    public static function catena(): string
    {
        self::prepara();

        return self::$cartella.'/ca.pem';
    }

    /** La risposta della TSA (TimeStampResp in DER) a una richiesta in DER. */
    public static function rispondi(string $richiesta): string
    {
        self::prepara();
        $file = tempnam(self::$cartella, 'req');
        $uscita = $file.'.tsr';
        file_put_contents($file, $richiesta);
        $processo = new Process([self::$openssl, 'ts', '-reply', '-config', self::$cartella.'/tsa.cnf', '-queryfile', $file, '-out', $uscita], self::$cartella);
        $processo->run();
        if (! is_file($uscita) || filesize($uscita) === 0) {
            throw new RuntimeException('openssl ts -reply non ha prodotto la risposta: '.$processo->getErrorOutput());
        }
        $risposta = (string) file_get_contents($uscita);
        @unlink($file);
        @unlink($uscita);

        return $risposta;
    }

    private static function prepara(): void
    {
        if (self::$cartella !== null) {
            return;
        }
        if (! self::disponibile()) {
            throw new RuntimeException('openssl non disponibile: la TSA di prova non si puo\' creare.');
        }
        $dir = rtrim(sys_get_temp_dir(), '/').'/webgis-tsa-'.getmypid();
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents("{$dir}/ca.cnf", <<<'CNF'
            [req]
            distinguished_name = dn
            x509_extensions = v3_ca
            prompt = no
            [dn]
            CN = Autorita di prova
            O = WebGIS test
            [v3_ca]
            basicConstraints = critical,CA:TRUE
            keyUsage = critical,keyCertSign,cRLSign
            subjectKeyIdentifier = hash
            CNF);
        file_put_contents("{$dir}/tsa.cnf", <<<CNF
            [req]
            distinguished_name = dn
            prompt = no
            [dn]
            CN = TSA di prova
            O = WebGIS test
            [tsa_ext]
            basicConstraints = CA:FALSE
            keyUsage = critical,digitalSignature,nonRepudiation
            extendedKeyUsage = critical,timeStamping
            subjectKeyIdentifier = hash
            authorityKeyIdentifier = keyid
            [tsa]
            default_tsa = tsa_config1
            [tsa_config1]
            dir = {$dir}
            serial = \$dir/tsaserial
            signer_cert = \$dir/tsa.pem
            certs = \$dir/ca.pem
            signer_key = \$dir/tsa.key
            signer_digest = sha256
            default_policy = 1.2.3.4.1
            other_policies = 1.2.3.4.5
            digests = sha256, sha384, sha512
            accuracy = secs:1, millisecs:0, microsecs:0
            clock_precision_digits = 0
            ordering = no
            tsa_name = yes
            ess_cert_id_chain = no
            ess_cert_id_alg = sha256
            CNF);
        $comandi = [
            ['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', 'ca.key', '-out', 'ca.pem', '-days', '3650', '-config', 'ca.cnf'],
            ['req', '-newkey', 'rsa:2048', '-nodes', '-keyout', 'tsa.key', '-out', 'tsa.csr', '-config', 'tsa.cnf'],
            ['x509', '-req', '-in', 'tsa.csr', '-CA', 'ca.pem', '-CAkey', 'ca.key', '-CAcreateserial', '-out', 'tsa.pem', '-days', '3650', '-extfile', 'tsa.cnf', '-extensions', 'tsa_ext'],
        ];
        foreach ($comandi as $argomenti) {
            $processo = new Process([self::$openssl, ...$argomenti], $dir);
            $processo->setTimeout(120);
            $processo->run();
            if (! $processo->isSuccessful()) {
                throw new RuntimeException('openssl '.$argomenti[0].' non riuscito: '.$processo->getErrorOutput());
            }
        }
        file_put_contents("{$dir}/tsaserial", "01\n");
        self::$cartella = $dir;
    }
}
