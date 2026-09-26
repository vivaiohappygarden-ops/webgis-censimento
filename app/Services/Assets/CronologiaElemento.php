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
 * valutazioni di stabilita', lavori, segnalazioni, fotografie, abbattimento,
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

    private const STATO_SEGNALAZIONE = [
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
                $eventi[] = $this->evento(
                    $data?->toDateString(),
                    'lavoro',
                    $l->title,
                    [$l->code, mb_strtolower(WorkOrder::STATUS_LABELS[$l->status] ?? $l->status), $l->team?->name],
                    ['href' => '/lavori?ordine='.$l->code, 'id' => $l->id, 'stato' => $l->status],
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
                ['href' => '/segnalazioni', 'id' => $s->id],
            );
        }

        // Fotografie, raggruppate per giorno dello scatto
        $foto = Photo::query()->where('asset_id', $asset->id)
            ->orderByDesc('taken_at')->orderByDesc('created_at')->get(['id', 'taken_at', 'created_at', 'taken_by']);
        foreach ($foto->groupBy(fn (Photo $f) => ($f->taken_at ?? $f->created_at)->setTimezone(self::FUSO)->toDateString()) as $giorno => $gruppo) {
            $eventi[] = $this->evento(
                $giorno,
                'foto',
                count($gruppo) === 1 ? '1 fotografia' : count($gruppo).' fotografie',
                [$nome($gruppo->first()->taken_by)],
                ['foto' => $gruppo->take(4)->map(fn (Photo $f) => ['id' => $f->id, 'url' => $f->url])->values()->all()],
            );
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
        $ordine = ['rilievo' => 0, 'modifica' => 1, 'valutazione' => 2, 'segnalazione' => 3, 'lavoro' => 4, 'foto' => 5, 'abbattimento' => 6];
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
