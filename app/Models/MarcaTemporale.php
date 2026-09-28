<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marca temporale apposta a un documento chiuso: il PDF conservato, la sua
 * impronta e il gettone della TSA con l'istante certificato.
 */
class MarcaTemporale extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'marche_temporali';

    protected $fillable = [
        'id', 'tipo', 'soggetto_id', 'parametri', 'titolo', 'nome_file', 'sha256', 'dimensione',
        'percorso_pdf', 'percorso_marca', 'generato_il', 'seriale', 'tsa', 'policy', 'servizio',
        'account', 'richiesta_da',
    ];

    protected function casts(): array
    {
        return [
            'parametri' => 'array',
            'generato_il' => 'datetime',
            'dimensione' => 'integer',
        ];
    }

    public function richiedente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'richiesta_da');
    }
}
