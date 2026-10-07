<?php

namespace App\Services\Assets;

use App\Models\Asset;
use App\Models\Photo;
use App\Models\TreeAssessment;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * La cronologia di un elemento censito: rilievo, modifiche della scheda,
 * valutazioni di stabilita', lavori, segnalazioni, fotografie per giorno di
 * caricamento (anche quelle eliminate, con la loro eliminazione), abbattimento,
 * in un'unica linea del tempo dal piu' recente. La leggono l'anteprima
 * dell'elenco e la scheda della veste nuova: la definizione degli eventi e'
 * una sola. Nessun dato nuovo: sono le righe delle tabelle esistenti, lette
 * nell'ordine in cui sono successe.
 */
class CronologiaElemento
{
    public const LIMITE = 60;

    private const FUSO = 'Europe/Rome';

    private const GRAVITA = ['critical' => 'critica', 'high' => 'alta', 'medium' => 'media', 'low' => 'bassa'];

    public const STATO_SEGNALAZIONE = [
        'open' => 'aperta', 'in_charge' => 'presa in carico', 'resolved' => 'risolta', 'closed' => 'chiusa', 'rejected' => 'respinta',
    ];

    private const FONTE = ['sync' => 'dal telefono', 'import' => 'da importazione'];

    /** @return array{eventi: list<array<string, mixed>>, totale: int} */
    public function per(Asset $asset): array
    {
        $eventi = [];
        $idUtenti = [];
        $nome = function (?string $id) use (&$idUtenti): string {
            if ($id) {
                $idUtenti[$id] = true;
            }

            return $id ? "{utente:{$id}}" : '';
        };

        // Rilievo o inserimento della scheda
        $rilievo = $asset->surveyed_at ? Carbon::parse($asset->surveyed_at) : $asset->created_at;
        $eventi[] = $this->evento(
            $rilievo?->toDateString(),
            'rilievo',
            $asset->surveyed_at ? 'Rilievo in campo' : 'Inserimento della scheda',
            [$nome($asset->surveyed_by ?? $asset->created_by),
                $asset->gps_accuracy_m !== null ? 'GPS '.number_format((float) $asset->gps_accuracy_m, 1, ',', '.').' m' : null],
        );

        // Modifiche della scheda (la fotografia scattata prima di ogni modifica)
        $versioni = DB::table('asset_versions')
            ->where('asset_id', $asset->id)->where('tenant_id', $asset->tenant_id)
            ->orderByDesc('changed_at')->limit(30)
            ->get(['version', 'changed_by', 'changed_at', 'change_source']);
        foreach ($versioni as $v) {
            $eventi[] = $this->evento(
                Carbon::parse($v->changed_at)->setTimezone(self::FUSO)->toDateString(),
                'modifica',
                'Modifica della scheda',
                [$nome($v->changed_by), self::FONTE[$v->change_source] ?? null, 'revisione '.($v->version + 1)],
            );
        }

        // Valutazioni di stabilita'
        if ($asset->tree) {
            $valutazioni = TreeAssessment::query()->with('assessor:id,name')
                ->where('tree_id', $asset->id)->orderByDesc('assessed_on')->orderByDesc('created_at')->get();
            foreach ($valutazioni as $v) {
                $eventi[] = $this->evento(
                    $v->assessed_on?->toDateString(),
                    'valutazione',
                    'Valutazione VTA'.($v->failure_class ? ' · classe '.$v->failure_class : ''),
                    [$v->assessor?->name ?: $v->assessor_external,
                        $v->next_check_due ? 'ricontrollo entro il '.$v->next_check_due->format('d/m/Y') : null,
                        $v->report_number ? 'perizia n. '.$v->report_number : null,
                        $v->validated_at ? 'validata' : null],
                    ['href' => '/censimento/'.$asset->id.'?vta=1', 'id' => $v->id],
                );
            }

            // Avvisi al committente (area da chiudere) nati dalle valutazioni
            $avvisi = \App\Models\ClientAlert::query()->with('area:id,name')->where('asset_id', $asset->id)->orderByDesc('sent_at')->get();
            foreach ($avvisi as $a) {
                $eventi[] = $this->evento(
                    $a->sent_at?->setTimezone(self::FUSO)->toDateString(),
                    'avviso',
                    'Avviso al committente'.($a->area ? ' · '.$a->area->name : ''),
                    [$nome($a->sent_by),
                        $a->inviati().' '.($a->inviati() === 1 ? 'indirizzo' : 'indirizzi'),
                        $a->acknowledged_at ? 'presa d\'atto il '.$a->acknowledged_at->setTimezone(self::FUSO)->format('d/m/Y') : 'in attesa di presa d\'atto',
                        $a->resolved_at ? 'rientrato il '.$a->resolved_at->setTimezone(self::FUSO)->format('d/m/Y') : null],
                    ['href' => '/censimento/'.$asset->id.'?vta=1', 'id' => $a->id],
                );
            }

            if ($asset->tree->removed_on) {
                $eventi[] = $this->evento(
                    Carbon::parse($asset->tree->removed_on)->toDateString(),
                    'abbattimento',
                    'Abbattimento o rimozione',
                    [$asset->tree->removal_reason],
                );
            }
        }

        // Lavori che hanno riguardato l'elemento
        $idLavori = DB::table('work_order_assets')->where('asset_id', $asset->id)->pluck('work_order_id')->unique();
        if ($idLavori->isNotEmpty()) {
            $lavori = WorkOrder::query()->with('team:id,name')->whereIn('id', $idLavori)->get();
            foreach ($lavori as $l) {
                $data = $l->completed_at?->setTimezone(self::FUSO) ?? $l->planned_end ?? $l->planned_start ?? $l->created_at;
                $periodo = $l->completed_at
                    ? $l->completed_at->setTimezone(self::FUSO)->format('d/m/Y')
                    : implode(' – ', array_unique(array_filter([$l->planned_start?->format('d/m/Y'), $l->planned_end?->format('d/m/Y')])));
                $eventi[] = $this->evento(
                    $data?->toDateString(),
                    'lavoro',
                    $l->title,
                    [$l->code, mb_strtolower(WorkOrder::STATUS_LABELS[$l->status] ?? $l->status), $l->team?->name],
                    // I campi espliciti servono alla tabella "Lavori e segnalazioni"
                    // della scheda, che non deve rileggere il dettaglio
                    ['href' => '/lavori/'.$l->id, 'id' => $l->id, 'codice' => $l->code, 'stato' => $l->status,
                        'stato_etichetta' => WorkOrder::STATUS_LABELS[$l->status] ?? $l->status, 'squadra' => $l->team?->name,
                        'periodo' => $periodo ?: null, 'origine' => $l->origin],
                );
            }
        }

        // Segnalazioni sull'elemento
        $segnalazioni = DB::table('issues')
            ->where('asset_id', $asset->id)->where('tenant_id', $asset->tenant_id)->whereNull('deleted_at')
            ->orderByDesc('created_at')->limit(20)
            ->get(['id', 'code', 'severity', 'status', 'description', 'created_at']);
        foreach ($segnalazioni as $s) {
            $eventi[] = $this->evento(
                Carbon::parse($s->created_at)->setTimezone(self::FUSO)->toDateString(),
                'segnalazione',
                'Segnalazione '.$s->code,
                ['gravità '.(self::GRAVITA[$s->severity] ?? $s->severity), self::STATO_SEGNALAZIONE[$s->status] ?? $s->status,
                    mb_strimwidth((string) $s->description, 0, 80, '…')],
                ['href' => '/segnalazioni', 'id' => $s->id, 'codice' => $s->code, 'stato' => $s->status,
                    'stato_etichetta' => ucfirst(self::STATO_SEGNALAZIONE[$s->status] ?? $s->status),
                    'gravita' => self::GRAVITA[$s->severity] ?? $s->severity],
            );
        }

        // Fotografie, raggruppate per giorno di caricamento: la cronologia
        // racconta quando la foto e' entrata nella scheda (decisione committente
        // 27/09/2026: dall'iPhone arrivano foto scattate settimane prima, e la
        // riga finiva nel giorno dello scatto). La data dello scatto, quando e'
        // un altro giorno, resta nel dettaglio: e' un dato della foto, non del
        // fatto. Le foto eliminate restano nel conteggio del loro giorno (senza
        // anteprima: il file non c'e' piu') e l'eliminazione e' un fatto a se',
        // nel giorno in cui e' avvenuta e con chi l'ha fatta: la cronologia non
        // riscrive il passato. Una foto eliminata sparisce dalla scheda e
        // dalle perizie non ancora validate, ma da qui si apre ancora: il
        // file resta e l'eliminazione e' morbida (decisione committente
        // 27/09/2026)
        $foto = Photo::withTrashed()->where('asset_id', $asset->id)
            ->orderByDesc('created_at')->orderByDesc('taken_at')
            ->get(['id', 'taken_at', 'created_at', 'taken_by', 'deleted_at']);
        $caricamento = fn (Photo $f) => $f->created_at->setTimezone(self::FUSO);
        $anteprima = fn (Photo $f) => [
            'id' => $f->id,
            'url' => $f->url,
            'created_at' => $f->created_at?->toIso8601String(),
            'taken_at' => $f->taken_at?->toIso8601String(),
            'eliminata' => $f->deleted_at !== null,
            'eliminata_il' => $f->deleted_at?->toIso8601String(),
        ];
        $scatti = function ($gruppo) use ($caricamento): ?string {
            // Solo gli scatti di un giorno diverso dal caricamento
            $giorni = $gruppo
                ->filter(fn (Photo $f) => $f->taken_at && $f->taken_at->setTimezone(self::FUSO)->toDateString() !== $caricamento($f)->toDateString())
                ->map(fn (Photo $f) => $f->taken_at->setTimezone(self::FUSO)->startOfDay())
                ->unique(fn ($d) => $d->toDateString())->sort()->values();
            if ($giorni->isEmpty()) {
                return null;
            }
            if ($giorni->count() === 1) {
                return ($gruppo->count() === 1 ? 'scattata il ' : 'scattate il ').$giorni->first()->format('d/m/Y');
            }

            return 'scattate fra il '.$giorni->first()->format('d/m/Y').' e il '.$giorni->last()->format('d/m/Y');
        };
        foreach ($foto->groupBy(fn (Photo $f) => $caricamento($f)->toDateString()) as $giorno => $gruppo) {
            $vive = $gruppo->whereNull('deleted_at');
            $eliminate = count($gruppo) - count($vive);
            $eventi[] = $this->evento(
                $giorno,
                'foto',
                count($gruppo) === 1 ? '1 fotografia' : count($gruppo).' fotografie',
                [$nome($gruppo->first()->taken_by), $scatti($gruppo), match (true) {
                    $eliminate === 0 => null,
                    count($gruppo) === 1 => 'eliminata in seguito',
                    $eliminate === count($gruppo) => 'tutte eliminate in seguito',
                    default => $eliminate.' eliminate in seguito',
                }],
                // Prima quelle ancora nella scheda, poi le eliminate
                ['foto' => $vive->concat($gruppo->whereNotNull('deleted_at'))->take(4)->map($anteprima)->values()->all()],
            );
        }

        $eliminate = $foto->whereNotNull('deleted_at');
        if ($eliminate->isNotEmpty()) {
            // Chi ha eliminato lo dice il registro: la riga della foto non lo porta
            $autori = DB::table('audit_logs')
                ->where('tenant_id', $asset->tenant_id)->where('action', 'photo.deleted')
                ->whereIn('subject_id', $eliminate->pluck('id'))
                ->orderBy('created_at')
                ->pluck('user_id', 'subject_id');
            $perGiornoEAutore = $eliminate->groupBy(fn (Photo $f) => $f->deleted_at->setTimezone(self::FUSO)->toDateString().'|'.($autori[$f->id] ?? ''));
            foreach ($perGiornoEAutore as $chiave => $gruppo) {
                [$giorno, $autore] = explode('|', $chiave, 2);
                $caricate = $gruppo->map(fn (Photo $f) => $caricamento($f)->format('d/m/Y'))->unique()->values();
                $eventi[] = $this->evento(
                    $giorno,
                    'foto_eliminata',
                    count($gruppo) === 1 ? 'Fotografia eliminata' : count($gruppo).' fotografie eliminate',
                    [$nome($autore !== '' ? $autore : null),
                        (count($gruppo) === 1 ? 'caricata il ' : 'caricate il ').$caricate->implode(', ')],
                    ['foto' => $gruppo->take(4)->map($anteprima)->values()->all()],
                );
            }
        }

        // I nomi degli utenti, letti in una volta sola
        $nomi = $idUtenti === [] ? collect() : User::query()->whereIn('id', array_keys($idUtenti))->pluck('name', 'id');
        foreach ($eventi as &$e) {
            $e['dettaglio'] = preg_replace_callback('/\{utente:([0-9a-f-]+)\}/', fn ($m) => $nomi[$m[1]] ?? 'utente non più presente', $e['dettaglio']);
            $e['dettaglio'] = trim(preg_replace('/(^ · | · $|( · ){2,})/', ' · ', $e['dettaglio']), ' ·');
        }
        unset($e);

        // Dal piu' recente; a parita' di giorno prima quello che e' successo
        // dopo nella logica del lavoro (foto e lavori dopo il rilievo)
        $ordine = ['rilievo' => 0, 'modifica' => 1, 'valutazione' => 2, 'avviso' => 3, 'segnalazione' => 4, 'lavoro' => 5, 'foto' => 6, 'foto_eliminata' => 7, 'abbattimento' => 8];
        usort($eventi, function ($a, $b) use ($ordine) {
            return [$b['data'] ?? '', $ordine[$b['tipo']]] <=> [$a['data'] ?? '', $ordine[$a['tipo']]];
        });

        return [
            'eventi' => array_slice($eventi, 0, self::LIMITE),
            'totale' => count($eventi),
        ];
    }

    private function evento(?string $data, string $tipo, string $titolo, array $dettagli, array $extra = []): array
    {
        return [
            'data' => $data,
            'tipo' => $tipo,
            'titolo' => $titolo,
            'dettaglio' => implode(' · ', array_values(array_filter($dettagli, fn ($d) => $d !== null && $d !== ''))),
            ...$extra,
        ];
    }
}
