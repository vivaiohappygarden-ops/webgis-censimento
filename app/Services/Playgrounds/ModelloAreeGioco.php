<?php

namespace App\Services\Playgrounds;

use App\Models\CatalogObjectType;
use App\Models\ChecklistItem;
use App\Models\CustomField;
use App\Models\InspectionTemplate;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Il corredo pronto per le aree gioco: i campi della scheda dell'attrezzo e
 * le liste di controllo impostate sulla UNI EN 1176-7.
 *
 * Perche' esiste. Censire un'altalena si poteva gia' fare (il catalogo ha
 * "gioco singolo" e "gioco complesso") e le ispezioni pure, ma ogni volta
 * bisognava inventarsi i campi e riscrivere le domande. Qui c'e' una
 * versione di partenza scritta una volta, che il tecnico installa con un
 * gesto e poi adatta: i campi si modificano dal Catalogo, le domande dalla
 * pagina Ispezioni.
 *
 * Onesta' del contenuto: le liste NON sono il testo della norma, che e'
 * protetto e va acquistato. Sono la traccia dei controlli che la UNI EN
 * 1176-7 prevede - visivo ordinario, funzionale, principale annuale -
 * scritta in italiano corrente. Il modello dichiara la norma di
 * riferimento; l'ispezione principale annuale resta compito di personale
 * competente, e il programma non sostituisce quel giudizio.
 *
 * Regola di casa: anteprima ed esecuzione passano dallo stesso metodo, con
 * $prova a decidere se scrivere. Rilanciare non duplica niente: i campi
 * hanno chiave unica per tipo di oggetto, i modelli un codice unico per
 * tenant. Quello che c'e' gia' si dichiara "presente" e non si tocca, cosi'
 * un adattamento del tecnico non viene mai sovrascritto.
 */
class ModelloAreeGioco
{
    /** Tipi di catalogo dell'attrezzo ludico (Modello Dati v2.1). */
    public const CODICI_ATTREZZO = ['P214250', 'P214251'];

    /** Tipo di catalogo dell'area gioco come area funzionale. */
    public const CODICE_AREA = 'S327552';

    /** Campi della scheda di un attrezzo ludico. */
    private const CAMPI_ATTREZZO = [
        ['key' => 'produttore', 'label' => 'Produttore', 'field_type' => 'text'],
        ['key' => 'modello', 'label' => 'Modello o nome commerciale', 'field_type' => 'text'],
        ['key' => 'matricola', 'label' => 'Numero di serie o matricola', 'field_type' => 'text'],
        ['key' => 'installato_il', 'label' => 'Data di installazione', 'field_type' => 'date'],
        ['key' => 'collaudato_il', 'label' => 'Data del collaudo', 'field_type' => 'date'],
        ['key' => 'materiale', 'label' => 'Materiale prevalente', 'field_type' => 'select',
            'options' => ['legno', 'metallo', 'plastica', 'misto legno-metallo', 'altro']],
        ['key' => 'fascia_eta', 'label' => "Fascia d'eta'", 'field_type' => 'select',
            'options' => ['0-3 anni', '3-6 anni', '6-12 anni', 'oltre 12 anni', 'tutte le eta']],
        ['key' => 'altezza_caduta_m', 'label' => 'Altezza di caduta libera', 'field_type' => 'number',
            'unit' => 'm', 'validation' => ['min' => 0, 'max' => 10]],
        ['key' => 'area_impatto_mq', 'label' => 'Area di impatto', 'field_type' => 'number', 'unit' => 'mq'],
        ['key' => 'superficie_attenuazione', 'label' => 'Superficie di attenuazione', 'field_type' => 'select',
            'options' => ['erba', 'sabbia', 'ghiaia tonda', 'corteccia', 'gomma colata',
                'piastrelle antitrauma', 'erba sintetica', 'altro', 'assente']],
        ['key' => 'norme_riferimento', 'label' => 'Norme di riferimento', 'field_type' => 'multiselect',
            'options' => ['EN 1176-1', 'EN 1176-2', 'EN 1176-3', 'EN 1176-4', 'EN 1176-5',
                'EN 1176-6', 'EN 1176-7', 'EN 1176-11', 'EN 1177']],
        ['key' => 'gioco_inclusivo', 'label' => 'Utilizzabile da bambini con disabilita', 'field_type' => 'boolean'],
    ];

    /** Campi della scheda dell'area gioco. */
    private const CAMPI_AREA = [
        ['key' => 'fascia_eta_area', 'label' => "Fascia d'eta' dell'area", 'field_type' => 'select',
            'options' => ['0-3 anni', '3-6 anni', '6-12 anni', 'oltre 12 anni', 'tutte le eta']],
        ['key' => 'recinzione', 'label' => 'Area recintata', 'field_type' => 'boolean'],
        ['key' => 'cartello_informativo', 'label' => 'Cartello informativo presente', 'field_type' => 'boolean'],
        ['key' => 'accesso_disabili', 'label' => 'Accessibile a persone con disabilita', 'field_type' => 'boolean'],
        ['key' => 'gestore_emergenze', 'label' => 'Riferimento per le emergenze esposto', 'field_type' => 'text'],
    ];

    /**
     * Le tre ispezioni previste dalla UNI EN 1176-7, con le loro domande.
     * La periodicita' e' quella minima consigliata: si cambia dal modello,
     * perche' dipende da quanto l'area e' frequentata e vandalizzata.
     */
    private const MODELLI = [
        [
            'code' => 'GIOCO-VIS',
            'name' => 'Aree gioco - controllo visivo ordinario',
            'target' => 'area',
            'standard_ref' => 'UNI EN 1176-7 (controllo visivo ordinario)',
            'frequency_days' => 7,
            'domande' => [
                'Area pulita: nessun rifiuto, vetro, siringa o materiale pericoloso',
                'Superficie di attenuazione presente, distribuita e senza buche',
                'Nessuna parte rotta, mancante o smontata a vista',
                'Nessuno spigolo vivo, chiodo, vite o parte sporgente',
                'Corde, catene e funi integre e correttamente tese',
                'Ancoraggi e fondazioni non affioranti nell area di caduta',
                'Cartello informativo presente e leggibile',
                'Recinzione, cancelli e panchine integri',
                'Nessun ristagno d acqua nelle zone di impatto',
                'Vegetazione che non interferisce con giochi e passaggi',
            ],
        ],
        [
            'code' => 'GIOCO-FUN',
            'name' => 'Aree gioco - controllo funzionale',
            'target' => 'asset',
            'standard_ref' => 'UNI EN 1176-7 (controllo funzionale)',
            'frequency_days' => 90,
            'domande' => [
                'Struttura stabile: nessun gioco anomalo nei collegamenti',
                'Bulloni, viti e serraggi verificati e in ordine',
                'Punti di rotazione (altalene, giostre) con usura entro i limiti',
                'Corde, catene e moschettoni con usura entro i limiti',
                'Superfici di scivolo lisce, senza crepe o bordi taglienti',
                'Nessuna parte marcia, corrosa o fessurata',
                'Distanze e spazi liberi rispettati (intrappolamento di testa, collo, dita)',
                'Altezza di caduta libera invariata rispetto alla scheda',
                'Spessore della superficie di attenuazione verificato nei punti di impatto',
                'Segnaletica e marcatura del produttore ancora leggibili',
            ],
        ],
        [
            'code' => 'GIOCO-ANN',
            'name' => 'Aree gioco - ispezione principale annuale',
            'target' => 'area',
            'standard_ref' => 'UNI EN 1176-7 (ispezione principale annuale, personale competente)',
            'frequency_days' => 365,
            'domande' => [
                'Fondazioni e parti interrate: nessuna corrosione o marcescenza',
                'Integrita strutturale delle parti portanti',
                'Riparazioni e parti sostituite conformi all originale',
                'Attenuazione di impatto verificata rispetto all altezza di caduta (EN 1177)',
                'Distanze di sicurezza dell intera area verificate',
                'Cartellonistica conforme e aggiornata',
                'Registro delle ispezioni e delle manutenzioni aggiornato',
                'Documentazione del produttore disponibile per ogni attrezzo',
                'Giudizio complessivo di conformita dell area',
            ],
        ],
    ];

    /**
     * @return array{campi: list<array>, modelli: list<array>, mancanti: list<array>}
     */
    public function installa(string $tenantId, bool $prova = false): array
    {
        $campi = [];
        $modelli = [];
        $mancanti = [];

        DB::transaction(function () use ($tenantId, $prova, &$campi, &$modelli, &$mancanti) {
            $tipi = CatalogObjectType::query()
                ->whereIn('code', [...self::CODICI_ATTREZZO, self::CODICE_AREA])
                ->get()
                ->keyBy('code');

            foreach ([...self::CODICI_ATTREZZO, self::CODICE_AREA] as $codice) {
                if (! $tipi->has($codice)) {
                    // Un catalogo ridotto puo' non avere quel tipo: si dice e
                    // si va avanti, invece di fermare tutto
                    $mancanti[] = ['codice' => $codice,
                        'motivo' => 'Tipo di catalogo non presente: i suoi campi non si installano.'];
                }
            }

            foreach (self::CODICI_ATTREZZO as $codice) {
                $campi = [...$campi, ...$this->campiPerTipo($tipi->get($codice), self::CAMPI_ATTREZZO, $tenantId, $prova)];
            }
            $campi = [...$campi, ...$this->campiPerTipo($tipi->get(self::CODICE_AREA), self::CAMPI_AREA, $tenantId, $prova)];

            foreach (self::MODELLI as $definizione) {
                $modelli[] = $this->modello($definizione, $tenantId, $prova);
            }

            if (! $prova) {
                Audit::log('playground.model_installed', null, [
                    'campi' => count(array_filter($campi, fn ($c) => $c['stato'] === 'creato')),
                    'modelli' => count(array_filter($modelli, fn ($m) => $m['stato'] === 'creato')),
                ]);
            }
        });

        return ['campi' => $campi, 'modelli' => $modelli, 'mancanti' => $mancanti];
    }

    /**
     * @param  list<array>  $definizioni
     * @return list<array>
     */
    private function campiPerTipo(?CatalogObjectType $tipo, array $definizioni, string $tenantId, bool $prova): array
    {
        if ($tipo === null) {
            return [];
        }

        $esistenti = CustomField::query()->where('object_type_id', $tipo->id)->pluck('key')->all();
        $fatti = [];
        $ordine = (int) CustomField::query()->where('object_type_id', $tipo->id)->max('sort_order');

        foreach ($definizioni as $definizione) {
            $presente = in_array($definizione['key'], $esistenti, true);

            if (! $presente && ! $prova) {
                CustomField::create([
                    'tenant_id' => $tenantId,
                    'object_type_id' => $tipo->id,
                    'key' => $definizione['key'],
                    'label' => $definizione['label'],
                    'field_type' => $definizione['field_type'],
                    'options' => $definizione['options'] ?? [],
                    'validation' => $definizione['validation'] ?? [],
                    'unit' => $definizione['unit'] ?? null,
                    'sort_order' => ++$ordine,
                ]);
            }

            $fatti[] = [
                'tipo' => $tipo->code,
                'tipo_nome' => $tipo->name,
                'chiave' => $definizione['key'],
                'etichetta' => $definizione['label'],
                // "Presente" e' un esito, non un errore: il campo che il
                // tecnico ha gia' adattato non si tocca
                'stato' => $presente ? 'presente' : 'creato',
            ];
        }

        return $fatti;
    }

    private function modello(array $definizione, string $tenantId, bool $prova): array
    {
        $esistente = InspectionTemplate::query()->where('code', $definizione['code'])->first();

        if ($esistente === null && ! $prova) {
            $modello = InspectionTemplate::create([
                'tenant_id' => $tenantId,
                'code' => $definizione['code'],
                'name' => $definizione['name'],
                'target' => $definizione['target'],
                'standard_ref' => $definizione['standard_ref'],
                'frequency_days' => $definizione['frequency_days'],
                'is_active' => true,
            ]);

            foreach (array_values($definizione['domande']) as $i => $domanda) {
                ChecklistItem::create([
                    'tenant_id' => $tenantId,
                    'template_id' => $modello->id,
                    'sort_order' => $i + 1,
                    'question' => $domanda,
                    // "Non applicabile" serve: non tutte le domande valgono
                    // per tutti gli attrezzi di un'area
                    'answer_type' => 'ok_ko_na',
                    'ko_creates_nc' => true,
                    'photo_required_on_ko' => true,
                ]);
            }
        }

        return [
            'codice' => $definizione['code'],
            'nome' => $definizione['name'],
            'domande' => count($definizione['domande']),
            'periodicita_giorni' => $definizione['frequency_days'],
            'stato' => $esistente !== null ? 'presente' : 'creato',
        ];
    }
}
