<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'asset_id', 'subject_type', 'subject_id', 'doc_type', 'title',
        's3_key', 'mime_type', 'size_bytes', 'hash_sha256', 'acl', 'uploaded_by',
    ];

    protected $hidden = ['s3_key'];

    protected $appends = ['url'];

    public function getUrlAttribute(): string
    {
        return route('v1.documents.file', $this->id);
    }

    protected function casts(): array
    {
        return [
            'acl' => 'array',
            'size_bytes' => 'integer',
        ];
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }
}
