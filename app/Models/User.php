<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'intelligence_suspended_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'intelligence_suspended_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<Url, $this> */
    public function urls(): HasMany
    {
        return $this->hasMany(Url::class, 'owner_id');
    }

    /** @return HasMany<ApiKey, $this> */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    /** @return HasMany<AnalysisJob, $this> */
    public function analysisJobs(): HasMany
    {
        return $this->hasMany(AnalysisJob::class, 'owner_id');
    }

    /** @return HasMany<DomainVerification, $this> */
    public function domainVerifications(): HasMany
    {
        return $this->hasMany(DomainVerification::class, 'owner_id');
    }

    /** @return HasMany<CrawlJob, $this> */
    public function crawlJobs(): HasMany
    {
        return $this->hasMany(CrawlJob::class, 'owner_id');
    }

    /** @return HasMany<AnalysisBatch, $this> */
    public function analysisBatches(): HasMany
    {
        return $this->hasMany(AnalysisBatch::class, 'owner_id');
    }
}
