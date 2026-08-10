<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ListQuery
{
    public static function perPage(Request $request, int $default = 25, int $max = 100): int
    {
        return max(1, min((int) $request->input('per_page', $default), $max));
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} */
    public static function bbox(Request $request): array
    {
        $parts = array_map('trim', explode(',', (string) $request->input('bbox')));

        if (count($parts) !== 4 || array_filter($parts, fn ($p) => ! is_numeric($p)) !== []) {
            throw ValidationException::withMessages([
                'bbox' => 'bbox deve essere nel formato minLon,minLat,maxLon,maxLat con valori numerici.',
            ]);
        }

        return array_map('floatval', $parts);
    }

    /** Valida che i filtri indicati, se presenti, siano UUID (evita errori 500 dal cast uuid). */
    public static function validateUuidFilters(Request $request, array $keys): void
    {
        $request->validate(
            array_fill_keys($keys, ['nullable', 'uuid'])
        );
    }
}
