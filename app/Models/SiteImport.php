<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SiteImport extends Model
{
    protected $fillable = [
        'owner_id', 'domain_verification_id', 'public_id', 'state', 'rights_attestation', 'declared_licenses',
        'rights_attested_at', 'rights_expires_at', 'policy', 'sanitized_html', 'editable_representation',
        'sanitation_summary', 'provenance', 'safe_failure_category', 'cancel_requested_at', 'confirmed_at',
        'takedown_at', 'completed_at', 'expires_at', 'deleted_at',
    ];

    protected $hidden = ['rights_attestation', 'sanitized_html', 'editable_representation'];

    protected function casts(): array
    {
        return [
            'rights_attestation' => 'encrypted', 'declared_licenses' => 'encrypted:array', 'policy' => 'array',
            'sanitized_html' => 'encrypted', 'editable_representation' => 'encrypted:array',
            'sanitation_summary' => 'array', 'provenance' => 'array', 'rights_attested_at' => 'immutable_datetime',
            'rights_expires_at' => 'immutable_datetime', 'cancel_requested_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime', 'takedown_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'deleted_at' => 'immutable_datetime',
        ];
    }

    public function authorized(): bool
    {
        return $this->deleted_at === null && $this->takedown_at === null && $this->rights_expires_at->isFuture() && $this->verification->current();
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

    /** @return HasMany<SiteImportAsset, $this> */
    public function assets(): HasMany
    {
        return $this->hasMany(SiteImportAsset::class);
    }

    /** @param Builder<$this> $query */
    public function scopeOwnedBy(Builder $query, User $owner): void
    {
        $query->where('owner_id', $owner->id);
    }
}
