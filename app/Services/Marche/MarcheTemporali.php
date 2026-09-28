<?php

namespace App\Services\Marche;

use App\Http\Controllers\Api\V1\PdfController;
use App\Http\Controllers\Api\V1\PeriziaController;
use App\Http\Controllers\Api\V1\PhytoTreatmentController;
use App\Http\Controllers\Api\V1\RelazioneAnnualeController;
use App\Http\Controllers\Api\V1\TreeBalanceController;
use App\Models\Inspection;
use App\Models\MarcaTemporale;
use App\Models\Organization;
use App\Models\TreeAssessment;
use App\Models\User;
use App\Services\Maps\StaticMap;
use App\Services\Pdf\PdfRenderer;
use App\Support\Audit;
use App\Support\Rfc3161;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Process;

/**
 * Marche temporali sui documenti chiusi.
 *
 * Il programma produce il PDF con lo stesso codice della stampa, ne calcola
 * l'impronta SHA-256, la manda alla TSA (RFC 3161) e conserva insieme il PDF
 * esatto e il gettone ricevuto: la marca vale per quei byte, e una ristampa
 * potrebbe non essere identica.
 *
 * Le marche le vende DAMA ai clienti a pacchetti: il pacchetto (quante marche
 * comprende) lo assegna la console della piattaforma a ogni organizzazione,
 * e ogni organizzazione consuma solo il suo. Le credenziali dell'account e
 * il tetto giornaliero stanno anch'essi nell'organizzazione.
 */
class MarcheTemporali
{
    public const TIPI = [
        'perizia' => 'Perizia di stabilità',
        'verbale' => 'Verbale di ispezione',
        'registro_fitosanitari' => 'Registro dei trattamenti fitosanitari',
        'bilancio_arboreo' => 'Bilancio arboreo',
        'relazione_annuale' => 'Relazione annuale del verde',
    ];

    /** Chi puo' stampare un documento puo' anche marcarlo: lo stesso permesso della sua pagina. */
    public const PERMESSI = [
        'perizia' => 'assets.view',
        'bilancio_arboreo' => 'assets.view',
        'verbale' => 'works.view',
        'registro_fitosanitari' => 'works.view',
        'relazione_annuale' => 'works.view',
    ];

    public const CARTELLA = 'marche';

    // ---- Configurazione ------------------------------------------------------

    /**
     * Le credenziali dell'organizzazione, e solo le sue: chi affitta la
     * piattaforma a un'altra azienda non deve vedersi consumare il proprio
     * lotto, quindi non esiste un ripiego su un account comune. Senza
     * credenziali le marche sono spente.
     *
     * @return array{attiva:bool, url:?string, utente:?string, password:?string, policy:?string, quota_giorno:int, pacchetto:?int, timeout:int, account:?string}
     */
    public function configurazione(string $tenantId): array
    {
        $base = config('marche');
        $propria = Organization::query()->find($tenantId)?->settings['marche'] ?? [];
        $pacchetto = isset($propria['pacchetto']) && $propria['pacchetto'] !== '' ? (int) $propria['pacchetto'] : null;
        if (empty($propria['utente']) || empty($propria['password_cifrata'])) {
            return ['attiva' => false, 'url' => null, 'utente' => null, 'password' => null, 'policy' => null,
                'quota_giorno' => (int) $base['quota_giorno'], 'pacchetto' => $pacchetto, 'timeout' => (int) $base['timeout'], 'account' => null];
        }
        try {
            $password = Crypt::decryptString($propria['password_cifrata']);
        } catch (DecryptException) {
            throw new MarcaTemporaleException("La password del servizio di marcatura non si legge più (è cambiata la chiave dell'applicazione): va reinserita da Documenti.");
        }
        $url = $propria['url'] ?: $base['url'];

        return [
            'attiva' => true,
            'url' => $url,
            'utente' => $propria['utente'],
            'password' => $password,
            'policy' => $propria['policy'] ?: null,
            'quota_giorno' => isset($propria['quota_giorno']) && $propria['quota_giorno'] !== null && $propria['quota_giorno'] !== ''
                ? (int) $propria['quota_giorno'] : (int) $base['quota_giorno'],
            'pacchetto' => $pacchetto,
            'timeout' => (int) $base['timeout'],
            'account' => self::account($url, $propria['utente']),
        ];
    }

    /**
     * Salva credenziali e regolazioni nelle impostazioni dell'organizzazione,
     * sotto lock come le altre impostazioni (nella stessa colonna vive il
     * contatore dei protocolli). Il pacchetto lo tocca solo chi lo passa
     * esplicitamente: la console della piattaforma, non l'organizzazione.
     *
     * @param  array{url?:?string, utente?:?string, password?:?string, policy?:?string, quota_giorno?:?int, pacchetto?:?int}  $dati
     */
    public function salva(Organization $organizzazione, array $dati, string $azione = 'marche.configurazione'): Organization
    {
        return DB::transaction(function () use ($organizzazione, $dati, $azione) {
            $organizzazione = Organization::query()->lockForUpdate()->findOrFail($organizzazione->id);
            $settings = $organizzazione->settings ?? [];
            $prima = $settings['marche'] ?? [];
            $marche = $prima;
            if (array_key_exists('utente', $dati)) {
                if (($dati['password'] ?? '') === '' && empty($prima['password_cifrata'])) {
                    throw ValidationException::withMessages(['password' => "Indicare la password dell'account di marcatura."]);
                }
                $marche['url'] = trim((string) (($dati['url'] ?? null) ?: config('marche.url')));
                $marche['utente'] = trim((string) $dati['utente']);
                $marche['password_cifrata'] = ($dati['password'] ?? '') !== '' ? Crypt::encryptString($dati['password']) : $prima['password_cifrata'];
                $marche['policy'] = ($dati['policy'] ?? '') !== '' ? trim((string) $dati['policy']) : null;
                $marche['quota_giorno'] = $dati['quota_giorno'] ?? null;
            }
            if (array_key_exists('pacchetto', $dati)) {
                $marche['pacchetto'] = $dati['pacchetto'];
            }
            $settings['marche'] = $marche;
            $organizzazione->forceFill(['settings' => $settings])->save();
            Audit::log($azione, $organizzazione, array_filter([
                'servizio' => isset($marche['url']) ? parse_url($marche['url'], PHP_URL_HOST) : null,
                'utente' => isset($marche['utente']) ? self::mascherato($marche['utente']) : null,
                'password_cambiata' => array_key_exists('utente', $dati) && ($dati['password'] ?? '') !== '',
                'pacchetto' => array_key_exists('pacchetto', $dati) ? ($dati['pacchetto'] ?? 'nessuno') : null,
            ], fn ($v) => $v !== null && $v !== false));

            return $organizzazione;
        });
    }

    /** Toglie le credenziali; il pacchetto assegnato dalla piattaforma resta. */
    public function togliCredenziali(Organization $organizzazione): Organization
    {
        return DB::transaction(function () use ($organizzazione) {
            $organizzazione = Organization::query()->lockForUpdate()->findOrFail($organizzazione->id);
            $settings = $organizzazione->settings ?? [];
            $pacchetto = $settings['marche']['pacchetto'] ?? null;
            $settings['marche'] = $pacchetto !== null ? ['pacchetto' => $pacchetto] : [];
            if ($settings['marche'] === []) {
                unset($settings['marche']);
            }
            $organizzazione->forceFill(['settings' => $settings])->save();
            Audit::log('marche.configurazione', $organizzazione, ['tolta' => true]);

            return $organizzazione;
        });
    }

    /** La stessa impronta per lo stesso account, qualunque organizzazione lo usi. */
    public static function account(string $url, string $utente): string
    {
        return hash('sha256', $url.'|'.$utente);
    }

    /** Quello che la pagina puo' dire dello stato, senza segreti. */
    public function stato(string $tenantId): array
    {
        $configurazione = $this->configurazione($tenantId);
        $catena = config('marche.catena');

        $totale = $this->totale($tenantId);
        $pacchetto = $configurazione['pacchetto'];

        return [
            'attiva' => $configurazione['attiva'],
            'servizio' => $configurazione['url'] ? (parse_url($configurazione['url'], PHP_URL_HOST) ?: $configurazione['url']) : null,
            'utente' => $configurazione['utente'] ? self::mascherato($configurazione['utente']) : null,
            'quota_giorno' => $configurazione['quota_giorno'],
            'usate_oggi' => $this->usateOggi($tenantId),
            'totale' => $totale,
            // Il pacchetto venduto dalla piattaforma: quante ne comprende e quante ne restano
            'pacchetto' => $pacchetto,
            'restanti' => $pacchetto !== null ? max($pacchetto - $totale, 0) : null,
            'esaurito' => $pacchetto !== null && $totale >= $pacchetto,
            'verifica_firma' => is_string($catena) && $catena !== '' && is_file($catena),
        ];
    }

    public static function mascherato(string $utente): string
    {
        $lunghezza = mb_strlen($utente);
        if ($lunghezza <= 4) {
            return mb_substr($utente, 0, 1).str_repeat('*', max($lunghezza - 1, 1));
        }

        return mb_substr($utente, 0, 2).str_repeat('*', min($lunghezza - 4, 8)).mb_substr($utente, -2);
    }

    /** Marche apposte oggi (giorno italiano) dall'organizzazione. */
    public function usateOggi(string $tenantId): int
    {
        return MarcaTemporale::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', Carbon::now('Europe/Rome')->startOfDay()->utc())
            ->count();
    }

    /** Tutte le marche apposte dall'organizzazione: e' quello che consuma il pacchetto. */
    public function totale(string $tenantId): int
    {
        return MarcaTemporale::withoutGlobalScopes()->where('tenant_id', $tenantId)->count();
    }

    // ---- Apposizione ---------------------------------------------------------

    /**
     * Produce il documento, chiede la marca e conserva tutto.
     *
     * @param  array<string,mixed>  $parametri  anno, committente, periodo per i registri
     */
    public function applica(User $utente, string $tipo, ?string $soggettoId, array $parametri = []): MarcaTemporale
    {
        if (! isset(self::TIPI[$tipo])) {
            throw new MarcaTemporaleException('Tipo di documento non previsto per la marca temporale.');
        }
        if (! $utente->can(self::PERMESSI[$tipo])) {
            throw new AuthorizationException('Questo utente non può produrre il documento da marcare.');
        }

        $configurazione = $this->configurazione($utente->tenant_id);
        if (! $configurazione['attiva']) {
        if (! $configurazione['attiva']) {
            throw new MarcaTemporaleException("Le marche temporali di questa organizzazione non sono ancora attive. Per attivarle serve un pacchetto di marche, che potete richiedere alla nostra assistenza; se avete gia' un vostro account di marcatura temporale, le credenziali si inseriscono in Documenti da chi gestisce gli utenti.");
        }
        }
        if ($configurazione['pacchetto'] !== null && $this->totale($utente->tenant_id) >= $configurazione['pacchetto']) {
            throw new MarcaTemporaleException("Il pacchetto di marche di questa organizzazione è esaurito ({$configurazione['pacchetto']} su {$configurazione['pacchetto']}): per rinnovarlo potete rivolgervi alla nostra assistenza.");
        }
        if ($configurazione['quota_giorno'] > 0 && $this->usateOggi($utente->tenant_id) >= $configurazione['quota_giorno']) {
            throw new MarcaTemporaleException("Per oggi le marche sono finite ({$configurazione['quota_giorno']} al giorno per questa organizzazione): la prossima si può apporre domani.");
        }

        $documento = $this->documento($utente, $tipo, $soggettoId, $parametri);
        $impronta = hash('sha256', $documento['pdf'], true);
        $nonce = random_bytes(8);
        $richiesta = Rfc3161::richiesta($impronta, $nonce, $configurazione['policy']);
        $rispostaGrezza = $this->interroga($configurazione, $richiesta);

        try {
            $risposta = Rfc3161::risposta($rispostaGrezza);
        } catch (InvalidArgumentException $e) {
            throw new MarcaTemporaleException('La risposta del servizio di marcatura non si legge ('.$e->getMessage().'): controlla indirizzo e credenziali.');
        }
        if (! in_array($risposta['stato'], [0, 1], true) || $risposta['tst'] === null) {
            $dettagli = array_filter([$risposta['motivo'], $risposta['fallimento']]);
            throw new MarcaTemporaleException('Il servizio di marcatura ha risposto "'.$risposta['stato_testo'].'"'
                .($dettagli ? ': '.implode(' · ', $dettagli) : '').'. Nessuna marca è stata consumata.');
        }
        $tst = $risposta['tst'];
        if ($tst['algoritmo'] !== Rfc3161::OID_SHA256 || ! hash_equals(bin2hex($impronta), $tst['impronta'])) {
            throw new MarcaTemporaleException("La marca ricevuta non corrisponde al documento inviato (impronta diversa): non è stata conservata.");
        }
        if ($tst['nonce'] === null || ! hash_equals(Rfc3161::esadecimale($nonce), $tst['nonce'])) {
            throw new MarcaTemporaleException("La marca ricevuta risponde a un'altra richiesta (numero di controllo diverso): non è stata conservata.");
        }

        $firmatario = Rfc3161::firmatario($risposta['certificati']);
        $id = (string) Str::uuid();
        $cartella = self::CARTELLA.'/'.$utente->tenant_id;
        $disco = Storage::disk();
        $disco->put("{$cartella}/{$id}.pdf", $documento['pdf']);
        $disco->put("{$cartella}/{$id}.tsr", $rispostaGrezza);

        $marca = MarcaTemporale::create([
            'id' => $id,
            'tenant_id' => $utente->tenant_id,
            'tipo' => $tipo,
            'soggetto_id' => $documento['soggetto_id'],
            'parametri' => $documento['parametri'] ?: null,
            'titolo' => $documento['titolo'],
            'nome_file' => $documento['nome_file'],
            'sha256' => bin2hex($impronta),
            'dimensione' => strlen($documento['pdf']),
            'percorso_pdf' => "{$cartella}/{$id}.pdf",
            'percorso_marca' => "{$cartella}/{$id}.tsr",
            'generato_il' => $tst['generato_il'],
            'seriale' => $tst['seriale'],
            'tsa' => $tst['tsa'] ?? ($firmatario ? trim(($firmatario['nome'] ?? '').($firmatario['organizzazione'] ? ' ('.$firmatario['organizzazione'].')' : '')) : null),
            'policy' => $tst['policy'],
            'servizio' => parse_url($configurazione['url'], PHP_URL_HOST) ?: $configurazione['url'],
            'account' => $configurazione['account'],
            'richiesta_da' => $utente->id,
        ]);

        Audit::log('marca.applicata', $marca, [
            'tipo' => $tipo,
            'soggetto_id' => $documento['soggetto_id'],
            'titolo' => $documento['titolo'],
            'sha256' => $marca->sha256,
            'generato_il' => $marca->generato_il->toIso8601String(),
            'seriale' => $marca->seriale,
            'tsa' => $marca->tsa,
            'servizio' => $marca->servizio,
        ]);

        return $marca;
    }

    /** La chiamata HTTP alla TSA: corpo DER, credenziali in Basic, risposta DER. */
    private function interroga(array $configurazione, string $richiesta): string
    {
        $servizio = parse_url($configurazione['url'], PHP_URL_HOST) ?: $configurazione['url'];
        try {
            $risposta = Http::withOptions(['timeout' => $configurazione['timeout'], 'connect_timeout' => 10])
                ->withBasicAuth($configurazione['utente'], $configurazione['password'])
                ->withBody($richiesta, 'application/timestamp-query')
                ->accept('application/timestamp-reply')
                ->post($configurazione['url']);
        } catch (ConnectionException $e) {
            throw new MarcaTemporaleException("Il servizio di marcatura ({$servizio}) non risponde: ".$e->getMessage());
        }
        if (in_array($risposta->status(), [401, 403], true)) {
            throw new MarcaTemporaleException("Il servizio di marcatura ({$servizio}) ha rifiutato le credenziali (errore {$risposta->status()}): controlla nome utente e password dell'account.");
        }
        if (! $risposta->successful()) {
            throw new MarcaTemporaleException("Il servizio di marcatura ({$servizio}) ha risposto con l'errore {$risposta->status()}: riprova più tardi.");
        }
        if ($risposta->body() === '') {
            throw new MarcaTemporaleException("Il servizio di marcatura ({$servizio}) ha risposto senza contenuto.");
        }

        return $risposta->body();
    }

    // ---- Il documento --------------------------------------------------------

    /**
     * Il PDF da marcare, prodotto dallo stesso codice della stampa: per le
     * perizie e i verbali il documento deve essere chiuso; i registri si
     * producono con i loro parametri e la copia marcata diventa il documento.
     *
     * @return array{titolo:string, nome_file:string, pdf:string, soggetto_id:?string, parametri:array}
     */
    private function documento(User $utente, string $tipo, ?string $soggettoId, array $parametri): array
    {
        $renderer = app(PdfRenderer::class);

        switch ($tipo) {
            case 'perizia':
                $perizia = TreeAssessment::query()->with('tree')->findOrFail($soggettoId);
                if ($perizia->validated_at === null) {
                    throw new MarcaTemporaleException('Si marca solo una perizia validata: validala dallo scadenzario VTA (la validazione fissa protocollo e impronta) e poi apponi la marca.');
                }
                $risposta = app(PeriziaController::class)->pdf(
                    $this->richiesta($utente, "/api/v1/assessments/{$perizia->id}/perizia-pdf"), $perizia->id, $renderer, app(StaticMap::class),
                );
                $codice = $perizia->tree?->asset?->census_code ?? null;

                return $this->esito($risposta, 'Perizia '.$perizia->report_number.($codice ? ' · '.$codice : ''), $perizia->id, []);

            case 'verbale':
                $ispezione = Inspection::query()->with(['template:id,name', 'asset' => fn ($q) => $q->withTrashed()->select('id', 'census_code'), 'area' => fn ($q) => $q->withTrashed()->select('id', 'name')])->findOrFail($soggettoId);
                if ($ispezione->completed_at === null) {
                    throw new MarcaTemporaleException("Si marca solo un verbale di ispezione chiuso: quest'ispezione è ancora aperta.");
                }
                $risposta = app(PdfController::class)->inspection($this->richiesta($utente, "/api/v1/inspections/{$ispezione->id}/pdf"), $renderer, $ispezione->id);
                $bersaglio = $ispezione->asset?->census_code ?? $ispezione->area?->name;

                return $this->esito($risposta, 'Verbale di ispezione · '.($ispezione->template?->name ?? 'controllo').($bersaglio ? ' · '.$bersaglio : '')
                    .' · '.$ispezione->completed_at->timezone('Europe/Rome')->format('d/m/Y'), $ispezione->id, []);

            case 'registro_fitosanitari':
                $anno = $this->anno($parametri);
                $query = array_filter(['year' => $anno, 'area_id' => $parametri['area_id'] ?? null]);
                $risposta = app(PhytoTreatmentController::class)->registerPdf($this->richiesta($utente, '/api/v1/phyto-treatments/register-pdf', $query), $renderer);

                return $this->esito($risposta, "Registro dei trattamenti fitosanitari {$anno}", null, $query);

            case 'bilancio_arboreo':
                $anno = $this->anno($parametri);
                $query = array_filter(['from' => "{$anno}-01-01", 'to' => "{$anno}-12-31", 'client_id' => $parametri['client_id'] ?? null]);
                $risposta = app(TreeBalanceController::class)->pdf($this->richiesta($utente, '/api/v1/vta/bilancio/pdf', $query), $renderer);

                return $this->esito($risposta, "Bilancio arboreo {$anno}".$this->committente($parametri), null, [...$query, 'anno' => $anno]);

            case 'relazione_annuale':
                $anno = $this->anno($parametri);
                $query = array_filter(['client_id' => $parametri['client_id'] ?? null, 'anno' => $anno]);
                $risposta = app(RelazioneAnnualeController::class)->pdf($this->richiesta($utente, '/api/v1/reports/relazione-annuale/pdf', $query), $renderer);

                return $this->esito($risposta, "Relazione annuale del verde {$anno}".$this->committente($parametri), null, $query);
        }

        throw new MarcaTemporaleException('Tipo di documento non previsto per la marca temporale.');
    }

    /** Una richiesta interna con l'utente che chiede la marca: i controller leggono da qui parametri e permessi. */
    private function richiesta(User $utente, string $percorso, array $query = []): Request
    {
        $richiesta = Request::create($percorso, 'GET', $query);
        $richiesta->setUserResolver(fn () => $utente);

        return $richiesta;
    }

    private function esito(Response $risposta, string $titolo, ?string $soggettoId, array $parametri): array
    {
        $pdf = (string) $risposta->getContent();
        if ($pdf === '') {
            throw new MarcaTemporaleException('Il documento da marcare è risultato vuoto.');
        }
        preg_match('/filename="?([^";]+)"?/', (string) $risposta->headers->get('Content-Disposition'), $m);

        return [
            'titolo' => $titolo,
            'nome_file' => $m[1] ?? Str::slug($titolo).'.pdf',
            'pdf' => $pdf,
            'soggetto_id' => $soggettoId,
            'parametri' => $parametri,
        ];
    }

    private function anno(array $parametri): int
    {
        $anno = (int) ($parametri['anno'] ?? 0);
        if ($anno < 2000 || $anno > 2100) {
            throw new MarcaTemporaleException("Indicare l'anno del documento da marcare.");
        }

        return $anno;
    }

    private function committente(array $parametri): string
    {
        if (empty($parametri['client_id'])) {
            return '';
        }
        $nome = \App\Models\Client::query()->whereKey($parametri['client_id'])->value('name');

        return $nome ? ' · '.$nome : '';
    }

    // ---- Verifica ------------------------------------------------------------

    /**
     * Controlla che il PDF conservato sia ancora quello marcato (impronta), che
     * il gettone parli proprio di quell'impronta e, se sul server c'e' la catena
     * dei certificati della TSA, anche la firma con openssl.
     */
    public function verifica(MarcaTemporale $marca): array
    {
        $disco = Storage::disk();
        $esito = [
            'file_presente' => $disco->exists($marca->percorso_pdf) && $disco->exists($marca->percorso_marca),
            'impronta_coincide' => false,
            'marca_coerente' => false,
            'firma' => 'non_controllata',
            'firma_dettaglio' => null,
            'generato_il' => $marca->generato_il->toIso8601String(),
            'generato_il_locale' => $marca->generato_il->timezone('Europe/Rome')->format('d/m/Y H:i:s'),
            'seriale' => $marca->seriale,
            'tsa' => $marca->tsa,
            'firmatario' => null,
            'valida' => false,
        ];
        if (! $esito['file_presente']) {
            $esito['firma_dettaglio'] = 'Sul server manca il PDF conservato o il gettone della marca.';

            return $esito;
        }

        $impronta = hash('sha256', (string) $disco->get($marca->percorso_pdf));
        $esito['impronta_coincide'] = hash_equals($marca->sha256, $impronta);
        try {
            $risposta = Rfc3161::risposta((string) $disco->get($marca->percorso_marca));
            $esito['marca_coerente'] = $risposta['tst'] !== null && hash_equals($risposta['tst']['impronta'], $impronta);
            $firmatario = Rfc3161::firmatario($risposta['certificati']);
            if ($firmatario) {
                $esito['firmatario'] = array_filter([
                    'nome' => $firmatario['nome'], 'organizzazione' => $firmatario['organizzazione'], 'emittente' => $firmatario['emittente'],
                    'scade_il' => $firmatario['scade_il']?->timezone('Europe/Rome')->format('d/m/Y'),
                ]);
            }
        } catch (InvalidArgumentException $e) {
            $esito['firma_dettaglio'] = 'Il gettone conservato non si legge: '.$e->getMessage();
        }

        $catena = config('marche.catena');
        if (is_string($catena) && $catena !== '' && is_file($catena)) {
            $processo = new Process([config('marche.openssl', 'openssl'), 'ts', '-verify', '-digest', $impronta,
                '-in', $disco->path($marca->percorso_marca), '-CAfile', $catena]);
            $processo->setTimeout(30);
            try {
                $processo->run();
                $ok = $processo->isSuccessful() && str_contains($processo->getOutput(), 'Verification: OK');
                $esito['firma'] = $ok ? 'verificata' : 'non_valida';
                $esito['firma_dettaglio'] = $ok ? 'Firma della TSA verificata con la catena dei certificati presente sul server.'
                    : trim(preg_replace('/\s+/', ' ', $processo->getErrorOutput().' '.$processo->getOutput()));
            } catch (\Throwable $e) {
                $esito['firma_dettaglio'] = 'Il controllo della firma con openssl non è riuscito: '.$e->getMessage();
            }
        } elseif ($esito['firma_dettaglio'] === null) {
            $esito['firma_dettaglio'] = "Sul server non c'è la catena dei certificati della TSA (MARCHE_CATENA): la firma si controlla fuori dal programma, con il file .tsr e il PDF.";
        }

        $esito['valida'] = $esito['impronta_coincide'] && $esito['marca_coerente'] && $esito['firma'] !== 'non_valida';

        return $esito;
    }
}
