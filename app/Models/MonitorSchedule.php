<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MonitorSchedule extends Model
{
    protected $fillable = ['owner_id', 'domain_verification_id', 'public_id', 'state', 'frequency_hours', 'monthly_run_budget', 'crawl_policy', 'thresholds', 'notification_preferences', 'parser_version', 'model_version', 'next_run_at', 'last_run_at', 'missed_runs', 'paused_at', 'deleted_at'];

    protected function casts(): array
    {
        return ['crawl_policy' => 'array', 'thresholds' => 'array', 'notification_preferences' => 'array', 'next_run_at' => 'immutable_datetime', 'last_run_at' => 'immutable_datetime', 'paused_at' => 'immutable_datetime', 'deleted_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<DomainVerification, $this> */
    public function verification(): BelongsTo
    {
        return $this->belongsTo(DomainVerification::class, 'domain_verification_id');
    }

    /** @return HasMany<MonitorRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(MonitorRun::class);
    }

    /** @return HasMany<MonitorVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(MonitorVersion::class);
    }

    /** @return HasMany<MonitorComparison, $this> */
    public function comparisons(): HasMany
    {
        return $this->hasMany(MonitorComparison::class);
    }

    /** @param Builder<$this> $query */
    public function scopeOwnedBy(Builder $query, User $owner): void
    {
        $query->where('owner_id', $owner->id);
    }
}
