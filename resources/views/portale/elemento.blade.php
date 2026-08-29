@extends('portale.layout')

@php
    // Il titolo della finestra è quello che il cittadino ritrova fra le schede
    // aperte del telefono: prima il nome, poi il numero del cartellino
    $nomeScheda = trim((string) ($asset->tree?->common_name ?? ''))
        ?: trim((string) ($asset->objectType?->name ?? ''))
        ?: 'Scheda elemento';
    $codiceScheda = trim((string) ($asset->census_code ?? ''));
    $riferimentoScheda = $codiceScheda !== '' ? $codiceScheda : $asset->id;
@endphp

@section('titolo', $codiceScheda !== '' ? $nomeScheda.' '.$codiceScheda : $nomeScheda)

@section('contenuto')
    {{-- Da qui non si resta chiusi: la mappa si riapre sull'elemento che si sta
         guardando, così si passa alla pianta vicina senza ricominciare --}}
    <nav class="ritorni" aria-label="Torna indietro">
        <a class="indietro" href="{{ $portale->url('/mappa') }}?elemento={{ rawurlencode($riferimentoScheda) }}">&larr; Vedi sulla mappa</a>
        <a class="indietro" href="{{ $portale->url('/') }}">Torna alla home</a>
    </nav>

    @include('portale.scheda', ['contesto' => 'pagina'])
@endsection

@push('stile')
<style>
    /* I due ritorni stanno in riga e vanno a capo sul telefono; la colonna è
       la stessa del foglio della scheda, che è centrato */
    .ritorni {
        display: flex;
        flex-wrap: wrap;
        gap: 0 var(--s-4);
        max-width: 760px;
        margin: 0 auto;
    }
</style>
@endpush
