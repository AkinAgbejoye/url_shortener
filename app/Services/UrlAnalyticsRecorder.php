<?php

namespace App\Services;

use App\Support\OperationalMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class UrlAnalyticsRecorder
{
    public function __construct(private readonly OperationalMetrics $metrics) {}

    public function recordSuccessfulRedirect(?int $urlId): void
    {
        if ($urlId === null) {
            return;
        }

        $bucketDate = CarbonImmutable::now('UTC')->toDateString();

        try {
            $inserted = DB::table('url_analytics_daily')->insertOrIgnore([
                'url_id' => $urlId,
                'date' => $bucketDate,
                'redirect_count' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted === 0) {
                DB::table('url_analytics_daily')
                    ->where('url_id', $urlId)
                    ->where('date', $bucketDate)
                    ->increment('redirect_count', 1, ['updated_at' => now()]);
            }

            $this->metrics->analytics('recorded');
            Log::info('analytics_recorded', [
                'outcome' => 'recorded',
                'bucket_date' => $bucketDate,
            ]);
        } catch (Throwable $exception) {
            $this->metrics->analytics('failed');
            Log::warning('analytics_record_failed', [
                'outcome' => 'failed',
                'bucket_date' => $bucketDate,
                'exception_class' => $exception::class,
            ]);
        }
    }
}
