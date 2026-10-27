<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AnalysisPageFetch extends Model
{
    protected $fillable = [
        'analysis_job_id', 'public_id', 'source_url', 'status_code', 'content_type', 'content_hash',
        'compressed_bytes', 'decompressed_bytes', 'fetched_at',
    ];

    protected $hidden = ['source_url'];

    protected function casts(): array
    {
        return ['source_url' => 'encrypted', 'fetched_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<AnalysisJob, $this> */
    public function analysisJob(): BelongsTo
    {
        return $this->belongsTo(AnalysisJob::class);
    }

    /** @return HasMany<AnalysisObservation, $this> */
    public function observations(): HasMany
    {
        return $this->hasMany(AnalysisObservation::class, 'page_fetch_id');
    }
}
