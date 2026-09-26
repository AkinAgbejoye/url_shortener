<?php

namespace App\Providers;

use App\Contracts\MetricsExporter;
use App\Metrics\NullMetricsExporter;
use App\Metrics\SafeMetricsExporter;
use App\Metrics\StatsdMetricsExporter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(MetricsExporter::class, function (Application $app): MetricsExporter {
            $config = $app->make('config');
            $exporter = $config->get('metrics.driver') === 'statsd'
                ? new StatsdMetricsExporter(
                    host: (string) $config->get('metrics.statsd.host'),
                    port: (int) $config->get('metrics.statsd.port'),
                    prefix: (string) $config->get('metrics.prefix'),
                    timeout: (float) $config->get('metrics.statsd.timeout'),
                )
                : new NullMetricsExporter;

            return new SafeMetricsExporter($exporter);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
