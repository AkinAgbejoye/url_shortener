<?php

namespace App\Console\Commands;

use App\Services\MonitorDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DispatchMonitors extends Command
{
    protected $signature = 'intelligence:dispatch-monitors {--backfill : Dispatch bounded missed intervals}';

    protected $description = 'Dispatch due verified-domain monitoring schedules';

    public function handle(MonitorDispatcher $dispatcher): int
    {
        $counts = $dispatcher->dispatch(CarbonImmutable::now('UTC'), (bool) $this->option('backfill'));
        Log::info('monitor_dispatch_completed', ['backfill' => (bool) $this->option('backfill'), ...$counts]);
        $this->table(['Examined', 'Dispatched', 'Skipped', 'Missed'], [array_values($counts)]);

        return self::SUCCESS;
    }
}
