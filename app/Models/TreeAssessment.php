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

    /** Parti dell'albero esaminate nell'analisi visiva, nell'ordine della scheda. */
    public const BODY_PARTS = ['rilevamenti', 'radici', 'colletto', 'fusto', 'castello', 'branche', 'chioma'];

    protected $fillable = [
        'tenant_id', 'tree_id', 'assessment_type', 'assessed_on', 'assessor_id',
        'assessor_external', 'defects', 'targets', 'failure_class', 'outcome',
        'prescriptions', 'next_check_due', 'survey', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'assessed_on' => 'date',
            'next_check_due' => 'date',
            'report_issued_at' => 'datetime',
            'defects' => 'array',
            'targets' => 'array',
            'survey' => 'array',
            'version' => 'integer',
        ];
    }

    /**
     * Protocollo della perizia, assegnato alla prima stampa e mai più
     * cambiato: due copie della stessa perizia devono avere lo stesso
     * numero e la stessa data di emissione.
     */
    public static function nextReportNumber(string $tenantId): string
    {
        \Illuminate\Support\Facades\DB::statement(
            'SELECT pg_advisory_xact_lock(hashtextextended(?, 42))', ["perizia:{$tenantId}"],
        );
        $year = now('Europe/Rome')->year;
        $daRighe = (int) \Illuminate\Support\Facades\DB::selectOne(
            "SELECT COALESCE(MAX((substring(report_number FROM '\\d+$'))::int), 0) AS n
             FROM tree_assessments WHERE tenant_id = ? AND report_number LIKE ?",
            [$tenantId, "PER-{$year}-%"],
        )->n;

        // Contatore che non torna mai indietro: se una perizia viene corretta
        // il suo numero viene liberato, ma non deve essere riassegnato a un
        // documento diverso da quello già consegnato al committente
        $organization = Organization::query()->lockForUpdate()->findOrFail($tenantId);
        $settings = $organization->settings ?? [];
        $ultimo = (int) ($settings['perizia_last_number'][(string) $year] ?? 0);

        $next = max($daRighe, $ultimo) + 1;
        $settings['perizia_last_number'][(string) $year] = $next;
        $organization->forceFill(['settings' => $settings])->save();

        return sprintf('PER-%d-%04d', $year, $next);
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
