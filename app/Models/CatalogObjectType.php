<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CatalogObjectType extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'sub_type_id', 'code', 'name', 'allowed_geometry', 'cam_layer',
        'is_cam', 'survey_mode', 'reference_photo_key', 'requires_tree_record',
        'is_planting_site', 'icon', 'style', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_cam' => 'boolean',
            'requires_tree_record' => 'boolean',
            'is_planting_site' => 'boolean',
            'style' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function subType()
    {
        return $this->belongsTo(CatalogSubType::class, 'sub_type_id');
    }

    public function customFields()
    {
        return $this->hasMany(CustomField::class, 'object_type_id');
    }

    /** Tipi Eloquent/PostGIS ammessi per la geometria di questo tipo oggetto. */
    public function allowedGeometryTypes(): array
    {
        return match ($this->allowed_geometry) {
            'P' => ['Point', 'MultiPoint'],
            'L' => ['LineString', 'MultiLineString'],
            'S' => ['Polygon', 'MultiPolygon'],
        };
    }
}
