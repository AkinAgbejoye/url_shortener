<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireApiKeyScope
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next, string $scope, string $required = 'optional'): Response
    {
        $outcome = $request->attributes->get('api_key_authentication');
        $apiKey = $request->attributes->get('api_key');

        if (! in_array($outcome, ['missing', 'valid'], true)) {
            return $this->unauthenticated();
        }

        if (! $apiKey instanceof ApiKey) {
            return $required === 'required' ? $this->unauthenticated() : $next($request);
        }

        if (! $apiKey->allows($scope)) {
            return response()->json([
                'message' => 'This API key does not have the required scope.',
            ], 403);
        }

        return $next($request);
    }

    private function unauthenticated(): JsonResponse
    {
        return response()->json(['message' => 'Unauthenticated.'], 401);
    }
}
