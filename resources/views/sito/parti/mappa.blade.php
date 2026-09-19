{{-- Mappa stilizzata: strade, un parco, gli alberi censiti come punti e uno
     di loro evidenziato con le linee di coordinate che lo agganciano alla
     cornice. Nessun testo dentro il disegno (le etichette stanno nella
     targhetta in HTML, cosi' restano leggibili a ogni scala). E' decorativa
     per il lettore di schermo: la scheda accanto dice tutto quello che serve.
     Tono: 'scuro' sul verde bosco (apertura), 'chiaro' sulla carta. --}}
@php($chiara = ($tono ?? 'scuro') === 'chiaro')
<svg class="mappa {{ $chiara ? 'mappa-chiara' : '' }}" viewBox="0 0 640 480" aria-hidden="true" focusable="false"
     style="--m-linea: {{ $chiara ? '#c9d0c9' : 'rgba(244, 241, 232, 0.30)' }}; --m-linea-2: {{ $chiara ? '#e2e6e2' : 'rgba(244, 241, 232, 0.14)' }}; --m-parco: {{ $chiara ? 'rgba(71, 111, 82, 0.22)' : 'rgba(71, 111, 82, 0.55)' }}; --m-albero: {{ $chiara ? '#476f52' : '#f4f1e8' }}; --m-quadro: {{ $chiara ? '#59645d' : 'rgba(244, 241, 232, 0.55)' }}; --m-croce: {{ $chiara ? '#476f52' : '#c8e253' }};">
    {{-- isolati --}}
    <g fill="var(--m-linea-2)">
        <rect x="24" y="24" width="72" height="56" />
        <rect x="24" y="150" width="68" height="120" />
        <rect x="24" y="330" width="80" height="64" />
        <rect x="160" y="30" width="110" height="60" />
        <rect x="170" y="160" width="100" height="90" />
        <rect x="170" y="340" width="120" height="50" />
        <rect x="490" y="20" width="60" height="70" />
        <rect x="560" y="360" width="60" height="90" />
        <rect x="340" y="380" width="120" height="70" />
    </g>
    {{-- strade --}}
    <g fill="none" stroke="var(--m-linea)" stroke-linecap="round">
        <path d="M0 300C160 280 300 340 640 290" stroke-width="10" />
        <path d="M120 0C140 160 90 320 130 480" stroke-width="5" />
        <path d="M300 0C290 120 320 250 300 480" stroke-width="4" />
        <path d="M0 120C200 140 400 90 640 130" stroke-width="5" />
        <path d="M0 420L640 400" stroke-width="4" />
        <path d="M470 0C450 140 520 300 480 480" stroke-width="3" />
    </g>
    {{-- il parco --}}
    <path d="M330 150C380 120 480 130 520 180C560 230 540 300 480 320C420 340 350 330 320 280C290 230 290 180 330 150Z" fill="var(--m-parco)" stroke="var(--m-linea)" stroke-width="1.5" />
    <path d="M340 200C400 220 450 250 500 300M360 300C390 260 430 240 505 210" fill="none" stroke="var(--m-linea)" stroke-width="1.5" stroke-dasharray="4 5" />
    {{-- gli alberi: il viale e il parco --}}
    <g fill="var(--m-albero)" fill-opacity="0.85">
        <circle cx="80" cy="284" r="4" /><circle cx="140" cy="279" r="4" /><circle cx="200" cy="283" r="4" /><circle cx="260" cy="291" r="4" /><circle cx="320" cy="297" r="4" /><circle cx="380" cy="297" r="4" /><circle cx="440" cy="291" r="4" /><circle cx="500" cy="285" r="4" /><circle cx="560" cy="280" r="4" /><circle cx="620" cy="276" r="4" />
        <circle cx="360" cy="180" r="5" /><circle cx="400" cy="165" r="5" /><circle cx="450" cy="170" r="5" /><circle cx="490" cy="200" r="5" /><circle cx="505" cy="245" r="5" /><circle cx="480" cy="290" r="5" /><circle cx="430" cy="300" r="5" /><circle cx="380" cy="290" r="5" /><circle cx="345" cy="250" r="5" /><circle cx="440" cy="215" r="5" /><circle cx="470" cy="260" r="5" /><circle cx="365" cy="215" r="5" />
    </g>
    {{-- coordinate dell'albero evidenziato: le linee raggiungono la cornice --}}
    <g stroke="var(--m-croce)" stroke-width="1.2" stroke-dasharray="3 5" fill="none">
        <path d="M400 0V210M400 250V480M0 230H380M420 230H640" />
    </g>
    <circle cx="400" cy="230" r="18" fill="none" stroke="var(--m-croce)" stroke-width="1.5" />
    <circle cx="400" cy="230" r="9" fill="#c8e253" stroke="#152019" stroke-width="2" />
    {{-- tacche di cornice: i segni del rilievo --}}
    <g stroke="var(--m-quadro)" stroke-width="1.5">
        <path d="M64 0v10M128 0v10M192 0v10M256 0v10M320 0v10M384 0v10M448 0v10M512 0v10M576 0v10" />
        <path d="M64 480v-10M128 480v-10M192 480v-10M256 480v-10M320 480v-10M384 480v-10M448 480v-10M512 480v-10M576 480v-10" />
        <path d="M0 64h10M0 128h10M0 192h10M0 256h10M0 320h10M0 384h10M0 448h10" />
        <path d="M640 64h-10M640 128h-10M640 192h-10M640 256h-10M640 320h-10M640 384h-10M640 448h-10" />
    </g>
    {{-- rosa dei venti --}}
    <path d="M590 22l6 20 20 6-20 6-6 20-6-20-20-6 20-6z" fill="var(--m-quadro)" />
    <path d="M590 22l6 20-6 6z" fill="#c8e253" />
</svg>
