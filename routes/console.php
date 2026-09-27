<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(sprintf(
    'urls:prune-lifecycle --batch-size=%d --retention-days=%d',
    config('url_shortener.cleanup.batch_size'),
    config('url_shortener.cleanup.retention_days'),
))
    ->dailyAt(config('url_shortener.cleanup.time'))
    ->withoutOverlapping(60)
    ->onOneServer();
