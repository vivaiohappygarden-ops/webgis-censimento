<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CustomField extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'object_type_id', 'key', 'label', 'field_type', 'required',
        'options', 'validation', 'unit', 'show_in_pwa', 'cam_field', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'required' => 'boolean',
            'options' => 'array',
            'validation' => 'array',
            'show_in_pwa' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function objectType()
    {
        return $this->belongsTo(CatalogObjectType::class, 'object_type_id');
    }
}
