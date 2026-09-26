<?php

namespace App\Models;

use App\Enums\UrlLifecycleState;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Url extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'long_url',
        'short_code',
        'expires_at',
        'disabled_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'disabled_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    public function lifecycleState(?DateTimeInterface $at = null): UrlLifecycleState
    {
        if ($this->trashed()) {
            return UrlLifecycleState::Deleted;
        }

        if ($this->isDisabled()) {
            return UrlLifecycleState::Disabled;
        }

        if ($this->isExpired($at)) {
            return UrlLifecycleState::Expired;
        }

        return UrlLifecycleState::Active;
    }

    public function isActive(?DateTimeInterface $at = null): bool
    {
        return $this->lifecycleState($at) === UrlLifecycleState::Active;
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    public function isExpired(?DateTimeInterface $at = null): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        return $this->expires_at->lessThanOrEqualTo($this->asImmutable($at));
    }

    /** @param Builder<Url> $query */
    public function scopeActive(Builder $query, ?DateTimeInterface $at = null): Builder
    {
        $comparisonTime = $this->asImmutable($at);

        return $query
            ->whereNull('disabled_at')
            ->where(function (Builder $query) use ($comparisonTime): void {
                $query
                    ->whereNull('expires_at')
                    ->orWhere('expires_at', '>', $comparisonTime);
            });
    }

    private function asImmutable(?DateTimeInterface $at): CarbonImmutable
    {
        return $at === null
            ? CarbonImmutable::now()
            : CarbonImmutable::instance($at);
    }
}
