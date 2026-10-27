<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalysisObservation extends Model
{
    protected $fillable = [
        'analysis_job_id', 'page_fetch_id', 'public_id', 'evidence_public_id', 'kind',
        'source_location', 'content_hash', 'statement', 'excerpt', 'observed_at',
    ];

    protected function casts(): array
    {
        return ['observed_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<AnalysisJob, $this> */
    public function analysisJob(): BelongsTo
    {
        return $this->belongsTo(AnalysisJob::class);
    }

    /** @return BelongsTo<AnalysisPageFetch, $this> */
    public function pageFetch(): BelongsTo
    {
        return $this->belongsTo(AnalysisPageFetch::class, 'page_fetch_id');
    }
}
