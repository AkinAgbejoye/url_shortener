<?php

use App\Http\Middleware\RequestContext;
use App\Support\StructuredExceptionReporter;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        api: __DIR__.'/../routes/api.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(RequestContext::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->context(fn (): array => [
            'request_id' => request()?->attributes->get('request_id'),
            'url_path' => request()?->path(),
        ]);

        $exceptions->report(function (Throwable $exception): void {
            try {
                app(StructuredExceptionReporter::class)->report($exception);
            } catch (Throwable) {
                // Exception reporting must never replace the original response.
            }
        })->stop();
    })->create();
