<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalysisBatchRow extends Model
{
    protected $fillable = ['analysis_batch_id', 'row_number', 'status', 'safe_error', 'analysis_job_id'];

    /** @return BelongsTo<AnalysisJob, $this> */
    public function analysisJob(): BelongsTo
    {
        return $this->belongsTo(AnalysisJob::class);
    }
}
