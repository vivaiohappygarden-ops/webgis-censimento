<?php

namespace App\Services\Carto;

use App\Models\Client;
use Illuminate\Validation\Rule;

/**
 * Sfondi cartografici del committente, pronti per la mappa.
 *
 * Il committente puo' avere fino a MASSIMO sfondi suoi (l'ortofoto comunale,
 * la carta tecnica regionale): o un servizio a riquadri numerati (z/x/y) o un
 * WMS. Qui, in un solo posto, le voci salvate diventano modelli di indirizzo
 * che MapLibre sa usare: per il WMS si costruisce la chiamata GetMap su
 * riquadri da 256 pixel in EPSG:3857, col segnaposto {bbox-epsg-3857} che la
 * mappa sostituisce da sola. Si usa la versione WMS 1.1.1 (SRS): con la
 * 1.3.0 alcuni server invertono l'ordine degli assi e le immagini escono
 * fuori posto. Servizi in altri sistemi di coordinate o protetti da password
 * non sono previsti: l'indirizzo deve rispondere in chiaro e in 3857.
 */
class SfondiCommittente
{
    public const MASSIMO = 6;

    /** Regole per la scrittura, usate dalla validazione del committente. */
    public static function regole(): array
    {
        return [
            'basemaps' => ['sometimes', 'nullable', 'array', 'max:'.self::MASSIMO],
            'basemaps.*.nome' => ['required', 'string', 'max:60'],
            'basemaps.*.tipo' => ['required', Rule::in(['xyz', 'wms'])],
            'basemaps.*.url' => ['required', 'string', 'max:500', 'starts_with:https://',
                function (string $attribute, mixed $value, \Closure $fail) {
                    // Un modello z/x/y senza i segnaposto non disegna niente
                    $indice = (int) explode('.', $attribute)[1];
                    $tipo = request()->input("basemaps.{$indice}.tipo");
                    if ($tipo === 'xyz' && (! str_contains((string) $value, '{z}')
                        || ! str_contains((string) $value, '{x}') || ! str_contains((string) $value, '{y}'))) {
                        $fail('L\'indirizzo a riquadri deve contenere i segnaposto {z}, {x} e {y}.');
                    }
                },
            ],
            'basemaps.*.layer' => ['required_if:basemaps.*.tipo,wms', 'nullable', 'string', 'max:200'],
            'basemaps.*.attribuzione' => ['sometimes', 'nullable', 'string', 'max:200'],
        ];
    }

    /**
     * Gli sfondi del committente nella stessa forma di quelli del portale:
     * id, nome, modello di indirizzo, attribuzione, zoom massimo.
     *
     * @return list<array{id: string, nome: string, url: string, attribuzione: string, zoom_massimo: int, scuro: bool}>
     */
    public static function perMappa(?Client $client): array
    {
        $sfondi = [];
        foreach (array_values((array) $client?->basemaps) as $i => $voce) {
            if (! is_array($voce) || empty($voce['nome']) || empty($voce['url'])) {
                continue;
            }
            $sfondi[] = [
                'id' => 'committente-'.$i,
                'nome' => (string) $voce['nome'],
                'url' => ($voce['tipo'] ?? 'xyz') === 'wms' ? self::modelloWms($voce) : (string) $voce['url'],
                'attribuzione' => (string) ($voce['attribuzione'] ?? ''),
                'zoom_massimo' => 19,
                'scuro' => false,
            ];
        }

        return $sfondi;
    }

    private static function modelloWms(array $voce): string
    {
        $base = rtrim((string) $voce['url'], '?&');
        $separatore = str_contains($base, '?') ? '&' : '?';

        // Il segnaposto del riquadro si aggiunge fuori da http_build_query:
        // codificato non verrebbe piu' riconosciuto dalla mappa
        return $base.$separatore.http_build_query([
            'SERVICE' => 'WMS',
            'VERSION' => '1.1.1',
            'REQUEST' => 'GetMap',
            'LAYERS' => (string) ($voce['layer'] ?? ''),
            'STYLES' => '',
            'SRS' => 'EPSG:3857',
            'WIDTH' => 256,
            'HEIGHT' => 256,
            'FORMAT' => 'image/png',
            'TRANSPARENT' => 'TRUE',
        ]).'&BBOX={bbox-epsg-3857}';
    }
}
