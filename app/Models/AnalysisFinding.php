<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalysisFinding extends Model
{
    protected $fillable = ['analysis_report_id', 'public_id', 'epistemic_type', 'category', 'statement', 'confidence', 'evidence_ids', 'details'];

    protected function casts(): array
    {
        return ['evidence_ids' => 'array', 'details' => 'array'];
    }

    /** @return BelongsTo<AnalysisReport, $this> */
    public function report(): BelongsTo
    {
        return $this->belongsTo(AnalysisReport::class, 'analysis_report_id');
    }
}
