<?php

namespace App\Reporting;

use App\Contracts\ExternalExceptionReporter;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Throwable;

class SentryExceptionReporter implements ExternalExceptionReporter
{
    public function __construct(private readonly HubInterface $hub) {}

    public function report(Throwable $exception, array $context): void
    {
        $this->hub->withScope(function (Scope $scope) use ($context, $exception): void {
            $scope->clear();

            if (is_string($context['request_id'] ?? null)) {
                $scope->setTag('request_id', $context['request_id']);
            }

            $scope->setContext('request_summary', array_filter(
                [
                    'request_id' => $context['request_id'] ?? null,
                    'url_path' => $context['url_path'] ?? null,
                    'http_method' => $context['http_method'] ?? null,
                    'user_agent' => $context['user_agent'] ?? null,
                ],
                fn (mixed $value): bool => is_string($value) && $value !== '',
            ));

            $this->hub->captureException($exception);
        });
    }
}
