<?php

namespace App\Services;

use App\Jobs\RunScheduledMonitor;
use App\Models\ModerationDomainBlock;
use App\Models\MonitorRun;
use App\Models\MonitorSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

class MonitorDispatcher
{
    /** @return array{examined: int, dispatched: int, skipped: int, missed: int} */
    public function dispatch(CarbonImmutable $now, bool $backfill = false): array
    {
        $counts = ['examined' => 0, 'dispatched' => 0, 'skipped' => 0, 'missed' => 0];
        if (! (bool) config('website_intelligence.monitoring.enabled', true)) {
            return $counts;
        }
        $globalLimit = max(1, (int) config('website_intelligence.monitoring.max_dispatch_per_tick', 20));
        $backfillLimit = max(1, (int) config('website_intelligence.monitoring.max_backfill_runs', 3));
        $schedules = MonitorSchedule::query()->where('state', 'active')->whereNull('deleted_at')->where('next_run_at', '<=', $now)->orderBy('next_run_at')->limit($globalLimit)->get();
        foreach ($schedules as $schedule) {
            $counts['examined']++;
            $schedule->load('verification', 'owner');
            $monthRuns = $schedule->runs()->where('scheduled_for', '>=', $now->startOfMonth())->count();
            if (! $schedule->verification->current() || ! $schedule->owner->hasVerifiedEmail() || $schedule->owner->intelligence_suspended_at !== null
                || ModerationDomainBlock::query()->where('domain_hash', $schedule->verification->domain_hash)->whereNull('released_at')->exists()
                || $monthRuns >= $schedule->monthly_run_budget) {
                $schedule->forceFill(['state' => 'paused', 'paused_at' => $now])->save();
                $counts['skipped']++;

                continue;
            }
            $slots = 0;
            while ($schedule->next_run_at->lte($now) && $slots < ($backfill ? $backfillLimit : 1) && $monthRuns + $slots < $schedule->monthly_run_budget) {
                $scheduledFor = $schedule->next_run_at;
                try {
                    $run = MonitorRun::create([
                        'monitor_schedule_id' => $schedule->id, 'public_id' => 'run_'.Str::ulid(),
                        'state' => 'queued', 'scheduled_for' => $scheduledFor,
                    ]);
                    RunScheduledMonitor::dispatch($run->id)->onQueue('intelligence');
                    $counts['dispatched']++;
                } catch (QueryException) {
                    $counts['skipped']++;
                }
                $schedule->next_run_at = $scheduledFor->addHours($schedule->frequency_hours);
                $slots++;
            }
            if (! $backfill && $schedule->next_run_at->lte($now)) {
                $missed = intdiv((int) $schedule->next_run_at->diffInHours($now), $schedule->frequency_hours) + 1;
                $schedule->next_run_at = $schedule->next_run_at->addHours($missed * $schedule->frequency_hours);
                $schedule->missed_runs += $missed;
                $counts['missed'] += $missed;
            }
            $schedule->save();
        }

        return $counts;
    }
}
