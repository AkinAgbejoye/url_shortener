<?php

namespace App\Services;

use App\Models\DomainVerification;
use App\Models\ModerationDomainBlock;
use App\Models\MonitorSchedule;
use App\Models\User;
use Illuminate\Support\Str;
use RuntimeException;

class MonitorScheduleService
{
    /** @param array<string, mixed> $preferences */
    public function create(User $owner, DomainVerification $verification, int $frequencyHours, int $monthlyBudget, int $minimumChangedCategories, array $preferences): MonitorSchedule
    {
        $minimumFrequency = max(1, (int) config('website_intelligence.monitoring.minimum_frequency_hours', 24));
        $maxBudget = max(1, (int) config('website_intelligence.monitoring.max_monthly_runs', 31));
        if (! (bool) config('website_intelligence.monitoring.enabled', true) || $verification->owner_id !== $owner->id
            || ! $owner->hasVerifiedEmail() || ! $verification->current() || $owner->intelligence_suspended_at !== null
            || ModerationDomainBlock::query()->where('domain_hash', $verification->domain_hash)->whereNull('released_at')->exists()
            || MonitorSchedule::query()->where('owner_id', $owner->id)->whereNull('deleted_at')->whereHas('verification', fn ($query) => $query->where('domain_hash', $verification->domain_hash))->exists()
            || $frequencyHours < $minimumFrequency || $frequencyHours > 720 || $monthlyBudget < 1 || $monthlyBudget > $maxBudget
            || MonitorSchedule::query()->ownedBy($owner)->whereNull('deleted_at')->count() >= max(1, (int) config('website_intelligence.monitoring.max_schedules_per_account', 5))) {
            throw new RuntimeException('schedule_not_eligible');
        }

        return MonitorSchedule::create([
            'owner_id' => $owner->id, 'domain_verification_id' => $verification->id,
            'public_id' => 'monitor_'.Str::ulid(), 'state' => 'active',
            'frequency_hours' => $frequencyHours, 'monthly_run_budget' => $monthlyBudget,
            'crawl_policy' => [
                'include_paths' => ['/'], 'exclude_paths' => ['/login', '/checkout', '/account', '/admin'],
                'max_pages' => min(10, max(1, (int) config('website_intelligence.monitoring.max_pages_per_run', 5))),
                'max_depth' => 1, 'max_bytes' => min(5000000, max(1024, (int) config('website_intelligence.monitoring.max_bytes_per_run', 2000000))),
            ],
            'thresholds' => ['minimum_changed_categories' => min(6, max(1, $minimumChangedCategories))],
            'notification_preferences' => ['email_on_material_change' => (bool) ($preferences['email_on_material_change'] ?? false)],
            'parser_version' => (string) config('website_intelligence.monitoring.parser_version', 'monitor-parser-v1'),
            'model_version' => (string) config('website_intelligence.monitoring.model_version', 'rules-v1'),
            'next_run_at' => now(),
        ]);
    }
}
