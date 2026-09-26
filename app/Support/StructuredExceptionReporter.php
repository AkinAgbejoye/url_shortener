<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class StructuredExceptionReporter
{
    public function report(Throwable $exception): void
    {
        $request = $this->request();

        Log::channel((string) config('logging.exception_channel', 'exceptions'))
            ->error('unhandled_exception', [
                'exception_class' => $exception::class,
                'request_id' => $this->boundedValue(
                    $request?->attributes->get('request_id'),
                    255,
                ),
                'url_path' => $this->boundedValue($request?->path(), 2048),
                'http_method' => $request?->method(),
                'user_agent' => $this->boundedValue($request?->userAgent(), 512),
            ]);
    }

    private function request(): ?Request
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = app('request');

        return $request instanceof Request ? $request : null;
    }

    private function boundedValue(mixed $value, int $length): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return substr(str_replace(["\r", "\n"], '', $value), 0, $length);
    }
}
