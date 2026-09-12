<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Playgrounds\ModelloAreeGioco;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Il corredo pronto per le aree gioco: campi della scheda dell'attrezzo e
 * liste di controllo sulla UNI EN 1176-7.
 *
 * Due permessi, perche' l'installazione tocca due cose diverse: il catalogo
 * (i campi della scheda) e i modelli di ispezione. Chi puo' fare solo una
 * delle due non deve poter fare l'altra di straforo.
 */
class AreeGiocoController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware(['can:catalog.manage', 'can:works.manage']),
        ];
    }

    public function installa(Request $request, ModelloAreeGioco $modello): JsonResponse
    {
        $request->validate(['prova' => ['sometimes', 'boolean']]);

        return response()->json([
            'data' => $modello->installa($request->user()->tenant_id, $request->boolean('prova')),
        ]);
    }
}
