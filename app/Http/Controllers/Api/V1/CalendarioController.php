<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Il gettone personale del calendario da abbonamento. Ognuno gestisce solo
 * il proprio: il gettone è una chiave d'accesso all'agenda e non passa mai
 * dagli elenchi utenti (è in $hidden sul modello User).
 */
class CalendarioController extends Controller
{
    /** Legge il gettone, creandolo alla prima apertura del pannello. */
    public function gettone(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->calendar_token === null) {
            $user->forceFill(['calendar_token' => self::nuovoGettone()])->save();
            Audit::log('calendar_token.created', $user);
        }

        return response()->json(['data' => $this->presenta($user)]);
    }

    /**
     * Sostituisce il gettone: il vecchio indirizzo smette di funzionare
     * all'istante. È l'unico modo di revocare un link finito in giro.
     */
    public function rigenera(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->forceFill(['calendar_token' => self::nuovoGettone()])->save();
        Audit::log('calendar_token.regenerated', $user);

        return response()->json(['data' => $this->presenta($user)]);
    }

    /**
     * 48 caratteri alfanumerici casuali (Str::random usa random_bytes):
     * url-sicuri e ben oltre i 40 richiesti. L'indice unico sul DB para la
     * collisione, che a questa lunghezza è teorica.
     */
    private static function nuovoGettone(): string
    {
        return Str::random(48);
    }

    private function presenta(User $user): array
    {
        return [
            'url' => route('calendario.feed', ['gettone' => $user->calendar_token]),
        ];
    }
}
