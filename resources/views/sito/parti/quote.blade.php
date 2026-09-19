{{-- Tavola delle quote: che cosa si misura su un albero, disegnato come una
     tavola tecnica (griglia, linee di estensione, tacche di quota, cartiglio).
     I richiami sono numeri dentro al disegno e la legenda sta in HTML, cosi'
     le etichette restano a corpo pieno anche sul telefono. Niente <title>
     dentro l'SVG: il browser lo mostrerebbe come nuvoletta al passaggio del
     mouse; la descrizione sta in aria-label. --}}
<div class="quote">
    <figure class="quote-disegno">
        <svg viewBox="0 0 440 500" role="img" aria-label="Schema di un albero con le quattro grandezze rilevate: altezza totale, ampiezza della chioma, circonferenza del fusto a 1,30 metri da terra, cartellino con codice QR" font-family="Inter, system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif">
            <defs>
                <marker id="tacca" markerWidth="8" markerHeight="8" refX="4" refY="4" orient="auto">
                    <path d="M1 7L7 1" stroke="#152019" stroke-width="1.3" />
                </marker>
            </defs>
            {{-- carta millimetrata --}}
            <g stroke="#e7eae5" stroke-width="1">
                <path d="M20 40H420M20 80H420M20 120H420M20 160H420M20 200H420M20 240H420M20 280H420M20 320H420M20 360H420M20 400H420" />
                <path d="M60 20V460M100 20V460M140 20V460M180 20V460M220 20V460M260 20V460M300 20V460M340 20V460M380 20V460" />
            </g>
            {{-- terreno con tratteggio --}}
            <path d="M20 430H420" stroke="#152019" stroke-width="1.8" />
            <path d="M40 430l-12 12M70 430l-12 12M100 430l-12 12M130 430l-12 12M160 430l-12 12M190 430l-12 12M220 430l-12 12M250 430l-12 12M280 430l-12 12M310 430l-12 12M340 430l-12 12M370 430l-12 12M400 430l-12 12" stroke="#8e9a92" stroke-width="1" />
            {{-- chioma dal profilo naturale --}}
            <path d="M136 236C114 208 118 166 146 148C136 114 164 84 198 90C210 58 270 58 282 90C316 84 344 114 334 148C362 166 366 208 344 236C356 268 326 300 292 290C278 312 202 312 188 290C154 300 124 268 136 236Z" fill="#dfe8dd" stroke="#12382a" stroke-width="1.8" stroke-linejoin="round" />
            {{-- branche --}}
            <path d="M240 300C238 262 226 232 206 200M240 300C244 254 262 222 284 190M240 292C240 250 236 210 242 168M226 236C214 224 202 218 190 216M258 226C272 214 286 210 300 210" fill="none" stroke="#12382a" stroke-width="1.2" />
            {{-- fusto con colletto --}}
            <path d="M224 300C226 340 226 380 224 412C220 422 208 428 196 430H284C272 428 260 422 256 412C254 380 254 340 256 300" fill="#fbfaf6" stroke="#12382a" stroke-width="1.8" stroke-linejoin="round" />
            <path d="M232 320C231 350 231 380 232 405M248 320C249 350 249 380 248 405" fill="none" stroke="#c9d0c9" stroke-width="1" />
            {{-- fascia della misura a 1,30 m --}}
            <path d="M223 372H257" stroke="#152019" stroke-width="3" />
            <path d="M225 372c0 3 6 5 15 5s15-2 15-5" fill="none" stroke="#152019" stroke-width="1.2" />
            {{-- cartellino con codice QR --}}
            <rect x="223" y="316" width="34" height="26" rx="2" fill="#fbfaf6" stroke="#152019" stroke-width="1.4" />
            <path fill="#152019" d="M228 320h5v5h-5zM236 320h5v5h-5zM228 328h5v5h-5zM237 329h2v2h-2zM240 331h2v2h-2zM245 320h2v2h-2zM246 324h4v3h-4zM244 328h2v4h-2zM248 333h3v2h-3z" />
            {{-- 2: ampiezza della chioma --}}
            <g fill="none" stroke="#8e9a92" stroke-width="1.1" stroke-dasharray="3 4">
                <path d="M123 232V34M357 232V34" />
            </g>
            <path d="M123 40H357" fill="none" stroke="#152019" stroke-width="1.3" marker-start="url(#tacca)" marker-end="url(#tacca)" />
            <circle cx="240" cy="40" r="17" fill="#152019" />
            <text x="240" y="48" text-anchor="middle" font-size="23" font-weight="700" fill="#c8e253">2</text>
            {{-- 1: altezza totale --}}
            <g fill="none" stroke="#8e9a92" stroke-width="1.1" stroke-dasharray="3 4">
                <path d="M240 66H412" />
            </g>
            <path d="M406 66V430" fill="none" stroke="#152019" stroke-width="1.3" marker-start="url(#tacca)" marker-end="url(#tacca)" />
            <circle cx="406" cy="248" r="17" fill="#152019" />
            <text x="406" y="256" text-anchor="middle" font-size="23" font-weight="700" fill="#c8e253">1</text>
            {{-- 3: circonferenza a 1,30 m da terra --}}
            <g fill="none" stroke="#8e9a92" stroke-width="1.1" stroke-dasharray="3 4">
                <path d="M222 372H44" />
            </g>
            <path d="M50 372V430" fill="none" stroke="#152019" stroke-width="1.3" marker-start="url(#tacca)" marker-end="url(#tacca)" />
            <circle cx="50" cy="401" r="17" fill="#152019" />
            <text x="50" y="409" text-anchor="middle" font-size="23" font-weight="700" fill="#c8e253">3</text>
            <text x="76" y="409" font-size="23" font-weight="600" fill="#152019">1,30 m</text>
            {{-- 4: cartellino --}}
            <path d="M257 329H300" stroke="#8e9a92" stroke-width="1.1" stroke-dasharray="3 4" />
            <circle cx="318" cy="329" r="17" fill="#152019" />
            <text x="318" y="337" text-anchor="middle" font-size="23" font-weight="700" fill="#c8e253">4</text>
        </svg>
        <figcaption class="cartiglio-tavola">
            <span><strong>Tav. 1</strong> Grandezze rilevate per ogni albero</span>
            <span>Non in scala</span>
        </figcaption>
    </figure>
    <ol class="legenda" aria-label="Legenda della tavola">
        <li><span class="n" aria-hidden="true">1</span><span><strong>Altezza totale</strong>, da terra alla cima della chioma. In metri.</span></li>
        <li><span class="n" aria-hidden="true">2</span><span><strong>Ampiezza della chioma</strong>: il diametro medio della sua proiezione a terra. In metri.</span></li>
        <li><span class="n" aria-hidden="true">3</span><span><strong>Circonferenza del fusto a 1,30 m</strong> da terra, misurata con la fettuccia; da questa si ricava il diametro. In centimetri.</span></li>
        <li><span class="n" aria-hidden="true">4</span><span><strong>Cartellino</strong> con numero univoco e codice QR, applicato al fusto sopra la quota di misura.</span></li>
    </ol>
</div>
