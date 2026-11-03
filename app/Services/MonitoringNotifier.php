<?php

namespace App\Services;

use App\Models\MonitorComparison;
use App\Models\MonitorRun;
use App\Models\MonitorSchedule;
use App\Notifications\MaterialMonitorChange;
use Throwable;

class MonitoringNotifier
{
    public function notify(MonitorSchedule $schedule, MonitorRun $run, MonitorComparison $comparison): void
    {
        if (! $comparison->material || ! ($schedule->notification_preferences['email_on_material_change'] ?? false)) {
            return;
        }
        try {
            $categories = array_values(array_map(fn (array $change): string => $change['category'], $comparison->source_changes));
            $schedule->owner->notify(new MaterialMonitorChange(
                $schedule->public_id,
                $comparison->previousVersion->public_id,
                $comparison->currentVersion->public_id,
                $categories,
            ));
            $comparison->forceFill(['notified_at' => now()])->save();
        } catch (Throwable) {
            $run->forceFill(['notification_failed_at' => now()])->save();
        }
    }
}
