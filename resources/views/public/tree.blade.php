<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $asset->tree?->common_name ?: $asset->objectType?->name }} - {{ $organization?->name }}</title>
<style>
    :root { color-scheme: light; }
    body { font-family: 'Courier New', Courier, ui-monospace, monospace; margin: 0; background: #f4f6f4; color: #172217; }
    .card { max-width: 640px; margin: 0 auto; padding: 24px 20px 48px; }
    .org { font-size: 12px; color: #4b5a4b; text-transform: uppercase; letter-spacing: 0.08em; }
    h1 { font-size: 26px; margin: 6px 0 2px; }
    .sci { font-size: 15px; color: #3d4f3d; margin-bottom: 18px; }
    img.foto { width: 100%; border-radius: 12px; border: 1px solid #cdd7cd; margin: 8px 0 18px; }
    dl { margin: 0; border-top: 1px solid #cdd7cd; }
    .row { display: flex; justify-content: space-between; gap: 16px; padding: 10px 0; border-bottom: 1px solid #dde5dd; font-size: 14px; }
    dt { color: #4b5a4b; }
    dd { margin: 0; text-align: right; }
    .dedica { margin: 18px 0 0; padding: 14px; background: #e9f0e9; border-radius: 12px; font-size: 14px; }
    .footer { margin-top: 28px; font-size: 11px; color: #6a786a; }
</style>
</head>
<body>
    <div class="card">
        <div class="org">{{ $organization?->name }} - censimento del verde</div>
        <h1>{{ $asset->tree?->common_name ?: $asset->objectType?->name }}</h1>
        @if ($asset->tree && ($asset->tree->genus || $asset->tree->species))
        <div class="sci">{{ trim(($asset->tree->genus ?? '').' '.($asset->tree->species ?? '')) }}@if ($asset->tree->cultivar) '{{ $asset->tree->cultivar }}'@endif</div>
        @endif

        @if ($hasPhoto)
        <img class="foto" src="{{ route('public.tree.photo', $token) }}" alt="Fotografia dell'elemento">
        @endif

        <dl>
            @if ($asset->area?->name)
            <div class="row"><dt>Dove si trova</dt><dd>{{ $asset->area->name }}@if ($asset->area->locality?->site?->name), {{ $asset->area->locality->site->name }}@endif</dd></div>
            @endif
            @if ($asset->tree?->height_m)
            <div class="row"><dt>Altezza</dt><dd>{{ rtrim(rtrim(number_format((float) $asset->tree->height_m, 1, ',', ''), '0'), ',') }} metri</dd></div>
            @endif
            @if ($asset->tree?->age_years_est)
            <div class="row"><dt>Età stimata</dt><dd>circa {{ $asset->tree->age_years_est }} anni</dd></div>
            @endif
            @if ($asset->tree?->planted_on)
            <div class="row"><dt>Messo a dimora</dt><dd>{{ $asset->tree->planted_on->format('Y') }}</dd></div>
            @endif
            @if ($asset->tree?->is_monumental)
            <div class="row"><dt>Albero monumentale</dt><dd>sì@if ($asset->tree->monumental_ref) ({{ $asset->tree->monumental_ref }})@endif</dd></div>
            @endif
        </dl>

        @if ($asset->tree?->is_dedicated && ($asset->tree->dedicated_to['name'] ?? null))
        <p class="dedica">
            Questo albero è dedicato a <strong>{{ $asset->tree->dedicated_to['name'] }}</strong>@if ($asset->tree->dedicated_to['occasion'] ?? null) ({{ $asset->tree->dedicated_to['occasion'] }})@endif.
        </p>
        @endif

        <p class="footer">
            Pagina informativa a cura di {{ $organization?->name }}.
            Per segnalazioni sul verde rivolgersi al gestore dell'area.
        </p>
    </div>
</body>
</html>
