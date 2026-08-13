<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<style>
    body { font-family: 'DejaVu Sans Mono', monospace; color: #111; margin: 16px; text-align: center; }
    .org { font-size: 8px; text-transform: uppercase; letter-spacing: 0.08em; color: #444; }
    h1 { font-size: 14px; margin: 4px 0 0; }
    .sci { font-size: 10px; color: #333; margin: 2px 0 8px; }
    img.qr { width: 200px; height: 200px; }
    .hint { font-size: 9px; margin-top: 6px; }
    .url { font-size: 7px; color: #555; word-break: break-all; margin-top: 4px; }
    .box { border: 2px solid #111; border-radius: 8px; padding: 12px 10px; }
</style>
</head>
<body>
    <div class="box">
        <div class="org">{{ $organization?->name }}</div>
        <h1>{{ $asset->tree?->common_name ?: $asset->objectType?->name }}</h1>
        @if ($asset->tree && ($asset->tree->genus || $asset->tree->species))
        <div class="sci">{{ trim(($asset->tree->genus ?? '').' '.($asset->tree->species ?? '')) }}</div>
        @endif
        <img class="qr" src="{{ $qrDataUri }}" alt="Codice QR">
        <div class="hint">Inquadra il codice per conoscere questa pianta</div>
        <div class="url">{{ $url }}</div>
    </div>
</body>
</html>
