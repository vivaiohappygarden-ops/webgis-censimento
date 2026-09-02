<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Organization;
use App\Services\Pdf\LuogoFirma;
use App\Services\Pdf\PdfRenderer;
use App\Services\Reports\RelazioneAnnuale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * La relazione annuale del verde: il PDF che a fine anno racconta all'ente
 * il lavoro fatto su un committente. I numeri stanno in RelazioneAnnuale;
 * qui si valida la richiesta e si compone il documento.
 *
 * Stesso permesso della Rendicontazione (works.view): è lo stesso riepilogo
 * per cliente e periodo, portato in forma di documento.
 */
class RelazioneAnnualeController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('can:works.view')];
    }

    public function pdf(Request $request, PdfRenderer $renderer)
    {
        [$client, $anno] = $this->parametri($request);

        $dati = RelazioneAnnuale::per($request->user()->tenant_id, $client, $anno);

        // Un solo orologio per tutto il documento: la relazione non ha una
        // data propria, quindi "stampato il" e la riga della firma leggono
        // lo stesso istante, o a cavallo della mezzanotte divergerebbero
        $adesso = Carbon::now('Europe/Rome');

        $pdf = $renderer->render('pdf.relazione-annuale', [
            'relazione' => $dati,
            'client' => $client,
            'organization' => Organization::find($request->user()->tenant_id),
            'stampatoIl' => $adesso,
            'luogoData' => LuogoFirma::riga($request->user()->tenant_id, $adesso),
        ]);

        $slug = Str::slug($client->public_slug ?: ($client->code ?: $client->name)) ?: 'committente';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="relazione-verde-'.$slug.'-'.$anno.'.pdf"',
        ]);
    }

    /**
     * Il primo anno con dati del committente, per popolare la tendina degli
     * anni nell'interfaccia: dal primo censimento o dal primo ordine di
     * lavoro, quello che viene prima.
     */
    public function primoAnno(Request $request): JsonResponse
    {
        $data = $request->validate(['client_id' => ['required', 'uuid']]);

        // Dentro l'ambito del tenant: il committente altrui dà "non trovato"
        $client = Client::query()->findOrFail($data['client_id']);

        $primoCensimento = DB::table('assets')
            ->join('areas', 'areas.id', '=', 'assets.area_id')
            ->join('localities', 'localities.id', '=', 'areas.locality_id')
            ->join('sites', 'sites.id', '=', 'localities.site_id')
            ->where('assets.tenant_id', $client->tenant_id)
            ->where('sites.client_id', $client->id)
            ->whereNull('assets.deleted_at')
            ->min('assets.created_at');

        $primoOrdine = DB::table('work_orders')
            ->where('tenant_id', $client->tenant_id)
            ->where('client_id', $client->id)
            ->whereNull('deleted_at')
            ->min('created_at');

        $date = array_filter([$primoCensimento, $primoOrdine]);

        return response()->json([
            'data' => [
                'primo_anno' => $date === []
                    ? Carbon::now('Europe/Rome')->year
                    : min(array_map(fn ($d) => Carbon::parse($d)->setTimezone('Europe/Rome')->year, $date)),
            ],
        ]);
    }

    /** @return array{0: Client, 1: int} */
    private function parametri(Request $request): array
    {
        $annoCorrente = Carbon::now('Europe/Rome')->year;

        $data = $request->validate([
            'client_id' => ['required', 'uuid'],
            // Un anno futuro non ha niente da raccontare; prima del 2000 è
            // un refuso: il censimento digitale non esisteva
            'anno' => ['required', 'integer', 'min:2000', "max:{$annoCorrente}"],
        ], [
            'client_id.required' => 'Indicare il committente della relazione.',
            'anno.required' => 'Indicare l\'anno della relazione.',
            'anno.max' => 'La relazione si stampa per un anno già cominciato, non per il futuro.',
        ]);

        // findOrFail dentro TenantScope: il committente di un'altra impresa
        // deve dare "non trovato", non un documento vuoto
        return [Client::query()->findOrFail($data['client_id']), (int) $data['anno']];
    }
}
