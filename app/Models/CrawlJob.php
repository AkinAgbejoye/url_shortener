<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrawlJob extends Model
{
    protected $fillable = ['owner_id', 'domain_verification_id', 'public_id', 'state', 'policy', 'robots_policy', 'summary', 'cancel_requested_at', 'started_at', 'completed_at', 'expires_at'];

    protected function casts(): array
    {
        return ['policy' => 'array', 'robots_policy' => 'array', 'summary' => 'array', 'cancel_requested_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<DomainVerification, $this> */
    public function verification(): BelongsTo
    {
        return $this->belongsTo(DomainVerification::class, 'domain_verification_id');
    }

    /** @return HasMany<CrawlPage, $this> */
    public function pages(): HasMany
    {
        return $this->hasMany(CrawlPage::class);
    }

    /** @param Builder<$this> $query */
    public function scopeOwnedBy(Builder $query, User $owner): void
    {
        $query->where('owner_id', $owner->id);
    }
}
