<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Vincolo del territorio (paesaggistico, archeologico, storico).
 *
 * Anagrafica riusabile: si scrive una volta e si collega a molti elementi.
 * Con il perimetro caricato il collegamento si ricava per intersezione.
 */
class LandConstraint extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $table = 'land_constraints';

    protected $fillable = [
        'tenant_id', 'client_id', 'code', 'name', 'description',
        'authority', 'document_id', 'is_public', 'created_by',
    ];

    protected function casts(): array
    {
        return ['is_public' => 'boolean'];
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function assets()
    {
        return $this->belongsToMany(Asset::class, 'asset_constraints', 'constraint_id', 'asset_id');
    }

    /** Etichetta breve mostrata al cittadino: "art.28 PTPR". */
    public function etichetta(): string
    {
        return trim($this->code);
    }
}
