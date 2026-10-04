<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\NelPerimetroZona;
use Illuminate\Database\Eloquent\Model;

class PlantingSite extends Model
{
    /** Come il modello si lega al committente per il perimetro di zona (ZonaScope). */
    public const PERIMETRO_ZONA = 'asset_id';

    use BelongsToTenant, NelPerimetroZona;

    protected $primaryKey = 'asset_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'asset_id', 'tenant_id', 'status', 'planned_species', 'origin',
        'previous_tree_id', 'target_season', 'notes',
    ];

    public function asset()
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }
}
