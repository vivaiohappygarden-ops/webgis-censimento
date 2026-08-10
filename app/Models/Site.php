<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Site extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'client_id', 'code', 'name', 'istat_code',
        'municipality', 'province', 'region', 'geom',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function localities()
    {
        return $this->hasMany(Locality::class);
    }
}
