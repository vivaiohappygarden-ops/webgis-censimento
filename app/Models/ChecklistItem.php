<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ChecklistItem extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'template_id', 'sort_order', 'question', 'answer_type',
        'options', 'ko_creates_nc', 'photo_required_on_ko',
        'attachment_required', 'weight', 'visibility_condition',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'options' => 'array',
            'ko_creates_nc' => 'boolean',
            'photo_required_on_ko' => 'boolean',
            'attachment_required' => 'boolean',
            'weight' => 'integer',
            'visibility_condition' => 'array',
        ];
    }

    public function template()
    {
        return $this->belongsTo(InspectionTemplate::class, 'template_id');
    }
}
