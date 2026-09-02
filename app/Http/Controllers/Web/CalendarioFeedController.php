<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Services\Calendario\CalendarioAbbonamento;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\PermissionRegistrar;

/**
 * Il feed iCal del calendario da abbonamento. La rotta vive fuori dal
 * gruppo "web" (vedi bootstrap/app.php): Google Calendar e gli altri
 * lettori non hanno sessione e non devono ricevere cookie. L'unico
 * riconoscimento è il gettone nell'indirizzo.
 */
class CalendarioFeedController extends Controller
{
    public function feed(string $gettone, CalendarioAbbonamento $calendario): Response
    {
        // Senza utente autenticato TenantScope non filtra: il gettone si
        // cerca esplicitamente, e su qualunque anomalia (gettone ignoto,
        // utente spento, organizzazione disattivata) la risposta è un 404
        // muto: a chi tenta indirizzi non si spiega che cosa non torna
        $user = User::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('calendar_token', $gettone)
            ->where('is_active', true)
            ->first();
        abort_if($user === null, 404);

        $organizzazioneAttiva = Organization::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('id', $user->tenant_id)
            ->where('is_active', true)
            ->exists();
        abort_unless($organizzazioneAttiva, 404);

        // Da qui in poi il feed lavora come l'utente: TenantScope filtra le
        // query e i controlli can() valgono nel suo tenant (team spatie).
        // setUser non tocca la sessione: nessun cookie parte da qui
        Auth::setUser($user);
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);

        return response($calendario->ics($user), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            // Qualche minuto di respiro: i lettori esterni riscaricano tutto
            // il file, e "private" perché l'agenda è personale
            'Cache-Control' => 'private, max-age=600',
        ]);
    }
}
