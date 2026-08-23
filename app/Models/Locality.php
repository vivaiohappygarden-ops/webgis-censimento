<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Locality extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'site_id', 'code', 'name', 'survey_zone_code', 'geom', 'istat_class',
    ];

    public function site()
    {
        return $this->belongsTo(Site::class);
    }

    public function areas()
    {
        return $this->hasMany(Area::class);
    }

    /** Documenti allegati alla localita', per esempio il piano di gestione. */
    public function documents()
    {
        return $this->morphMany(Document::class, 'subject');
    }
}
