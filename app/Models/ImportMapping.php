<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Una corrispondenza colonne->campi salvata con un nome: il fornitore che
 * consegna sempre lo stesso tracciato si mappa una volta sola.
 */
class ImportMapping extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'import_mappings';

    protected $fillable = [
        'tenant_id', 'name', 'mapping', 'default_object_type_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'mapping' => 'array',
        ];
    }
}
