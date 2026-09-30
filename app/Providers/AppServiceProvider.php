<?php

namespace App\Providers;

use App\Contracts\ExternalExceptionReporter;
use App\Contracts\MetricsExporter;
use App\Metrics\NullMetricsExporter;
use App\Metrics\SafeMetricsExporter;
use App\Metrics\StatsdMetricsExporter;
use App\Reporting\NullExternalExceptionReporter;
use App\Reporting\SentryExceptionReporter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Sentry\State\HubInterface;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ExternalExceptionReporter::class, function (Application $app): ExternalExceptionReporter {
            $dsn = $app->make('config')->get('sentry.dsn');

            return is_string($dsn) && trim($dsn) !== ''
                ? new SentryExceptionReporter($app->make(HubInterface::class))
                : new NullExternalExceptionReporter;
        });

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
        RateLimiter::for('login', fn (Request $request): Limit => Limit::perMinute(5)
            ->by($this->authRateLimitKey($request)));

        RateLimiter::for('register', fn (Request $request): Limit => Limit::perMinute(3)
            ->by($this->authRateLimitKey($request)));
    }

    private function authRateLimitKey(Request $request): string
    {
        $email = $request->input('email');
        $normalizedEmail = is_string($email) ? Str::lower(trim($email)) : '';

        return hash('sha256', $normalizedEmail.'|'.$request->ip());
    }
}
