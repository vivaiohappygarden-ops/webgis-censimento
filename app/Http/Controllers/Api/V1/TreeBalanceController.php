<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Organization;
use App\Services\Pdf\LuogoFirma;
use App\Services\Pdf\PdfRenderer;
use App\Services\Trees\TreeBalance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;

/**
 * Bilancio arboreo fra due date (legge 10/2013): consistenza iniziale e
 * finale, nuovi impianti, abbattimenti, variazione e dettaglio per specie.
 *
 * Il conto sta in TreeBalance; qui si validano le richieste e si sceglie se
 * rispondere con i numeri o con il documento da consegnare all'ente.
 */
class TreeBalanceController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('can:assets.view')];
    }

    public function index(Request $request): JsonResponse
    {
        [$from, $to, $client] = $this->parametri($request);

        return response()->json([
            'data' => TreeBalance::per($request->user()->tenant_id, $from, $to, $client?->id),
        ]);
    }

    /**
     * Bilancio in PDF, da allegare agli atti dell'ente.
     *
     * Il documento riguarda un committente alla volta: il bilancio di fine
     * mandato è del singolo Comune, e sommare piu' enti in un foglio solo
     * darebbe un numero che nessuno puo' usare.
     */
    public function pdf(Request $request, PdfRenderer $renderer)
    {
        [$from, $to, $client] = $this->parametri($request);

        $dati = TreeBalance::per($request->user()->tenant_id, $from, $to, $client?->id);

        // Un solo orologio per tutto il documento: leggendo l'ora due volte,
        // a cavallo della mezzanotte, "stampato il" e la data della firma
        // uscirebbero con due giorni diversi
        $adesso = Carbon::now('Europe/Rome');

        $pdf = $renderer->render('pdf.tree-balance', [
            'bilancio' => $dati,
            'client' => $client,
            'organization' => Organization::find($request->user()->tenant_id),
            'stampatoIl' => $adesso,
            'luogoData' => LuogoFirma::riga($request->user()->tenant_id, $adesso),
        ]);

        $nome = 'bilancio-arboreo-'.$dati['from'].'_'.$dati['to'].'.pdf';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$nome.'"',
        ]);
    }

    /** @return array{0: string, 1: string, 2: ?Client} */
    private function parametri(Request $request): array
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'client_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        // Il committente si cerca dentro l'ambito del tenant: chiedere il
        // bilancio di un ente di un'altra impresa deve dare "non trovato"
        $client = empty($data['client_id']) ? null
            : Client::query()->findOrFail($data['client_id']);

        return [
            Carbon::parse($data['from'])->toDateString(),
            Carbon::parse($data['to'])->toDateString(),
            $client,
        ];
    }
}
