<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Oggi\CoseDaFare;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Cruscotto "Oggi" della veste precedente: tutto ciò che richiede attenzione,
 * per sezione, in un'unica chiamata. Le definizioni stanno in CoseDaFare,
 * condivise con la pagina Oggi della veste nuova.
 */
class DashboardController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('can:works.view')];
    }

    public function today(Request $request, CoseDaFare $cose): JsonResponse
    {
        return response()->json(['data' => $cose->perSezione($request->user(), $cose->oggi())]);
    }
}
