<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IdempotencyKey;
use App\Models\Url;
use App\Services\Base62Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class UrlController extends Controller
{
    /**
     * Create a shortened URL.
     */
    public function store(Request $request, Base62Service $base62)
    {
        $validated = $request->validate([
            'long_url' => ['required', 'url', 'max:2048'],
        ]);

        $idempotencyKey = $request->header('Idempotency-Key');

        /*
         * If an idempotency key was provided,
         * check whether we have already processed it.
         */
        if ($idempotencyKey) {

            $existing = IdempotencyKey::where(
                'key',
                $idempotencyKey
            )->first();

            if ($existing) {
                return response()->json(
                    $existing->response,
                    200
                );
            }
        }

        /*
         * Create the URL and idempotency record
         * inside one MySQL transaction.
         */
        $result = DB::transaction(function () use (
            $validated,
            $base62,
            $idempotencyKey
        ) {

            /*
             * Create the URL first.
             */
            $url = Url::create([
                'long_url' => $validated['long_url'],
                'short_code' => 'TEMP',
                'short_url' => 'TEMP',
            ]);

            /*
             * Generate short code from the database ID.
             */
            $shortCode = $base62->encode($url->id);

            /*
             * Save the generated short code.
             */
            $url->update([
                'short_code' => $shortCode,
            ]);

            /*
             * Build the API response.
             */
            $response = [
                'id' => $url->id,
                'short_code' => $url->short_code,
                'short_url' => url('/' . $url->short_code),
                'long_url' => $url->long_url,
            ];

            /*
             * Save idempotency record in MySQL.
             */
            if ($idempotencyKey) {

                IdempotencyKey::create([
                    'key' => $idempotencyKey,
                    'request_hash' => hash(
                        'sha256',
                        $validated['long_url']
                    ),
                    'response' => $response,
                ]);
            }

            return [
                'url' => $url->fresh(),
                'response' => $response,
            ];
        });

        /*
         * Put the URL into Redis cache.
         *
         * Redis is an optimization, not our source of truth.
         */
        try {

            Cache::put(
                "url:{$result['url']->short_code}",
                $result['url']->long_url,
                now()->addHours(24)
            );

        } catch (Throwable $e) {

            /*
             * Redis failure should not invalidate
             * the successful MySQL transaction.
             */
        }

        /*
         * Cache the idempotency response in Redis.
         */
        if ($idempotencyKey) {

            try {

                Cache::put(
                    "idempotency:{$idempotencyKey}",
                    [
                        'request_hash' => hash(
                            'sha256',
                            $validated['long_url']
                        ),
                        'response' => $result['response'],
                    ],
                    now()->addHours(24)
                );

            } catch (Throwable $e) {

                /*
                 * Ignore Redis failure.
                 * MySQL has the permanent record.
                 */
            }
        }

        return response()->json(
            $result['response'],
            201
        );
    }


    /**
     * Redirect a short URL to its original URL.
     */
    public function redirect(string $shortCode)
    {
        $cacheKey = "url:{$shortCode}";

        $longUrl = null;

        /*
         * -------------------------------------------------
         * 1. Try Redis first.
         * -------------------------------------------------
         */
        try {

            $longUrl = Cache::get($cacheKey);

        } catch (Throwable $e) {

            /*
             * Redis unavailable.
             *
             * We will fall back to MySQL.
             */
        }


        /*
         * -------------------------------------------------
         * 2. Redis HIT.
         * -------------------------------------------------
         */
        if ($longUrl) {

            return redirect()->away($longUrl);
        }


        /*
         * -------------------------------------------------
         * 3. Redis MISS.
         *
         * Try to acquire a lock so that many simultaneous
         * requests don't all query MySQL.
         * -------------------------------------------------
         */
        try {

            $lock = Cache::lock(
                "lock:cache-rebuild:{$shortCode}",
                10
            );

            /*
             * We successfully acquired the lock.
             */
            if ($lock->get()) {

                try {

                    /*
                     * Check Redis again.
                     *
                     * Another request might have populated
                     * the cache before we acquired the lock.
                     */
                    $longUrl = Cache::get($cacheKey);

                    if (!$longUrl) {

                        /*
                         * -------------------------------------------------
                         * 4. Query MySQL.
                         * -------------------------------------------------
                         */
                        $url = Url::where(
                            'short_code',
                            $shortCode
                        )->first();

                        /*
                         * Short code doesn't exist.
                         */
                        if (!$url) {

                            abort(404);
                        }

                        $longUrl = $url->long_url;

                        /*
                         * -------------------------------------------------
                         * 5. Populate Redis.
                         * -------------------------------------------------
                         */
                        Cache::put(
                            $cacheKey,
                            $longUrl,
                            now()->addHours(24)
                        );
                    }

                } finally {

                    /*
                     * Always release the lock.
                     */
                    $lock->release();
                }

            } else {

                /*
                 * -------------------------------------------------
                 * Someone else is rebuilding the cache.
                 *
                 * Wait briefly.
                 * -------------------------------------------------
                 */
                usleep(100000);

                /*
                 * Try Redis again.
                 */
                $longUrl = Cache::get($cacheKey);

                /*
                 * If the cache is still unavailable,
                 * fall back to MySQL.
                 */
                if (!$longUrl) {

                    $url = Url::where(
                        'short_code',
                        $shortCode
                    )->first();

                    if (!$url) {

                        abort(404);
                    }

                    $longUrl = $url->long_url;
                }
            }

        } catch (Throwable $e) {

            /*
             * -------------------------------------------------
             * Redis is unavailable.
             *
             * MySQL is our source of truth.
             * -------------------------------------------------
             */

            $url = Url::where(
                'short_code',
                $shortCode
            )->first();

            if (!$url) {

                abort(404);
            }

            $longUrl = $url->long_url;
        }


        /*
         * -------------------------------------------------
         * 6. Redirect the user.
         * -------------------------------------------------
         */
        return redirect()->away($longUrl);
    }
}