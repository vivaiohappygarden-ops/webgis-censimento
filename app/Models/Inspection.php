<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Inspection extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    public const OUTCOMES = ['passed', 'passed_with_remarks', 'failed', 'not_completed'];

    protected $fillable = [
        'tenant_id', 'template_id', 'template_version', 'asset_id', 'area_id',
        'inspector_id', 'started_at', 'completed_at', 'geom', 'answers', 'outcome',
    ];

    protected function casts(): array
    {
        return [
            'template_version' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'answers' => 'array',
            'version' => 'integer',
        ];
    }

    public function template()
    {
        return $this->belongsTo(InspectionTemplate::class, 'template_id');
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function area()
    {
        return $this->belongsTo(Area::class);
    }

    public function inspector()
    {
        return $this->belongsTo(User::class, 'inspector_id');
    }
}
