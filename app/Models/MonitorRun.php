<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitorRun extends Model
{
    protected $fillable = ['monitor_schedule_id', 'crawl_job_id', 'public_id', 'state', 'scheduled_for', 'started_at', 'completed_at', 'cancel_requested_at', 'safe_failure_category', 'notification_failed_at'];

    protected function casts(): array
    {
        return ['scheduled_for' => 'immutable_datetime', 'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'cancel_requested_at' => 'immutable_datetime', 'notification_failed_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<MonitorSchedule, $this> */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(MonitorSchedule::class, 'monitor_schedule_id');
    }

    /** @return BelongsTo<CrawlJob, $this> */
    public function crawlJob(): BelongsTo
    {
        return $this->belongsTo(CrawlJob::class);
    }
}
