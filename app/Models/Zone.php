<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Una zona dell'organizzazione: un gruppo di committenti (e quindi di sedi,
 * localita', aree, elementi e lavori) che un utente di zona vede e tocca.
 * Il perimetro lo applica App\Support\PerimetroZone, qui stanno solo i dati.
 */
class Zone extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'name', 'code', 'notes', 'created_by', 'updated_by'];

    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class, 'zone_client');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'zone_user');
    }
}
