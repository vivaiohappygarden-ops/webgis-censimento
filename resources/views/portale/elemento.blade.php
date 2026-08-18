@extends('portale.layout')

@section('titolo', $asset->census_code ?: 'Scheda elemento')

@section('contenuto')
    <a class="indietro" href="{{ $portale->url('/') }}">&larr; Torna alla home</a>

    @include('portale.scheda')
@endsection
