<?php

namespace App\Services\Marche;

use RuntimeException;

/** Una marca non si e' potuta apporre: il messaggio e' gia' scritto per chi legge la pagina. */
class MarcaTemporaleException extends RuntimeException {}
