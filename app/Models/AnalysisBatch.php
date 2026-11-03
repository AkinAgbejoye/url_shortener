<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AnalysisBatch extends Model
{
    protected $fillable = ['owner_id', 'public_id', 'state', 'format', 'manifest_rows', 'total_rows', 'processed_rows', 'successful_rows', 'failed_rows', 'cancel_requested_at', 'completed_at', 'expires_at'];

    protected $hidden = ['manifest_rows'];

    protected function casts(): array
    {
        return ['manifest_rows' => 'encrypted:array', 'cancel_requested_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(AnalysisBatchRow::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @param Builder<$this> $query */
    public function scopeOwnedBy(Builder $query, User $owner): void
    {
        $query->where('owner_id', $owner->id);
    }
}
