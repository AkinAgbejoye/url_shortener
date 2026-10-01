<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;

class SafeRequestPath
{
    public static function for(?Request $request): ?string
    {
        if ($request === null) {
            return null;
        }

        $path = $request->path();
        if (! str_starts_with($path, 'api/v1/urls/')) {
            return $path;
        }

        $route = $request->route();

        return $route instanceof Route ? $route->uri() : 'api/v1/urls/{shortCode}';
    }
}
