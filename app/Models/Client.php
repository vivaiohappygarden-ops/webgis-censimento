<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'code', 'name', 'client_type', 'vat_number', 'fiscal_code',
        'sdi_code', 'pec', 'ipa_code', 'address', 'contacts', 'notes', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'address' => 'array',
            'contacts' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function contracts()
    {
        return $this->hasMany(Contract::class);
    }

    public function sites()
    {
        return $this->hasMany(Site::class);
    }
}
