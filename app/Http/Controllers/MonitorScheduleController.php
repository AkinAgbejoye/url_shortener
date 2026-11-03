<?php

namespace App\Http\Controllers;

use App\Jobs\RunScheduledMonitor;
use App\Models\DomainVerification;
use App\Models\MonitorRun;
use App\Models\MonitorSchedule;
use App\Models\User;
use App\Services\MonitorScheduleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

class MonitorScheduleController extends Controller
{
    public function index(Request $request): View
    {
        $owner = $this->owner($request);

        return view('monitor-schedules', [
            'verifications' => DomainVerification::query()->ownedBy($owner)->whereNull('revoked_at')->where('verified_until', '>', now())->latest()->get(),
            'schedules' => MonitorSchedule::query()->ownedBy($owner)->whereNull('deleted_at')->with(['verification', 'runs' => fn ($query) => $query->latest()->limit(10), 'comparisons' => fn ($query) => $query->latest()->limit(5)])->latest()->get(),
        ]);
    }

    public function store(Request $request, MonitorScheduleService $service): RedirectResponse
    {
        $input = $request->validate([
            'verification_id' => ['required', 'string', 'max:40'],
            'frequency_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'monthly_run_budget' => ['required', 'integer', 'min:1', 'max:100'],
            'minimum_changed_categories' => ['required', 'integer', 'min:1', 'max:6'],
            'email_on_material_change' => ['nullable', 'boolean'],
        ]);
        $verification = DomainVerification::query()->ownedBy($this->owner($request))->where('public_id', $input['verification_id'])->firstOrFail();
        try {
            $schedule = $service->create($this->owner($request), $verification, $input['frequency_hours'], $input['monthly_run_budget'], $input['minimum_changed_categories'], $input);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['schedule' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Monitoring schedule '.$schedule->public_id.' enabled.');
    }

    public function control(Request $request, string $scheduleId): RedirectResponse
    {
        $schedule = $this->schedule($request, $scheduleId);
        $action = $request->validate(['action' => ['required', Rule::in(['pause', 'resume', 'delete', 'preferences'])]])['action'];
        if ($action === 'pause' && $schedule->state === 'active') {
            $schedule->forceFill(['state' => 'paused', 'paused_at' => now()])->save();
            $this->cancelActiveRuns($schedule);
        }
        if ($action === 'resume' && $schedule->state === 'paused' && $schedule->verification->current() && (bool) config('website_intelligence.monitoring.enabled', true)) {
            $schedule->forceFill(['state' => 'active', 'paused_at' => null, 'next_run_at' => now()])->save();
        }
        if ($action === 'delete') {
            $this->cancelActiveRuns($schedule);
            $schedule->forceFill(['state' => 'deleted', 'deleted_at' => now()])->save();
        }
        if ($action === 'preferences') {
            $input = $request->validate([
                'minimum_changed_categories' => ['required', 'integer', 'min:1', 'max:6'],
                'email_on_material_change' => ['nullable', 'boolean'],
            ]);
            $schedule->forceFill([
                'thresholds' => ['minimum_changed_categories' => $input['minimum_changed_categories']],
                'notification_preferences' => ['email_on_material_change' => (bool) ($input['email_on_material_change'] ?? false)],
            ])->save();
        }

        return back()->with('status', 'Monitoring control updated.');
    }

    public function runControl(Request $request, string $runId): RedirectResponse
    {
        $run = MonitorRun::query()->whereHas('schedule', fn ($query) => $query->ownedBy($this->owner($request)))->where('public_id', $runId)->firstOrFail();
        $action = $request->validate(['action' => ['required', Rule::in(['cancel', 'retry'])]])['action'];
        if ($action === 'cancel' && in_array($run->state, ['queued', 'running'], true)) {
            $run->forceFill(['state' => 'cancelled', 'cancel_requested_at' => now(), 'completed_at' => now()])->save();
            $run->crawlJob?->forceFill(['state' => 'cancelled', 'cancel_requested_at' => now(), 'completed_at' => now()])->save();
        }
        if ($action === 'retry' && $run->state === 'failed' && $run->schedule->state === 'active' && $run->schedule->verification->current()) {
            $run->forceFill(['state' => 'queued', 'started_at' => null, 'completed_at' => null, 'cancel_requested_at' => null, 'safe_failure_category' => null])->save();
            RunScheduledMonitor::dispatch($run->id)->onQueue('intelligence');
        }

        return back()->with('status', 'Monitoring run control updated.');
    }

    private function schedule(Request $request, string $id): MonitorSchedule
    {
        return MonitorSchedule::query()->ownedBy($this->owner($request))->where('public_id', $id)->with('verification')->firstOrFail();
    }

    private function cancelActiveRuns(MonitorSchedule $schedule): void
    {
        $runs = $schedule->runs()->whereIn('state', ['queued', 'running'])->with('crawlJob')->get();
        foreach ($runs as $run) {
            $run->forceFill(['state' => 'cancelled', 'cancel_requested_at' => now(), 'completed_at' => now()])->save();
            $run->crawlJob?->forceFill(['state' => 'cancelled', 'cancel_requested_at' => now(), 'completed_at' => now()])->save();
        }
    }

    private function owner(Request $request): User
    {
        /** @var User $owner */
        $owner = $request->user();

        return $owner;
    }
}
