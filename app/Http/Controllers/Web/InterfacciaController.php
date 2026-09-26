<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\Interfaccia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * La scelta personale fra la nuova interfaccia e la precedente: si salva
 * sull'utente e vale da subito, su qualunque dispositivo.
 */
class InterfacciaController extends Controller
{
    public function scegli(Request $request): RedirectResponse
    {
        $dati = $request->validate([
            'modo' => ['required', Rule::in(Interfaccia::MODI)],
        ]);

        $utente = $request->user();
        $utente->settings = [...($utente->settings ?? []), 'interfaccia' => $dati['modo']];
        $utente->save();

        // Si riparte dalla pagina di casa della veste scelta: una pagina della
        // veste vecchia aperta nella nuova (o viceversa) confonderebbe
        return redirect('/');
    }
}
