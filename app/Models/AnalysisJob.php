<?php

namespace App\Models;

use App\Enums\AnalysisState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AnalysisJob extends Model
{
    protected $fillable = [
        'owner_id', 'public_id', 'target_url', 'submitted_origin', 'origin_hash', 'domain_hash',
        'state', 'safe_failure_category', 'idempotency_key_hash', 'intent_hash', 'extraction',
        'provenance', 'user_context', 'cancel_requested_at', 'started_at', 'completed_at', 'expires_at',
    ];

    protected $hidden = ['target_url', 'origin_hash', 'domain_hash', 'idempotency_key_hash', 'intent_hash'];

    protected function casts(): array
    {
        return [
            'target_url' => 'encrypted',
            'state' => AnalysisState::class,
            'extraction' => 'array',
            'provenance' => 'array',
            'user_context' => 'encrypted:array',
            'cancel_requested_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return HasMany<AnalysisPageFetch, $this> */
    public function pageFetches(): HasMany
    {
        return $this->hasMany(AnalysisPageFetch::class);
    }

    /** @return HasMany<AnalysisObservation, $this> */
    public function observations(): HasMany
    {
        return $this->hasMany(AnalysisObservation::class);
    }

    /** @return HasOne<AnalysisReport, $this> */
    public function report(): HasOne
    {
        return $this->hasOne(AnalysisReport::class);
    }

    /** @param Builder<$this> $query */
    public function scopeOwnedBy(Builder $query, User $owner): void
    {
        $query->where('owner_id', $owner->getKey());
    }
}
