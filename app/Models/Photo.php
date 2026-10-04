<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\NelPerimetroZona;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Photo extends Model
{
    /** Come il modello si lega al committente per il perimetro di zona (ZonaScope). */
    public const PERIMETRO_ZONA = 'asset_id';

    use BelongsToTenant, NelPerimetroZona, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'asset_id', 'subject_type', 'subject_id', 'category',
        's3_key', 'thumb_s3_key', 'original_filename', 'mime_type', 'size_bytes',
        'hash_sha256', 'taken_at', 'exif', 'geom', 'taken_by',
    ];

    protected $hidden = ['geom', 's3_key', 'thumb_s3_key'];

    protected $appends = ['url'];

    public function getUrlAttribute(): string
    {
        return route('v1.photos.file', $this->id);
    }

    protected function casts(): array
    {
        return [
            'exif' => 'array',
            'taken_at' => 'datetime',
            'size_bytes' => 'integer',
        ];
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }
}
