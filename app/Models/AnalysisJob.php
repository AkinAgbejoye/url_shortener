<?php

namespace App\Models;

use App\Enums\AnalysisState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalysisJob extends Model
{
    protected $fillable = [
        'owner_id', 'public_id', 'target_url', 'submitted_origin', 'origin_hash', 'domain_hash',
        'state', 'safe_failure_category', 'idempotency_key_hash', 'intent_hash', 'extraction',
        'provenance', 'cancel_requested_at', 'started_at', 'completed_at', 'expires_at',
    ];

    protected $hidden = ['target_url', 'origin_hash', 'domain_hash', 'idempotency_key_hash', 'intent_hash'];

    protected function casts(): array
    {
        return [
            'target_url' => 'encrypted',
            'state' => AnalysisState::class,
            'extraction' => 'array',
            'provenance' => 'array',
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

    /** @param Builder<$this> $query */
    public function scopeOwnedBy(Builder $query, User $owner): void
    {
        $query->where('owner_id', $owner->getKey());
    }
}
