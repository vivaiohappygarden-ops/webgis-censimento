<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Vista salvata: i filtri di un elenco memorizzati con un nome. */
class SavedFilter extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'saved_filters';

    protected $fillable = ['user_id', 'pagina', 'nome', 'filtri', 'predefinita', 'condivisa'];

    protected function casts(): array
    {
        return [
            'filtri' => 'array',
            'predefinita' => 'boolean',
            'condivisa' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
