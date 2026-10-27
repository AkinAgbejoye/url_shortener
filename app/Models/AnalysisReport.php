<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalysisReport extends Model
{
    protected $fillable = [
        'analysis_job_id', 'public_id', 'schema_version', 'generated_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return ['generated_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<AnalysisJob, $this> */
    public function analysisJob(): BelongsTo
    {
        return $this->belongsTo(AnalysisJob::class);
    }
}
