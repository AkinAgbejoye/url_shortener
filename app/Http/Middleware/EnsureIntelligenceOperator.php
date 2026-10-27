<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureIntelligenceOperator
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $operators = config('website_intelligence.operators.emails', []);

        abort_unless(
            $user instanceof User
            && $user->hasVerifiedEmail()
            && is_array($operators)
            && in_array(strtolower($user->email), $operators, true),
            404,
        );

        return $next($request);
    }
}
