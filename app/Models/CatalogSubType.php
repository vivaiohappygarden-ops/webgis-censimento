<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CatalogSubType extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = ['tenant_id', 'main_type_id', 'code', 'name', 'is_cam', 'sort_order'];

    protected function casts(): array
    {
        return ['is_cam' => 'boolean', 'sort_order' => 'integer'];
    }

    public function mainType()
    {
        return $this->belongsTo(CatalogMainType::class, 'main_type_id');
    }

    public function objectTypes()
    {
        return $this->hasMany(CatalogObjectType::class, 'sub_type_id');
    }
}
