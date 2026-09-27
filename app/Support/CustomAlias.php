<?php

namespace App\Support;

use Illuminate\Support\Str;

final class CustomAlias
{
    public static function canonicalize(string $alias): string
    {
        return Str::lower(trim($alias));
    }
}
