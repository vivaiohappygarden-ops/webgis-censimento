@extends('portale.layout')

@section('titolo', $asset->census_code ?: 'Scheda elemento')

@section('contenuto')
    <a class="indietro" href="{{ $portale->url('/') }}">&larr; Torna alla home</a>

    @include('portale.scheda')

    <p class="prossimo">
        Cronologia degli interventi, vincoli e stima dell'anidride carbonica assorbita sono in preparazione.
    </p>
@endsection
