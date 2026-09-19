@php
    /*
    | Il telaio di ogni pagina del sito aziendale. Il sistema grafico ("Impatto",
    | committente 19/09/2026) e' documentato in testa a stile.blade.php.
    | Qui: metadati, testata con il menu senza script, briciole, pie' di pagina.
    | Nessun dato aziendale e' scritto nelle viste: tutto passa da SitoDati,
    | e quello che e' vuoto non viene stampato.
    */
    $nome = \App\Support\SitoDati::testo('nome') ?? 'Censimento Alberature';
    $sottotitolo = \App\Support\SitoDati::testo('sottotitolo');
    $azienda = \App\Support\SitoDati::gruppo('azienda');
    $contatti = \App\Support\SitoDati::gruppo('contatti');
    $righeSocietarie = \App\Support\SitoDati::righeSocietarie();
    $canonica = \App\Support\SitoUrl::canonica($pagina);
    $indicizzabile = \App\Support\SitoUrl::indicizzabile();
    $titolo = $pagina === 'home' ? $nome.' – '.$meta['titolo'] : $meta['titolo'].' – '.$nome;

    // Dati strutturati: solo sul dominio di produzione (dove esistono indirizzi
    // assoluti) e solo con quello che la configurazione dichiara. Niente
    // recensioni, niente valutazioni, niente numeri.
    $strutturati = [];
    if ($canonica) {
        $strutturati[] = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => $azienda['ragione_sociale'] ?? $nome,
            'legalName' => $azienda['ragione_sociale'] ?? null,
            'url' => \App\Support\SitoUrl::assoluto(),
            'address' => $azienda['sede'] ?? null,
            'vatID' => $azienda['piva'] ?? null,
            'taxID' => $azienda['codice_fiscale'] ?? null,
            'email' => $contatti['email'] ?? null,
            'telephone' => $contatti['telefono'] ?? null,
            'areaServed' => $azienda['territorio'] ?? null,
        ]);
        $strutturati[] = [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => $nome,
            'url' => \App\Support\SitoUrl::assoluto(),
            'inLanguage' => 'it',
        ];
        if ($pagina !== 'home') {
            $strutturati[] = [
                '@context' => 'https://schema.org',
                '@type' => 'BreadcrumbList',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => \App\Support\SitoUrl::assoluto()],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => $meta['breadcrumb'], 'item' => $canonica],
                ],
            ];
        }
    }
    $jsonOpzioni = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP;
@endphp
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $titolo }}</title>
<meta name="description" content="{{ $meta['descrizione'] }}">
{{-- Dal percorso di collaudo, e finche' il dominio non e' configurato, le
     pagine non si indicizzano: niente duplicati e niente indirizzi che non
     esistono. La canonical compare solo sul dominio di produzione. --}}
<meta name="robots" content="{{ $indicizzabile ? 'index, follow' : 'noindex, nofollow' }}">
@if ($canonica)
<link rel="canonical" href="{{ $canonica }}">
@endif
<meta name="theme-color" content="#12382a">
<link rel="icon" href="{{ \App\Support\SitoUrl::risorsa('favicon.svg') }}" type="image/svg+xml">
<meta property="og:type" content="website">
<meta property="og:site_name" content="{{ $nome }}">
<meta property="og:locale" content="it_IT">
<meta property="og:title" content="{{ $titolo }}">
<meta property="og:description" content="{{ $meta['descrizione'] }}">
@if ($canonica)
<meta property="og:url" content="{{ $canonica }}">
<meta property="og:image" content="https://{{ \App\Support\SitoUrl::dominio() }}{{ \App\Support\SitoUrl::risorsa('anteprima.png') }}">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="{{ $nome }}: censimento delle alberature per i Comuni">
@endif
<style>
@include('sito.caratteri')
@include('sito.stile')
</style>
@foreach ($strutturati as $dato)
<script type="application/ld+json">{!! json_encode($dato, $jsonOpzioni) !!}</script>
@endforeach
</head>
<body>

<a class="salta" href="#contenuto">Salta al contenuto</a>

<header class="testata">
    <div class="contenitore testata-riga">
        <a class="marchio" href="{{ $u() }}" @if ($pagina === 'home') aria-current="page" @endif>
            <span class="marchio-segno" aria-hidden="true"></span>
            <span>
                <span class="marchio-nome">{{ $nome }}</span>
                @if ($sottotitolo)
                    <span class="marchio-riga">{{ $sottotitolo }}</span>
                @endif
            </span>
        </a>

        {{-- Dai 1000px: il pulsante qui e il menu in linea nella riga sotto.
             Sotto i 1000px: il blocco details qui sotto. Uno solo dei due menu e'
             visibile, l'altro e' display:none e quindi fuori dall'albero di
             accessibilita'. --}}
        <a class="invito testata-azione" href="{{ $u('contatti') }}">Richiedi un sopralluogo</a>

        {{-- Menu del telefono senza script: details/summary. Si apre e si chiude
             con il tocco, con Invio o con la barra spaziatrice. --}}
        <details class="menu-mobile">
            <summary>Menu</summary>
            <nav class="menu-pannello su-scuro" aria-label="Pagine del sito">
                <ul>
                    @foreach ($menu as $slug => $etichetta)
                        <li><a href="{{ $u($slug) }}" @if ($pagina === $slug) aria-current="page" @endif>{{ $etichetta }}</a></li>
                    @endforeach
                    <li class="azione"><a class="invito" href="{{ $u('contatti') }}">Richiedi un sopralluogo</a></li>
                </ul>
            </nav>
        </details>
    </div>
    <nav class="menu-desktop" aria-label="Pagine del sito">
        <div class="contenitore">
            <ul>
                @foreach ($menu as $slug => $etichetta)
                    <li><a href="{{ $u($slug) }}" @if ($pagina === $slug) aria-current="page" @endif>{{ $etichetta }}</a></li>
                @endforeach
            </ul>
        </div>
    </nav>
</header>

@if ($pagina !== 'home')
    <nav class="percorso" aria-label="Percorso">
        <div class="contenitore">
            <ol>
                <li><a href="{{ $u() }}">Home</a></li>
                <li><span aria-current="page">{{ $meta['breadcrumb'] }}</span></li>
            </ol>
        </div>
    </nav>
@endif

<main id="contenuto">
    @yield('contenuto')
</main>

<footer class="piede su-scuro">
    <div class="contenitore">
        <div class="piede-griglia">
            <div>
                <p class="piede-marchio">{{ $azienda['ragione_sociale'] ?? $nome }}</p>
                @if ($sottotitolo)
                    <p class="piede-riga">{{ $sottotitolo }} per i Comuni e gli enti pubblici.</p>
                @endif
                @if ($righeSocietarie)
                    <div class="piede-dati">
                        @foreach ($righeSocietarie as $riga)
                            <p>{{ $riga }}</p>
                        @endforeach
                    </div>
                @endif
            </div>
            <div>
                <h2>Pagine</h2>
                <ul class="piede-voci">
                    @foreach ($menu as $slug => $etichetta)
                        <li><a href="{{ $u($slug) }}">{{ $etichetta }}</a></li>
                    @endforeach
                    <li><a href="{{ $u('privacy') }}">Privacy e note legali</a></li>
                </ul>
            </div>
            @if ($contatti)
                <div>
                    <h2>Contatti</h2>
                    <ul class="piede-voci">
                        @if (isset($contatti['telefono']))
                            <li><a href="{{ \App\Support\SitoDati::telefonoHref($contatti['telefono']) }}">{{ $contatti['telefono'] }}</a></li>
                        @endif
                        @if (isset($contatti['email']))
                            <li><a href="mailto:{{ $contatti['email'] }}">{{ $contatti['email'] }}</a></li>
                        @endif
                        @if (isset($contatti['pec']))
                            <li><a href="mailto:{{ $contatti['pec'] }}">PEC {{ $contatti['pec'] }}</a></li>
                        @endif
                        @if (isset($contatti['orari']))
                            <li class="tenue">{{ $contatti['orari'] }}</li>
                        @endif
                        @if (isset($contatti['indirizzo']))
                            <li class="tenue">{{ $contatti['indirizzo'] }}</li>
                        @endif
                    </ul>
                </div>
            @endif
        </div>

        <div class="piede-legale">
            {{-- Dichiarazione vera, non uno slogan: le pagine del sito non avviano
                 sessione, non hanno strumenti di statistica e non depositano
                 niente sul dispositivo di chi legge (lo verifica
                 SitoAziendaleTest). Per questo non c'e' un banner. --}}
            <p><strong>Nessun cookie, niente da accettare.</strong> Questo sito non usa moduli né strumenti di statistica e non carica risorse da altri siti.</p>
            <a href="{{ $u('privacy') }}">Privacy e note legali</a>
        </div>
    </div>
</footer>

</body>
</html>
