<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Client;
use App\Services\Oggi\CoseDaFare;
use App\Services\Portale\PortalQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * La pagina Oggi della veste nuova (bozza A, 26/09/2026): un'unica lista di
 * cose da fare in ordine di urgenza, con il pulsante giusto su ogni riga, e
 * accanto quello che e' arrivato dal campo, i documenti da chiudere e lo
 * stato dei portali pubblici. Le definizioni delle scadenze sono quelle di
 * CoseDaFare: i numeri tornano con il cruscotto della veste precedente.
 *
 * Ogni sezione compare solo a chi ha il permesso di vederne la pagina.
 */
class OggiController extends Controller
{
    private const STATO_LAVORO = [
        'planned' => 'pianificato', 'assigned' => 'assegnato', 'in_progress' => 'in corso', 'suspended' => 'sospeso',
    ];

    private const GRAVITA = ['critical' => 'critica', 'high' => 'alta', 'medium' => 'media', 'low' => 'bassa'];

    private const GRAVITA_NC = ['minor' => 'lieve', 'major' => 'grave', 'critical' => 'critica'];

    private const ORDINE_URGENZA = ['ritardo' => 0, 'oggi' => 1, 'presto' => 2, 'programma' => 3];

    private const COMANDI_CAMPO = [
        'asset.create' => 'nuovo rilievo',
        'asset.update_measures' => 'misure aggiornate',
        'asset.update_geom' => 'posizione corretta',
        'area.create' => 'area nuova',
        'tag.associate' => 'cartellino associato',
        'inspection.complete' => 'ispezione compilata',
        'issue.create' => 'segnalazione dal campo',
        'work_log.add' => 'consuntivo',
        'work_order.transition' => 'lavoro avviato o chiuso',
    ];

    public function riepilogo(Request $request, CoseDaFare $cose): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('assets.view') || $user->can('works.view'), 403);

        $today = $cose->oggi();
        $voci = [];
        $conteggi = [
            'lavori_ritardo' => 0, 'lavori_settimana' => 0,
            'ispezioni_scadute' => 0, 'ispezioni_in_scadenza' => 0,
            'segnalazioni' => 0, 'non_conformita' => 0,
            'certificati_scaduti' => 0, 'certificati_in_scadenza' => 0,
            'vta_scaduti' => 0, 'vta_in_scadenza' => 0, 'vta_senza_ordine' => 0, 'vta_mai_valutati' => 0,
            'irrigazione' => 0,
        ];

        if ($user->can('works.view')) {
            $lavori = $cose->workOrders($today);
            $conteggi['lavori_ritardo'] = $lavori['overdue_count'];
            $conteggi['lavori_settimana'] = $lavori['week_count'];
            foreach ($lavori['overdue'] as $o) {
                $voci[] = $this->voce('lavoro', 'lavori', $o['id'], $o['code'].' · '.$o['title'],
                    [$o['area'], $o['team'], self::STATO_LAVORO[$o['status']] ?? $o['status'], 'doveva chiudersi il '.$this->data($o['planned_end'])],
                    'ritardo', $this->giorniDa($o['planned_end'], $today),
                    [['label' => 'Apri', 'href' => '/lavori?ordine='.$o['code']]]);
            }
            foreach ($lavori['week'] as $o) {
                $iniziato = $o['planned_start'] <= $today->toDateString();
                $voci[] = $this->voce('lavoro', 'lavori', $o['id'], $o['code'].' · '.$o['title'],
                    [$o['area'], $o['team'], self::STATO_LAVORO[$o['status']] ?? $o['status'],
                        $iniziato ? 'in programma dal '.$this->data($o['planned_start']) : 'inizia il '.$this->data($o['planned_start'])],
                    $iniziato ? 'oggi' : 'programma', $this->giorniA($o['planned_start'], $today),
                    [['label' => 'Apri', 'href' => '/lavori?ordine='.$o['code']]]);
            }

            $ispezioni = $cose->inspections();
            $conteggi['ispezioni_scadute'] = $ispezioni['overdue_count'];
            $conteggi['ispezioni_in_scadenza'] = $ispezioni['due_soon_count'];
            foreach ($ispezioni['rows'] as $r) {
                $scaduta = $r['state'] === 'overdue';
                $voci[] = $this->voce('ispezione', 'controlli', $r['target_id'].':'.$r['template_id'], $r['template_name'],
                    [$r['target_label'], $scaduta
                        ? 'in ritardo di '.abs($r['days_left']).' '.$this->giorni(abs($r['days_left'])).' (dovuta il '.$this->data($r['due_date']).')'
                        : 'entro il '.$this->data($r['due_date'])],
                    $scaduta ? 'ritardo' : 'presto', abs($r['days_left']),
                    [['label' => 'Compila', 'href' => '/ispezioni']]);
            }

            $segnalazioni = $cose->issues();
            $conteggi['segnalazioni'] = $segnalazioni['count'];
            foreach ($segnalazioni['rows'] as $r) {
                $fase = $r['status'] === 'open' ? ($r['sla']['take_charge'] ?? null) : ($r['sla']['resolve'] ?? null);
                $verbo = $r['status'] === 'open' ? 'presa in carico' : 'risoluzione';
                $inRitardo = ($fase['state'] ?? null) === 'overdue';
                $giorni = $inRitardo ? (int) ($fase['days_late'] ?? 0) : $this->giorniA($fase['due_at'] ?? null, $today);
                $voci[] = $this->voce('segnalazione', 'segnalazioni', $r['id'], $r['code'].' · '.$r['description'],
                    ['gravità '.(self::GRAVITA[$r['severity']] ?? $r['severity']),
                        $inRitardo ? $verbo.' in ritardo di '.$giorni.' '.$this->giorni($giorni) : $verbo.' entro il '.$this->data($fase['due_at'] ?? null)],
                    $inRitardo ? 'ritardo' : 'presto', $giorni,
                    [['label' => $r['status'] === 'open' ? 'Prendi in carico' : 'Apri', 'href' => '/segnalazioni']]);
            }

            $nc = $cose->nonConformities();
            $conteggi['non_conformita'] = $nc['open_count'];
            foreach ($nc['rows'] as $r) {
                $scaduta = $r['due_on'] !== null && $r['due_on'] < $today->toDateString();
                $voci[] = $this->voce('non_conformita', 'segnalazioni', $r['id'], $r['code'].' · '.$r['description'],
                    ['non conformità '.(self::GRAVITA_NC[$r['severity']] ?? $r['severity']),
                        $r['due_on'] ? ($scaduta ? 'da chiudere entro il '.$this->data($r['due_on']).', scaduta' : 'da chiudere entro il '.$this->data($r['due_on'])) : 'senza termine'],
                    $scaduta ? 'ritardo' : ($r['due_on'] ? 'presto' : 'programma'), $r['due_on'] ? abs($this->giorniA($r['due_on'], $today)) : 0,
                    [['label' => 'Apri', 'href' => '/lavori?vista=qualita']]);
            }

            $certificati = $cose->certificates($today);
            $conteggi['certificati_scaduti'] = $certificati['expired_count'];
            $conteggi['certificati_in_scadenza'] = $certificati['due_soon_count'];
            foreach ($certificati['rows'] as $r) {
                $scaduto = $r['state'] === 'expired';
                $voci[] = $this->voce('certificato', 'altro', $r['id'], $r['title'].' · '.$r['holder_name'],
                    [$scaduto ? 'scaduto il '.$this->data($r['expires_on']) : 'scade il '.$this->data($r['expires_on'])],
                    $scaduto ? 'ritardo' : 'presto', abs($this->giorniA($r['expires_on'], $today)),
                    [['label' => 'Apri', 'href' => '/patentini']]);
            }
        }

        if ($user->can('assets.view')) {
            $vta = $cose->vta($today, $user->tenant_id);
            $conteggi['vta_scaduti'] = $vta['overdue_count'];
            $conteggi['vta_in_scadenza'] = $vta['due_soon_count'];
            $conteggi['vta_senza_ordine'] = $vta['without_order_count'];
            $conteggi['vta_mai_valutati'] = $cose->alberiMaiValutati($user->tenant_id);
            foreach ($vta['rows'] as $r) {
                $scaduto = $r['next_check_due'] < $today->toDateString();
                $voci[] = $this->voce('vta', 'controlli', $r['id'], ($r['census_code'] ?: 'Albero senza cartellino').' · '.($r['species'] ?: $r['common_name'] ?: 'specie da indicare').' · classe '.$r['failure_class'],
                    [$scaduto ? 'ricontrollo scaduto il '.$this->data($r['next_check_due']) : 'ricontrollo entro il '.$this->data($r['next_check_due']),
                        $r['work_order_code'] ? 'in agenda con '.$r['work_order_code'] : 'senza ordine'],
                    $scaduto ? 'ritardo' : 'presto', abs($this->giorniA($r['next_check_due'], $today)),
                    [['label' => 'Valuta', 'href' => '/censimento/'.$r['id'].'?vta=1'], ['label' => 'Scheda', 'href' => '/censimento/'.$r['id']]]);
            }
        }

        if ($user->can('areas.view')) {
            $irrigazione = $cose->irrigation($today);
            $conteggi['irrigazione'] = count($irrigazione['rows']);
            foreach ($irrigazione['rows'] as $r) {
                $passata = $r['on_date'] !== null && $r['on_date'] < $today->toDateString();
                $voci[] = $this->voce('irrigazione', 'altro', $r['id'], $r['name'],
                    [$r['area'], ($r['action'] === 'winterize' ? 'da invernare' : 'da riaprire').($r['on_date'] ? ' entro il '.$this->data($r['on_date']) : '')],
                    $passata ? 'ritardo' : 'presto', $r['on_date'] ? abs($this->giorniA($r['on_date'], $today)) : 0,
                    [['label' => 'Apri', 'href' => '/irrigazione']]);
            }
        }

        usort($voci, function ($a, $b) {
            $ra = self::ORDINE_URGENZA[$a['urgenza']];
            $rb = self::ORDINE_URGENZA[$b['urgenza']];
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }

            // Fra i ritardi vince chi aspetta da più tempo; fra le scadenze la più vicina
            return $a['urgenza'] === 'ritardo' ? $b['giorni'] <=> $a['giorni'] : $a['giorni'] <=> $b['giorni'];
        });

        // I totali sono i numeri veri delle sezioni, non le righe elencate:
        // ogni sezione porta al massimo CoseDaFare::LIMIT righe, la frase in
        // testa alla pagina deve dire quante cose ci sono davvero
        $conteggi['famiglie'] = [
            'lavori' => $conteggi['lavori_ritardo'] + $conteggi['lavori_settimana'],
            'controlli' => $conteggi['ispezioni_scadute'] + $conteggi['ispezioni_in_scadenza'] + $conteggi['vta_scaduti'] + $conteggi['vta_in_scadenza'],
            'segnalazioni' => $conteggi['segnalazioni'] + $conteggi['non_conformita'],
            'altro' => $conteggi['certificati_scaduti'] + $conteggi['certificati_in_scadenza'] + $conteggi['irrigazione'],
        ];
        $conteggi['totale'] = array_sum($conteggi['famiglie']);
        $conteggi['elencate'] = count($voci);

        return response()->json(['data' => [
            'data' => $today->toDateString(),
            'giorno' => $this->giornoInLettere($today),
            'voci' => $voci,
            'conteggi' => $conteggi,
            'campo' => $user->can('assets.view') ? $this->campo($user->tenant_id, $today) : null,
            'documenti' => $user->can('assets.view') ? $this->documenti($user->tenant_id) : null,
            'portali' => $user->can('clients.view') ? $this->portali($user->tenant_id) : null,
        ]]);
    }

    /** Quello che e' arrivato dal campo oggi: comandi di sincronizzazione applicati. */
    private function campo(string $tenantId, Carbon $today): array
    {
        $dalle = $today->copy()->utc();
        $righe = collect(DB::select(<<<'SQL'
            SELECT so.command_type, so.entity_id, so.created_at, u.name AS utente,
                   a.census_code, ar.name AS area_name
            FROM sync_operations so
            LEFT JOIN users u ON u.id = so.user_id
            LEFT JOIN assets a ON a.id = so.entity_id
            LEFT JOIN areas ar ON ar.id = so.entity_id
            WHERE so.tenant_id = ? AND so.status = 'applied' AND so.created_at >= ?
            ORDER BY so.created_at DESC
            SQL, [$tenantId, $dalle->toIso8601String()]))
            ->filter(fn ($r) => isset(self::COMANDI_CAMPO[$r->command_type]));

        $conta = fn (string $tipo) => $righe->where('command_type', $tipo)->count();

        return [
            'rilievi' => $conta('asset.create'),
            'misure' => $conta('asset.update_measures'),
            'aree' => $conta('area.create'),
            'operatori' => $righe->pluck('utente')->filter()->unique()->count(),
            'righe' => $righe->take(8)->map(fn ($r) => [
                'ora' => Carbon::parse($r->created_at)->setTimezone(CoseDaFare::TIMEZONE)->format('H:i'),
                'tipo' => self::COMANDI_CAMPO[$r->command_type],
                'cosa' => $r->census_code ?: $r->area_name,
                'utente' => $r->utente,
            ])->values(),
        ];
    }

    /** Perizie con il rapporto emesso ma non ancora validato: da chiudere. */
    private function documenti(string $tenantId): array
    {
        $righe = collect(DB::select(<<<'SQL'
            SELECT ta.id, ta.report_number, ta.assessed_on::text AS assessed_on, ta.report_issued_at, a.census_code, a.id AS asset_id
            FROM tree_assessments ta
            JOIN assets a ON a.id = ta.tree_id
            WHERE ta.tenant_id = ? AND ta.deleted_at IS NULL
              AND ta.report_issued_at IS NOT NULL AND ta.validated_at IS NULL
            ORDER BY ta.report_issued_at DESC
            SQL, [$tenantId]));

        return [
            'perizie_da_validare' => $righe->count(),
            'righe' => $righe->take(5)->map(fn ($r) => [
                'id' => $r->id,
                'numero' => $r->report_number,
                'cartellino' => $r->census_code,
                'asset_id' => $r->asset_id,
                'valutata_il' => $this->data($r->assessed_on),
                'emessa_il' => $this->data(substr((string) $r->report_issued_at, 0, 10)),
            ])->values(),
        ];
    }

    /**
     * I portali pubblici accesi, con quanto pubblicano e che cosa manca. Il
     * conteggio di quello che si vede usa le regole del portale (PortalQuery),
     * non una copia: se cambiano li', cambia anche qui.
     */
    private function portali(string $tenantId): array
    {
        $clienti = Client::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->whereNull('deleted_at')
            ->where('public_enabled', true)->where('is_active', true)
            ->orderBy('name')->get();

        return $clienti->map(function (Client $c) {
            $profilo = $c->public_profile ?? [];
            $recapiti = ['contact_email', 'contact_phone', 'address', 'contact_pec'];
            $mancanti = count(array_filter($recapiti, fn ($k) => trim((string) ($profilo[$k] ?? '')) === ''));

            // Nascosti a mano: quelli che sarebbero pubblicabili ma il
            // backoffice ha tolto dalla vetrina
            $nascosti = Asset::withoutGlobalScopes()
                ->whereNull('deleted_at')->where('tenant_id', $c->tenant_id)
                ->where('public_hidden', true)->where('status', 'active')
                ->whereIn('area_id', PortalQuery::areaIds($c))
                ->count();

            return [
                'id' => $c->id,
                'nome' => $c->publicName(),
                'slug' => $c->public_slug,
                'pubblicati' => PortalQuery::assets($c)->count(),
                'nascosti' => $nascosti,
                'recapiti_mancanti' => $mancanti,
                'copertina' => ! empty($profilo['cover_path']),
            ];
        })->values()->all();
    }

    private function voce(string $tipo, string $famiglia, string $id, string $titolo, array $dettagli, string $urgenza, int $giorni, array $azioni): array
    {
        return [
            'chiave' => $tipo.':'.$id,
            'tipo' => $tipo,
            'famiglia' => $famiglia,
            'titolo' => $titolo,
            'dettaglio' => implode(' · ', array_values(array_filter($dettagli, fn ($d) => $d !== null && $d !== ''))),
            'urgenza' => $urgenza,
            'giorni' => $giorni,
            'azioni' => $azioni,
        ];
    }

    /** Giorni passati da una data (positivi se e' nel passato). */
    private function giorniDa(?string $data, Carbon $today): int
    {
        if (! $data) {
            return 0;
        }

        return (int) Carbon::parse(substr($data, 0, 10), CoseDaFare::TIMEZONE)->startOfDay()->diffInDays($today, false);
    }

    /** Giorni che mancano a una data (negativi se e' gia' passata). */
    private function giorniA(?string $data, Carbon $today): int
    {
        return -$this->giorniDa($data, $today);
    }

    private function giorni(int $n): string
    {
        return $n === 1 ? 'giorno' : 'giorni';
    }

    private function data(?string $iso): string
    {
        if (! $iso) {
            return '—';
        }

        return Carbon::parse($iso)->setTimezone(CoseDaFare::TIMEZONE)->format('d/m/Y');
    }

    private function giornoInLettere(Carbon $giorno): string
    {
        $settimana = ['lunedì', 'martedì', 'mercoledì', 'giovedì', 'venerdì', 'sabato', 'domenica'];
        $mesi = ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];

        return ucfirst($settimana[$giorno->dayOfWeekIso - 1]).' '.$giorno->day.' '.$mesi[$giorno->month - 1].' '.$giorno->year;
    }
}
