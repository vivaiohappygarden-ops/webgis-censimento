<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Pdf\Intestazione;
use App\Services\Photos\ImageDerivative;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * L'intestazione dei documenti dell'organizzazione: ragione sociale, partita
 * IVA, recapiti e logo, regolati da chi gestisce gli utenti. Sono i dati che
 * escono in cima a ogni PDF (Intestazione::per).
 */
class IntestazioneController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('can:users.manage', except: ['fileLogo'])];
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->dati(Organization::query()->findOrFail($request->user()->tenant_id))]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nome' => ['required', 'string', 'max:200'],
            'partita_iva' => ['nullable', 'string', 'max:20'],
            'codice_fiscale' => ['nullable', 'string', 'max:20'],
            'indirizzo' => ['nullable', 'string', 'max:200'],
            'comune' => ['nullable', 'string', 'max:120'],
            'telefono' => ['nullable', 'string', 'max:60'],
            'email' => ['nullable', 'email', 'max:150'],
            'pec' => ['nullable', 'email', 'max:150'],
            'sito' => ['nullable', 'string', 'max:200'],
        ], [
            'nome.required' => 'La ragione sociale non può restare vuota: è quello che compare in cima ai documenti.',
        ]);
        $pulisci = fn ($v) => $v !== null && trim((string) $v) !== '' ? trim((string) $v) : null;

        // Sotto lock, come le altre impostazioni dell'organizzazione
        $organizzazione = DB::transaction(function () use ($request, $data, $pulisci) {
            $organizzazione = Organization::query()->lockForUpdate()->findOrFail($request->user()->tenant_id);
            $branding = $organizzazione->branding ?? [];
            foreach (Intestazione::CAMPI as $campo) {
                $branding[$campo] = $pulisci($data[$campo] ?? null);
            }
            $organizzazione->forceFill([
                'name' => trim($data['nome']),
                'vat_number' => $pulisci($data['partita_iva'] ?? null),
                'branding' => $branding,
            ])->save();

            return $organizzazione;
        });
        Audit::log('intestazione.aggiornata', $organizzazione, ['nome' => $organizzazione->name]);

        return response()->json(['data' => $this->dati($organizzazione)]);
    }

    /** Il logo viene ricodificato in PNG (max 600 px): via i metadati, formato che dompdf stampa bene. */
    public function logo(Request $request): JsonResponse
    {
        $request->validate(['logo' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:4096']]);
        $png = ImageDerivative::png(file_get_contents($request->file('logo')->getRealPath()), maxDimension: 600);
        if ($png === null) {
            throw ValidationException::withMessages(['logo' => 'Immagine non leggibile: usa un file JPEG, PNG o WEBP di dimensioni normali.']);
        }

        $organizzazione = DB::transaction(function () use ($request, $png) {
            $organizzazione = Organization::query()->lockForUpdate()->findOrFail($request->user()->tenant_id);
            $branding = $organizzazione->branding ?? [];
            $precedente = $branding['logo_path'] ?? null;
            // Il nome cambia a ogni caricamento: la pagina non mostra il logo vecchio dalla cache
            $percorso = Intestazione::CARTELLA."/{$organizzazione->id}/logo-".now()->format('YmdHis').'-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(6)).'.png';
            Storage::disk()->put($percorso, $png);
            $branding['logo_path'] = $percorso;
            $organizzazione->forceFill(['branding' => $branding])->save();
            if ($precedente && $precedente !== $percorso) {
                Storage::disk()->delete($precedente);
            }

            return $organizzazione;
        });
        Audit::log('intestazione.logo', $organizzazione);

        return response()->json(['data' => $this->dati($organizzazione)]);
    }

    public function rimuoviLogo(Request $request): JsonResponse
    {
        $organizzazione = DB::transaction(function () use ($request) {
            $organizzazione = Organization::query()->lockForUpdate()->findOrFail($request->user()->tenant_id);
            $branding = $organizzazione->branding ?? [];
            if (! empty($branding['logo_path'])) {
                Storage::disk()->delete($branding['logo_path']);
            }
            unset($branding['logo_path']);
            $organizzazione->forceFill(['branding' => $branding])->save();

            return $organizzazione;
        });
        Audit::log('intestazione.logo_tolto', $organizzazione);

        return response()->json(['data' => $this->dati($organizzazione)]);
    }

    /** Il logo della propria organizzazione, per l'anteprima nella pagina delle impostazioni. */
    public function fileLogo(Request $request)
    {
        $organizzazione = Organization::query()->findOrFail($request->user()->tenant_id);
        $percorso = $organizzazione->branding['logo_path'] ?? null;
        abort_unless($percorso && Storage::disk()->exists($percorso), 404);

        return response(Storage::disk()->get($percorso), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    private function dati(Organization $organizzazione): array
    {
        $branding = $organizzazione->branding ?? [];
        $dati = ['nome' => $organizzazione->name, 'partita_iva' => $organizzazione->vat_number];
        foreach (Intestazione::CAMPI as $campo) {
            $dati[$campo] = $branding[$campo] ?? null;
        }
        $dati['logo_url'] = ! empty($branding['logo_path'])
            ? '/api/v1/intestazione/logo?v='.substr(md5($branding['logo_path']), 0, 8) : null;

        return $dati;
    }
}
