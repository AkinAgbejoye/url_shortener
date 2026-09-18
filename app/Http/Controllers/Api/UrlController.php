<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUrlRequest;
use App\Services\UrlResolver;
use App\Services\UrlShortenerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class UrlController extends Controller
{
    public function store(StoreUrlRequest $request, UrlShortenerService $shortener): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key');

        if (is_string($idempotencyKey) && mb_strlen($idempotencyKey) > 255) {
            return response()->json([
                'message' => 'The Idempotency-Key header may not be greater than 255 characters.',
            ], 422);
        }

        $result = $shortener->shorten(
            $request->validated('long_url'),
            is_string($idempotencyKey) && $idempotencyKey !== '' ? $idempotencyKey : null,
        );

        if ($result['conflict']) {
            return response()->json([
                'message' => 'This idempotency key was already used with a different request.',
            ], 409);
        }

        if ($result['created']) {
            try {
                Cache::put(
                    'url:'.$result['response']['short_code'],
                    $result['response']['long_url'],
                    now()->addHours(24),
                );
            } catch (Throwable $exception) {
                Log::warning('url_cache_operation_failed', [
                    'short_code' => $result['response']['short_code'],
                    'exception' => $exception->getMessage(),
                    'request_id' => $request->attributes->get('request_id'),
                    'url_path' => $request->path(),
                ]);
            }
        }

        return response()->json($result['response'], $result['created'] ? 201 : 200);
    }

    public function redirect(string $shortCode, UrlResolver $resolver): RedirectResponse
    {
        $longUrl = $resolver->resolve($shortCode);

        abort_if($longUrl === null, 404);

        return redirect()->away($longUrl);
    }
}
