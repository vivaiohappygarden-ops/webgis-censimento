<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class InstrumentalAnalysis extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'assessment_id', 'instrument_type', 'instrument_model',
        'measured_at', 'measurement_height_cm', 'measures', 'document_id', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'measured_at' => 'datetime',
            'measurement_height_cm' => 'integer',
            'measures' => 'array',
        ];
    }

    public function assessment()
    {
        return $this->belongsTo(TreeAssessment::class, 'assessment_id');
    }

    public function document()
    {
        return $this->belongsTo(Document::class, 'document_id');
    }
}
