<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'vat_number', 'metric_srid', 'settings', 'branding', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'branding' => 'array',
            'is_active' => 'boolean',
            'metric_srid' => 'integer',
        ];
    }

    public function users()
    {
        return $this->hasMany(User::class, 'tenant_id');
    }
}
