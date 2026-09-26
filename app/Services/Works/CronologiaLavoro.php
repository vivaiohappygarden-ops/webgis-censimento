<?php

namespace App\Services\Works;

use App\Models\Estimate;
use App\Models\Photo;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Oggi\CoseDaFare;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * La cronologia di un ordine di lavoro: creazione, cambi di stato (dal
 * registro delle operazioni), consuntivi dal campo, fotografie, controlli
 * qualita', non conformita', richieste delle imprese e la fine prevista
 * superata, dal piu' recente. Insieme escono i documenti collegati
 * (preventivo d'origine, SAL che lo contengono, rendiconto) e, per ogni
 * elemento, quante volte e' stato lavorato e fotografato. La legge la pagina
 * dell'ordine e l'anteprima dell'elenco (veste nuova, blocco 4).
 */
class CronologiaLavoro
{
    public const ORIGINI = [
        'manual' => 'creato a mano',
        'estimate' => 'da un preventivo accettato',
        'inspection' => "da un'ispezione",
        'issue' => 'da una segnalazione',
        'maintenance_plan' => 'da un piano di manutenzione',
        'non_conformity' => 'da una non conformità',
        'vta_recheck' => 'dallo scadenzario VTA',
        'work_check' => 'da un controllo qualità',
    ];

    private const FUSO = 'Europe/Rome';

    private const GRAVITA_NC = ['minor' => 'lieve', 'major' => 'grave', 'critical' => 'critica'];

    private const STATO_NC = ['open' => 'aperta', 'action' => 'in azione', 'verified' => 'verificata', 'closed' => 'chiusa'];

    private const STATO_RICHIESTA = ['aperta' => 'in attesa', 'accettata' => 'accettata', 'rifiutata' => 'non accolta'];

    /** A parita' di istante, l'ordine logico dei fatti (la creazione viene prima di tutto). */
    private const PRIORITA = [
        'creato' => 0, 'elementi' => 1, 'stato' => 2, 'modifica' => 3, 'giorno' => 3, 'consuntivo' => 4, 'foto' => 5,
        'controllo' => 6, 'non_conformita' => 7, 'riprogrammazione' => 8, 'ritardo' => 9,
    ];

    /** @return array{eventi: list<array<string, mixed>>, documenti: list<array<string, mixed>>, per_elemento: array<string, array<string, mixed>>} */
    public function per(WorkOrder $ordine): array
    {
        $eventi = [];
        $idUtenti = [];
        $nome = function (?string $id) use (&$idUtenti): ?string {
            if (! $id) {
                return null;
            }
            $idUtenti[$id] = true;

            return "{utente:{$id}}";
        };
        $ev = function (?Carbon $quando, string $tipo, string $titolo, array $dettagli, array $extra = []) {
            $q = $quando?->copy()->setTimezone(self::FUSO);

            return [
                'quando' => $q?->toIso8601String(),
                'ordine' => ($q?->format('Y-m-d\TH:i:s.u') ?? '').'|'.(self::PRIORITA[$tipo] ?? 5),
                'data' => $q?->toDateString(),
                'tipo' => $tipo,
                'titolo' => $titolo,
                'dettaglio' => implode(' · ', array_values(array_filter($dettagli, fn ($d) => $d !== null && $d !== ''))),
                ...$extra,
            ];
        };

        $eventi[] = $ev($ordine->created_at, 'creato', 'Ordine creato', [$nome($ordine->created_by), self::ORIGINI[$ordine->origin] ?? null]);

        // Il registro delle operazioni: cambi di stato, elementi collegati,
        // giornate annullate, modifiche
        $registro = DB::table('audit_logs')
            ->where('tenant_id', $ordine->tenant_id)
            ->where('subject_type', $ordine->getMorphClass())->where('subject_id', $ordine->id)
            ->whereIn('action', ['work_order.transition', 'work_order.assets_attached', 'work_order.day_toggled', 'work_order.updated'])
            ->orderBy('created_at')
            ->get(['action', 'user_id', 'payload', 'created_at']);
        foreach ($registro as $r) {
            $p = json_decode((string) $r->payload, true) ?: [];
            $quando = Carbon::parse($r->created_at);
            switch ($r->action) {
                case 'work_order.transition':
                    $a = $p['to'] ?? null;
                    $eventi[] = $ev($quando, 'stato', 'Stato: '.mb_strtolower(WorkOrder::STATUS_LABELS[$a] ?? (string) $a), [$nome($r->user_id)], ['stato' => $a]);
                    break;
                case 'work_order.assets_attached':
                    $n = $p['quanti'] ?? $p['count'] ?? (isset($p['ids']) ? count($p['ids']) : null);
                    $eventi[] = $ev($quando, 'elementi', 'Elementi collegati', [$nome($r->user_id), $n !== null ? $n.' '.($n === 1 ? 'elemento' : 'elementi') : null]);
                    break;
                case 'work_order.day_toggled':
                    $giorno = isset($p['day']) ? Carbon::parse($p['day'])->format('d/m/Y') : null;
                    $eventi[] = $ev($quando, 'giorno', ($p['cancelled'] ?? false) ? 'Giornata annullata' : 'Giornata ripristinata', [$nome($r->user_id), $giorno]);
                    break;
                default:
                    $eventi[] = $ev($quando, 'modifica', 'Ordine modificato', [$nome($r->user_id)]);
            }
        }

        // Consuntivi dal campo
        $logs = DB::table('work_logs as wl')
            ->leftJoin('assets as a', 'a.id', '=', 'wl.asset_id')
            ->where('wl.work_order_id', $ordine->id)->whereNull('wl.deleted_at')
            ->orderBy('wl.started_at')
            ->get(['wl.id', 'wl.asset_id', 'wl.operator_id', 'wl.started_at', 'wl.man_hours', 'wl.quantity', 'wl.unit', 'wl.notes', 'a.census_code']);
        $perElemento = [];
        foreach ($logs as $l) {
            $eventi[] = $ev(Carbon::parse($l->started_at), 'consuntivo', 'Consuntivo dal campo', [
                $nome($l->operator_id), $l->census_code,
                $l->man_hours !== null ? number_format((float) $l->man_hours, 1, ',', '.').' ore' : null,
                $l->quantity !== null ? number_format((float) $l->quantity, 2, ',', '.').' '.($l->unit ?? '') : null,
                $l->notes ? mb_strimwidth(trim($l->notes), 0, 80, '…') : null,
            ], ['id' => $l->id, 'asset_id' => $l->asset_id]);
            if ($l->asset_id) {
                $perElemento[$l->asset_id]['fatti'] = ($perElemento[$l->asset_id]['fatti'] ?? 0) + 1;
                $ultimo = Carbon::parse($l->started_at)->setTimezone(self::FUSO)->toDateString();
                $perElemento[$l->asset_id]['ultimo'] = max($perElemento[$l->asset_id]['ultimo'] ?? '', $ultimo);
            }
        }

        // Fotografie scattate per questo ordine, per giorno
        $foto = Photo::query()->where('subject_type', $ordine->getMorphClass())->where('subject_id', $ordine->id)
            ->orderByDesc('taken_at')->orderByDesc('created_at')->get(['id', 'asset_id', 'taken_at', 'created_at', 'taken_by']);
        foreach ($foto as $f) {
            if ($f->asset_id) {
                $perElemento[$f->asset_id]['foto'] = ($perElemento[$f->asset_id]['foto'] ?? 0) + 1;
            }
        }
        foreach ($foto->groupBy(fn (Photo $f) => ($f->taken_at ?? $f->created_at)->setTimezone(self::FUSO)->toDateString()) as $gruppo) {
            $prima = $gruppo->first();
            $eventi[] = $ev($prima->taken_at ?? $prima->created_at, 'foto', count($gruppo) === 1 ? '1 fotografia' : count($gruppo).' fotografie', [$nome($prima->taken_by)],
                ['foto' => $gruppo->take(6)->map(fn (Photo $f) => ['id' => $f->id, 'url' => $f->url, 'asset_id' => $f->asset_id])->values()->all()]);
        }

        // Controlli qualita' e non conformita'
        foreach (DB::table('work_checks')->where('work_order_id', $ordine->id)->orderBy('checked_at')->get() as $c) {
            $eventi[] = $ev(Carbon::parse($c->checked_at), 'controllo', 'Controllo qualità '.($c->outcome === 'passed' ? 'positivo' : 'negativo'),
                [$nome($c->checked_by), $c->notes ? mb_strimwidth(trim($c->notes), 0, 80, '…') : null], ['esito' => $c->outcome]);
        }
        foreach (DB::table('non_conformities')->where('work_order_id', $ordine->id)->whereNull('deleted_at')->orderBy('created_at')->get() as $nc) {
            $eventi[] = $ev(Carbon::parse($nc->created_at), 'non_conformita', 'Non conformità '.$nc->code,
                ['gravità '.(self::GRAVITA_NC[$nc->severity] ?? $nc->severity), self::STATO_NC[$nc->status] ?? $nc->status, mb_strimwidth((string) $nc->description, 0, 80, '…')],
                ['href' => '/lavori?vista=qualita']);
        }

        // Richieste di spostamento dalle imprese
        foreach (DB::table('reschedule_requests')->where('work_order_id', $ordine->id)->orderBy('created_at')->get() as $rr) {
            $eventi[] = $ev(Carbon::parse($rr->created_at), 'riprogrammazione', "Richiesta di spostamento dall'impresa", [
                $rr->reason, $rr->proposed_start ? 'data proposta '.Carbon::parse($rr->proposed_start)->format('d/m/Y') : null,
                self::STATO_RICHIESTA[$rr->status] ?? $rr->status,
            ]);
        }

        // La fine prevista superata: un fatto di oggi, non del passato
        $oggi = Carbon::now(CoseDaFare::TIMEZONE);
        if (in_array($ordine->status, WorkOrder::FIELD_STATUSES, true) && $ordine->planned_end && $ordine->planned_end->lt($oggi->copy()->startOfDay())) {
            $eventi[] = $ev($oggi, 'ritardo', 'Fine prevista superata', ['doveva chiudersi il '.$ordine->planned_end->format('d/m/Y')]);
        }

        $nomi = $idUtenti === [] ? collect() : User::query()->whereIn('id', array_keys($idUtenti))->pluck('name', 'id');
        foreach ($eventi as &$e) {
            $e['dettaglio'] = preg_replace_callback('/\{utente:([0-9a-f-]+)\}/', fn ($m) => $nomi[$m[1]] ?? 'utente non più presente', $e['dettaglio']);
        }
        unset($e);
        usort($eventi, fn ($a, $b) => strcmp($b['ordine'], $a['ordine']));
        foreach ($eventi as &$e) {
            unset($e['ordine']);
        }
        unset($e);

        return [
            'eventi' => $eventi,
            'documenti' => $this->documenti($ordine),
            'per_elemento' => $perElemento,
        ];
    }

    /** Preventivo d'origine, SAL che contengono l'ordine, rendiconto a lavoro chiuso. */
    private function documenti(WorkOrder $ordine): array
    {
        $documenti = [];
        if ($ordine->origin === 'estimate' && $ordine->origin_id) {
            $preventivo = Estimate::query()->find($ordine->origin_id);
            if ($preventivo) {
                $documenti[] = [
                    'tipo' => 'preventivo', 'codice' => $preventivo->code, 'stato' => $preventivo->status,
                    'titolo' => 'Preventivo '.$preventivo->code, 'data' => $preventivo->created_at?->toDateString(), 'href' => '/lavori?vista=preventivi',
                ];
            }
        }
        $sals = DB::table('sal_items as si')->join('sals as s', 's.id', '=', 'si.sal_id')
            ->where('si.work_order_id', $ordine->id)
            ->groupBy('s.id', 's.code', 's.status', 's.period_to')
            ->selectRaw('s.id, s.code, s.status, s.period_to::text AS period_to, SUM(si.imponibile) AS imponibile')
            ->orderByDesc('s.period_to')->get();
        foreach ($sals as $s) {
            $documenti[] = [
                'tipo' => 'sal', 'codice' => $s->code, 'stato' => $s->status, 'titolo' => 'SAL '.$s->code,
                'data' => $s->period_to, 'importo' => $s->imponibile !== null ? (float) $s->imponibile : null, 'href' => '/lavori?vista=sal',
            ];
        }
        if ($ordine->status === 'completed') {
            $documenti[] = ['tipo' => 'rendiconto', 'titolo' => 'Rendiconto dei lavori', 'data' => $ordine->completed_at?->toDateString(), 'href' => '/lavori?vista=rendiconto'];
        }

        return $documenti;
    }
}
