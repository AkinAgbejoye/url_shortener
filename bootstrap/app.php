<?php

use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\RequestContext;
use App\Http\Middleware\RequireApiKeyScope;
use App\Support\SafeRequestPath;
use App\Support\StructuredExceptionReporter;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        api: __DIR__.'/../routes/api.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(RequestContext::class);
        $middleware->redirectUsersTo('/account');
        $middleware->alias([
            'api-key.authenticate' => AuthenticateApiKey::class,
            'api-key.scope' => RequireApiKeyScope::class,
        ]);
        $middleware->prependToPriorityList([
            AuthenticatesRequests::class,
            ThrottleRequests::class,
            ThrottleRequestsWithRedis::class,
        ], AuthenticateApiKey::class);
        $middleware->appendToPriorityList([
            ThrottleRequests::class,
            ThrottleRequestsWithRedis::class,
        ], RequireApiKeyScope::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash('token');

        $exceptions->context(fn (): array => [
            'request_id' => request()?->attributes->get('request_id'),
            'url_path' => SafeRequestPath::for(request()),
        ]);

        $exceptions->report(function (Throwable $exception): void {
            try {
                app(StructuredExceptionReporter::class)->report($exception);
            } catch (Throwable) {
                // Exception reporting must never replace the original response.
            }
        })->stop();
    })->create();
