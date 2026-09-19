{{-- Tavola delle quote: che cosa si misura su un albero. I richiami sono
     numeri dentro al disegno e la legenda sta in HTML, cosi' le etichette
     restano a corpo pieno anche sul telefono. --}}
<div class="quote">
    <figure class="quote-disegno">
        <svg viewBox="0 0 420 440" role="img" aria-labelledby="quote-titolo" font-family="Inter, system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif">
            <title id="quote-titolo">Schema di un albero con le quattro grandezze rilevate: altezza, diametro della chioma, circonferenza a 1,30 metri da terra, cartellino</title>
            <defs>
                <marker id="tacca" markerWidth="8" markerHeight="8" refX="4" refY="4" orient="auto">
                    <path d="M1 7L7 1" stroke="#152019" stroke-width="1.2" />
                </marker>
            </defs>
            <g stroke="#e6e9e4" stroke-width="1">
                <path d="M40 30H380M40 90H380M40 150H380M40 210H380M40 270H380M40 330H380" />
                <path d="M100 20V400M160 20V400M220 20V400M280 20V400" />
            </g>
            <path d="M30 390H400" stroke="#152019" stroke-width="1.6" />
            <path d="M50 390l-10 10M80 390l-10 10M110 390l-10 10M140 390l-10 10M170 390l-10 10M200 390l-10 10M230 390l-10 10M260 390l-10 10M290 390l-10 10M320 390l-10 10M350 390l-10 10M380 390l-10 10" stroke="#8e9a92" stroke-width="1" />
            <ellipse cx="200" cy="170" rx="118" ry="102" fill="#dfe8dd" stroke="#12382a" stroke-width="1.8" />
            <path d="M188 390V262c0-8 3-14 8-18M212 390V262c0-8-3-14-8-18" fill="none" stroke="#12382a" stroke-width="1.8" />
            <path d="M188 262c0 8-7 14-16 18M212 262c0 8 7 14 16 18M200 244c-4-24 4-52 0-76" fill="none" stroke="#12382a" stroke-width="1.4" />
            <rect x="180" y="286" width="40" height="26" rx="2" fill="#fbfaf6" stroke="#152019" stroke-width="1.4" />
            <path d="M186 292h10v10h-10zM204 292h10v10h-10zM186 306h10M204 304h4v4h-4z" fill="none" stroke="#152019" stroke-width="1.2" />
            {{-- 2: diametro della chioma --}}
            <g stroke="#152019" stroke-width="1.2" fill="none">
                <path d="M82 44V166M318 44V166" stroke-dasharray="3 3" stroke="#8e9a92" />
                <path d="M82 50H318" marker-start="url(#tacca)" marker-end="url(#tacca)" />
            </g>
            <circle cx="200" cy="50" r="16" fill="#152019" />
            <text x="200" y="57" text-anchor="middle" font-size="20" font-weight="700" fill="#c8e253">2</text>
            {{-- 1: altezza --}}
            <g stroke="#152019" stroke-width="1.2" fill="none">
                <path d="M200 68H392" stroke-dasharray="3 3" stroke="#8e9a92" />
                <path d="M386 68V390" marker-start="url(#tacca)" marker-end="url(#tacca)" />
            </g>
            <circle cx="386" cy="229" r="16" fill="#152019" />
            <text x="386" y="236" text-anchor="middle" font-size="20" font-weight="700" fill="#c8e253">1</text>
            {{-- 3: circonferenza a 1,30 m --}}
            <path d="M186 332h28" stroke="#152019" stroke-width="2" />
            <path d="M188 332c0 3 5 5 12 5s12-2 12-5" fill="none" stroke="#152019" stroke-width="1.2" />
            <g stroke="#152019" stroke-width="1.2" fill="none">
                <path d="M56 332H184" stroke-dasharray="3 3" stroke="#8e9a92" />
                <path d="M62 332V390" marker-start="url(#tacca)" marker-end="url(#tacca)" />
            </g>
            <circle cx="62" cy="361" r="16" fill="#152019" />
            <text x="62" y="368" text-anchor="middle" font-size="20" font-weight="700" fill="#c8e253">3</text>
            {{-- 4: cartellino --}}
            <path d="M220 299H252" stroke="#8e9a92" stroke-width="1" stroke-dasharray="3 3" />
            <circle cx="268" cy="299" r="16" fill="#152019" />
            <text x="268" y="306" text-anchor="middle" font-size="20" font-weight="700" fill="#c8e253">4</text>
        </svg>
        <figcaption class="tenue" style="margin-top: var(--s2); font-size: 17px;">Schema, non in scala.</figcaption>
    </figure>
    <ol class="legenda" aria-label="Legenda della tavola">
        <li><span class="n" aria-hidden="true">1</span><span><strong>Altezza</strong> totale dell'albero, da terra alla cima della chioma.</span></li>
        <li><span class="n" aria-hidden="true">2</span><span><strong>Ampiezza della chioma</strong>, misurata come diametro medio della proiezione a terra.</span></li>
        <li><span class="n" aria-hidden="true">3</span><span><strong>Circonferenza del fusto a 1,30 m</strong> da terra, da cui si ricava il diametro.</span></li>
        <li><span class="n" aria-hidden="true">4</span><span><strong>Cartellino</strong> con numero univoco e codice QR, applicato al fusto.</span></li>
    </ol>
</div>
