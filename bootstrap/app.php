<?php

use App\Exceptions\AnalysisNotFound;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\EnsureIntelligenceOperator;
use App\Http\Middleware\RequestContext;
use App\Http\Middleware\RequireApiKeyScope;
use App\Support\SafeRequestPath;
use App\Support\StructuredExceptionReporter;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
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
            'intelligence.operator' => EnsureIntelligenceOperator::class,
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

        $exceptions->render(function (AnalysisNotFound $exception, Request $request) {
            if (! $request->expectsJson()) {
                return response()->view('errors.analysis-not-found', [], 404, [
                    'Cache-Control' => 'private, no-store, max-age=0',
                    'Content-Security-Policy' => "default-src 'none'; style-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
                    'X-Content-Type-Options' => 'nosniff',
                ]);
            }

            return response()->json(['error' => [
                'code' => 'not_found',
                'message' => 'The requested analysis was not found.',
                'request_id' => (string) $request->attributes->get('request_id'),
            ]], 404);
        });

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
