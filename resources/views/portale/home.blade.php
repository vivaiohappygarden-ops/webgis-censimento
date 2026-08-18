@extends('portale.layout')

@section('titolo', 'Censimento del verde')

@push('stile')
<style>
    h1 { font-size: 24px; margin: 8px 0 4px; }
    p.benvenuto { max-width: 62ch; color: var(--tenue); margin: 0 0 24px; white-space: pre-line; }
    .numeri { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 24px; }
    .numero {
        background: var(--carta);
        border: 1px solid var(--bordo);
        border-radius: 10px;
        padding: 14px 16px;
    }
    .numero .valore { font-size: 26px; font-weight: 600; line-height: 1.1; }
    .numero .voce { font-size: 12px; color: var(--tenue); text-transform: uppercase; letter-spacing: 0.06em; margin-top: 4px; }
    .avviso {
        background: #fff7ed;
        border: 1px solid #fed7aa;
        color: #7c2d12;
        border-radius: 10px;
        padding: 12px 16px;
        margin-bottom: 20px;
        font-size: 14px;
    }
    .nota { color: var(--tenue); font-size: 13px; }
    .bottone {
        display: inline-block; background: var(--tinta); color: #fff; text-decoration: none;
        border-radius: 10px; padding: 10px 18px; font-size: 15px; font-weight: 600;
    }
</style>
@endpush

@section('contenuto')
    <h1>Il censimento del patrimonio arboreo</h1>

    @if ($portale->welcomeText())
        <p class="benvenuto">{{ $portale->welcomeText() }}</p>
    @else
        <p class="benvenuto">Questa pagina raccoglie il censimento del patrimonio arboreo del territorio: ogni albero ha una scheda con la specie, le misure e gli interventi eseguiti.</p>
    @endif

    @if ($nonTrovato)
        <p class="avviso">
            Nessun elemento trovato con l'etichetta &laquo;{{ $cercato }}&raquo;.
            Controlla il numero riportato sul cartellino: si scrive anche solo con le cifre.
        </p>
    @endif

    <div class="numeri">
        <div class="numero">
            <div class="valore">{{ number_format($statistiche['elementi'], 0, ',', '.') }}</div>
            <div class="voce">Elementi censiti</div>
        </div>
        <div class="numero">
            <div class="valore">{{ number_format($statistiche['alberi'], 0, ',', '.') }}</div>
            <div class="voce">Alberi</div>
        </div>
        <div class="numero">
            <div class="valore">{{ number_format($statistiche['varieta'], 0, ',', '.') }}</div>
            <div class="voce">Varietà botaniche</div>
        </div>
        <div class="numero">
            <div class="valore">{{ number_format($statistiche['curati'], 0, ',', '.') }}</div>
            <div class="voce">Alberi curati</div>
        </div>
        <div class="numero">
            <div class="valore">{{ number_format($statistiche['potati'], 0, ',', '.') }}</div>
            <div class="voce">Alberi potati</div>
        </div>
    </div>

    <p><a class="bottone" href="{{ $portale->url('/mappa') }}">Visualizza la mappa</a></p>

    <p class="nota">
        Per aprire la scheda di un albero digita il numero riportato sul cartellino nel campo di ricerca in alto.
    </p>
@endsection
