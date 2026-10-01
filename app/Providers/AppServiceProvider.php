<?php

namespace App\Providers;

use App\Contracts\ExternalExceptionReporter;
use App\Contracts\MetricsExporter;
use App\Metrics\NullMetricsExporter;
use App\Metrics\SafeMetricsExporter;
use App\Metrics\StatsdMetricsExporter;
use App\Models\ApiKey;
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

        RateLimiter::for('password-reset', fn (Request $request): Limit => Limit::perMinute(5)
            ->by($this->authRateLimitKey($request)));

        RateLimiter::for('verification', fn (Request $request): Limit => Limit::perMinute(3)
            ->by(hash('sha256', ($request->user()?->getAuthIdentifier() ?? 'guest').'|'.$request->ip())));

        RateLimiter::for('api-keys.list', fn (Request $request): Limit => Limit::perMinute(
            (int) config('url_shortener.api_keys.list_per_minute', 30),
        )->by($this->accountRateLimitKey($request)));

        RateLimiter::for('api-keys.create', fn (Request $request): Limit => Limit::perMinute(
            (int) config('url_shortener.api_keys.create_per_minute', 5),
        )->by($this->accountRateLimitKey($request)));

        RateLimiter::for('api-keys.revoke', fn (Request $request): Limit => Limit::perMinute(
            (int) config('url_shortener.api_keys.revoke_per_minute', 10),
        )->by($this->accountRateLimitKey($request)));

        RateLimiter::for('api-key.requests', function (Request $request): Limit|array {
            $apiKey = $request->attributes->get('api_key');

            if (! $apiKey instanceof ApiKey) {
                $outcome = $request->attributes->get('api_key_authentication');
                $limit = $outcome === 'missing'
                    ? $this->anonymousApiLimit($request)
                    : (int) config('url_shortener.api_keys.invalid_requests_per_minute', 10);

                return Limit::perMinute(
                    $limit,
                )->by(hash('sha256', 'ip|'.$request->ip()));
            }

            return [
                Limit::perMinute(
                    (int) config('url_shortener.api_keys.requests_per_minute', 60),
                )->by(hash('sha256', 'key|'.$apiKey->getKey())),
                Limit::perMinute(
                    (int) config('url_shortener.api_keys.account_requests_per_minute', 120),
                )->by(hash('sha256', 'account|'.$apiKey->user_id)),
            ];
        });
    }

    private function authRateLimitKey(Request $request): string
    {
        $email = $request->input('email');
        $normalizedEmail = is_string($email) ? Str::lower(trim($email)) : '';

        return hash('sha256', $normalizedEmail.'|'.$request->ip());
    }

    private function accountRateLimitKey(Request $request): string
    {
        return hash('sha256', ($request->user()?->getAuthIdentifier() ?? 'guest').'|'.$request->ip());
    }

    private function anonymousApiLimit(Request $request): int
    {
        if ($request->isMethod('post') && $request->path() === 'api/v1/urls') {
            return (int) config('url_shortener.api_keys.anonymous_create_per_minute', 10);
        }

        return (int) config('url_shortener.api_keys.anonymous_manage_per_minute', 30);
    }
}
