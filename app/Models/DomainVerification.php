<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DomainVerification extends Model
{
    protected $fillable = ['owner_id', 'public_id', 'domain', 'domain_hash', 'method', 'challenge_token', 'challenge_token_hash', 'challenge_expires_at', 'verified_at', 'verified_until', 'revoked_at'];

    protected $hidden = ['domain', 'domain_hash', 'challenge_token', 'challenge_token_hash'];

    protected function casts(): array
    {
        return ['domain' => 'encrypted', 'challenge_token' => 'encrypted', 'challenge_expires_at' => 'immutable_datetime', 'verified_at' => 'immutable_datetime', 'verified_until' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }

    public function current(): bool
    {
        return $this->verified_at !== null && $this->revoked_at === null && $this->verified_until?->isFuture();
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return HasMany<CrawlJob, $this> */
    public function crawlJobs(): HasMany
    {
        return $this->hasMany(CrawlJob::class);
    }

    /** @return HasMany<SiteImport, $this> */
    public function siteImports(): HasMany
    {
        return $this->hasMany(SiteImport::class);
    }

    /** @return HasMany<MonitorSchedule, $this> */
    public function monitorSchedules(): HasMany
    {
        return $this->hasMany(MonitorSchedule::class);
    }

    /** @param Builder<$this> $query */
    public function scopeOwnedBy(Builder $query, User $owner): void
    {
        $query->where('owner_id', $owner->id);
    }
}
