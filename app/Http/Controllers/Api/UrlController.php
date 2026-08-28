<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Url;
use App\Services\Base62Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class UrlController extends Controller
{
    public function store(Request $request, Base62Service $base62)
{
    $validated = $request->validate([
        'long_url' => ['required', 'url', 'max:2048'],
    ]);

    $idempotencyKey = $request->header('Idempotency-Key');

    if (!$idempotencyKey) {
        return response()->json([
            'message' => 'Idempotency-Key header is required.',
        ], 400);
    }

    $cacheKey = "idempotency:{$idempotencyKey}";

    /*
    |--------------------------------------------------------------------------
    | Create a fingerprint of the request
    |--------------------------------------------------------------------------
    */

    $requestHash = hash(
        'sha256',
        json_encode($validated)
    );

    /*
    |--------------------------------------------------------------------------
    | Check if this idempotency key was already used
    |--------------------------------------------------------------------------
    */

    $existing = Cache::get($cacheKey);

    if ($existing) {

        if ($existing['request_hash'] !== $requestHash) {
            return response()->json([
                'message' => 'Idempotency-Key was already used with a different request.',
            ], 409);
        }

        return response()->json(
            $existing['response'],
            200
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Acquire a Redis lock
    |--------------------------------------------------------------------------
    */

    $lock = Cache::lock(
        "lock:idempotency:{$idempotencyKey}",
        10
    );

    if (!$lock->get()) {
        return response()->json([
            'message' => 'Request is already being processed.',
        ], 409);
    }

    try {

        /*
        |--------------------------------------------------------------------------
        | Check again after acquiring the lock
        |--------------------------------------------------------------------------
        */

        $existing = Cache::get($cacheKey);

        if ($existing) {

            if ($existing['request_hash'] !== $requestHash) {
                return response()->json([
                    'message' => 'Idempotency-Key was already used with a different request.',
                ], 409);
            }

            return response()->json(
                $existing['response'],
                200
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Create URL
        |--------------------------------------------------------------------------
        */

        $url = Url::create([
            'long_url' => $validated['long_url'],
            'short_code' => 'TEMP',
            'short_url' => 'TEMP',
        ]);

        $shortCode = $base62->encode($url->id);

        $url->update([
            'short_code' => $shortCode,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Cache URL
        |--------------------------------------------------------------------------
        */

        Cache::put(
            "url:{$shortCode}",
            $url->long_url,
            now()->addHours(24)
        );

        /*
        |--------------------------------------------------------------------------
        | Build response
        |--------------------------------------------------------------------------
        */

        $response = [
            'id' => $url->id,
            'short_code' => $url->short_code,
            'short_url' => url('/' . $url->short_code),
            'long_url' => $url->long_url,
        ];

        /*
        |--------------------------------------------------------------------------
        | Store idempotency result
        |--------------------------------------------------------------------------
        */

        Cache::put(
            $cacheKey,
            [
                'request_hash' => $requestHash,
                'response' => $response,
            ],
            now()->addHours(24)
        );

        return response()->json($response, 201);

    } finally {

        /*
        |--------------------------------------------------------------------------
        | Always release the lock
        |--------------------------------------------------------------------------
        */

        $lock->release();
    }
}

    public function redirect(string $shortCode)
    {
        $longUrl = Cache::get("url:{$shortCode}");

        if (!$longUrl) {
            $url = Url::where('short_code', $shortCode)->firstOrFail();

            $longUrl = $url->long_url;

            Cache::put(
                "url:{$shortCode}",
                $longUrl,
                now()->addHours(24)
            );
        }

        return redirect()->away($longUrl);
    }
}