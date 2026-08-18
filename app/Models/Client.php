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
        'public_slug', 'public_enabled', 'public_profile', 'label_prefix',
    ];

    protected function casts(): array
    {
        return [
            'address' => 'array',
            'contacts' => 'array',
            'public_profile' => 'array',
            'is_active' => 'boolean',
            'public_enabled' => 'boolean',
        ];
    }

    /** Indirizzo del portale e presenza dello stemma servono a ogni schermata di gestione. */
    protected $appends = ['portal_url', 'has_logo'];

    public function getPortalUrlAttribute(): ?string
    {
        return $this->portalUrl();
    }

    public function getHasLogoAttribute(): bool
    {
        return ! empty($this->public_profile['logo_path']);
    }

    public function contracts()
    {
        return $this->hasMany(Contract::class);
    }

    public function sites()
    {
        return $this->hasMany(Site::class);
    }

    /** Il portale è raggiungibile solo se acceso e con il committente attivo. */
    public function portalIsLive(): bool
    {
        return $this->public_enabled
            && $this->is_active
            && $this->public_slug !== null
            && $this->deleted_at === null;
    }

    /** Nome da mostrare in pubblico: quello scelto dal Comune, altrimenti la ragione sociale. */
    public function publicName(): string
    {
        $scelto = trim((string) ($this->public_profile['display_name'] ?? ''));

        return $scelto !== '' ? $scelto : $this->name;
    }

    /**
     * Indirizzo del portale: sottodominio quando il dominio di base è
     * configurato, altrimenti il percorso di ripiego usato in collaudo.
     */
    public function portalUrl(): ?string
    {
        if ($this->public_slug === null) {
            return null;
        }

        $base = config('portal.base_host');

        return $base
            ? 'https://'.$this->public_slug.'.'.$base
            : url('/comune/'.$this->public_slug);
    }
}
