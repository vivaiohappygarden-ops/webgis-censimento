{{-- La chiusura di ogni pagina: una sola azione, sempre la stessa. --}}
<section class="sezione chiusura" aria-labelledby="titolo-chiusura">
    <div class="contenitore">
        <div>
            <h2 id="titolo-chiusura">{{ $titolo }}</h2>
            <p class="guida">{{ $testo }}</p>
        </div>
        <div class="inviti" style="margin-top: 0;">
            <a class="invito" href="{{ $u('contatti') }}">Richiedi un sopralluogo</a>
        </div>
    </div>
</section>
