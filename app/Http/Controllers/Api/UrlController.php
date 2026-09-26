<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUrlRequest;
use App\Services\UrlResolver;
use App\Services\UrlShortenerService;
use App\Support\OperationalMetrics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class UrlController extends Controller
{
    public function store(
        StoreUrlRequest $request,
        UrlShortenerService $shortener,
        OperationalMetrics $metrics,
    ): JsonResponse {
        $startedAt = hrtime(true);
        $outcome = 'error';

        try {
            $result = $shortener->shorten(
                $request->validated('long_url'),
                $request->idempotencyKey(),
            );

            if ($result['conflict']) {
                $outcome = 'conflict';

                return response()->json([
                    'message' => 'This idempotency key was already used with a different request.',
                ], 409);
            }

            $outcome = $result['created'] ? 'created' : 'replayed';

            if ($result['created']) {
                try {
                    Cache::put(
                        'url:'.$result['response']['short_code'],
                        $result['response']['long_url'],
                        now()->addHours(24),
                    );
                    $metrics->cache('write', 'success');
                } catch (Throwable $exception) {
                    $metrics->cache('write', 'failure');
                    Log::warning('url_cache_operation_failed', [
                        'short_code' => $result['response']['short_code'],
                        'exception' => $exception->getMessage(),
                        'request_id' => $request->attributes->get('request_id'),
                        'url_path' => $request->path(),
                    ]);
                }
            }

            return response()->json($result['response'], $result['created'] ? 201 : 200);
        } finally {
            $metrics->request('create', $outcome, $this->elapsedMilliseconds($startedAt));
        }
    }

    public function redirect(
        string $shortCode,
        UrlResolver $resolver,
        OperationalMetrics $metrics,
    ): RedirectResponse {
        $startedAt = hrtime(true);
        $outcome = 'error';

        try {
            $longUrl = $resolver->resolve($shortCode);
            $outcome = $longUrl === null ? 'not_found' : 'found';

            abort_if($longUrl === null, 404);

            return redirect()->away($longUrl);
        } finally {
            $metrics->request('redirect', $outcome, $this->elapsedMilliseconds($startedAt));
        }
    }

    private function elapsedMilliseconds(int $startedAt): float
    {
        return (hrtime(true) - $startedAt) / 1_000_000;
    }
}
