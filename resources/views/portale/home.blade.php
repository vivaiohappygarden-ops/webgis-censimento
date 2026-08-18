@extends('portale.layout')

@section('titolo', 'Censimento del verde')

@push('stile')
<style>
    h1 { font-size: 24px; margin: 8px 0 4px; }
    p.benvenuto { max-width: 62ch; color: var(--tenue); margin: 0 0 24px; white-space: pre-line; }
    .riquadro {
        background: var(--carta);
        border: 1px solid var(--bordo);
        border-radius: 10px;
        padding: 18px 20px;
    }
    .prossimo { color: var(--tenue); font-size: 14px; }
</style>
@endpush

@section('contenuto')
    <h1>Il censimento del patrimonio arboreo</h1>

    @if ($portale->welcomeText())
        <p class="benvenuto">{{ $portale->welcomeText() }}</p>
    @else
        <p class="benvenuto">Questa pagina raccoglie il censimento del patrimonio arboreo del territorio: ogni albero ha una scheda con la specie, le misure e gli interventi eseguiti.</p>
    @endif

    <div class="riquadro">
        <p class="prossimo">La ricerca per numero di etichetta, le statistiche e la mappa pubblica sono in preparazione.</p>
    </div>
@endsection
