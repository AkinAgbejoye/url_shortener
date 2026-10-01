<?php

namespace App\Http\Middleware;

use App\Services\ApiKeyAuthenticator;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiKey
{
    public function __construct(private readonly ApiKeyAuthenticator $authenticator) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $authorization = $request->header('Authorization');
        $result = $this->authenticator->authenticate(is_string($authorization) ? $authorization : null);
        $request->attributes->set('api_key_authentication', $result['outcome']);

        if ($result['api_key'] !== null) {
            $apiKey = $result['api_key'];
            $request->attributes->set('api_key', $apiKey);
            $request->setUserResolver(fn () => $apiKey->user);

            $guard = Auth::guard();
            $previousUser = $guard->user();
            $guard->setUser($apiKey->user);

            try {
                return $next($request);
            } finally {
                $previousUser === null
                    ? $guard->forgetUser()
                    : $guard->setUser($previousUser);
            }
        }

        return $next($request);
    }
}
