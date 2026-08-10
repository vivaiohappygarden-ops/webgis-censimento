<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TreeAssessment extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    public const FAILURE_CLASSES = ['A', 'B', 'C', 'C/D', 'D'];

    /** Mesi di default al ricontrollo per classe di propensione al cedimento. */
    public const DEFAULT_RECHECK_MONTHS = ['A' => 60, 'B' => 36, 'C' => 24, 'C/D' => 12, 'D' => null];

    protected $fillable = [
        'tenant_id', 'tree_id', 'assessment_type', 'assessed_on', 'assessor_id',
        'assessor_external', 'defects', 'targets', 'failure_class', 'outcome',
        'prescriptions', 'next_check_due', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'assessed_on' => 'date',
            'next_check_due' => 'date',
            'defects' => 'array',
            'targets' => 'array',
            'version' => 'integer',
        ];
    }

    public function tree()
    {
        return $this->belongsTo(Tree::class, 'tree_id', 'asset_id');
    }

    public function assessor()
    {
        return $this->belongsTo(User::class, 'assessor_id');
    }

    public function instrumentalAnalyses()
    {
        return $this->hasMany(InstrumentalAnalysis::class, 'assessment_id');
    }
}
