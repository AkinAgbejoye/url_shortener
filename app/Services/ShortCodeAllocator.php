<?php

namespace App\Services;

use App\Exceptions\CustomAliasConflict;
use App\Exceptions\ShortCodeAllocationExhausted;
use App\Models\Url;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class ShortCodeAllocator
{
    public function __construct(private readonly Base62Service $base62) {}

    public function claim(Url $url, ?string $customAlias): string
    {
        if ($customAlias !== null) {
            try {
                $this->claimCandidate($url, $customAlias, true);
            } catch (UniqueConstraintViolationException) {
                throw new CustomAliasConflict;
            }

            return $customAlias;
        }

        $base = $this->base62->encode($url->id);
        $attempts = min(100, max(
            1,
            (int) config('url_shortener.aliases.allocation_attempts', 20),
        ));

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $candidate = $attempt === 0 ? $base : "{$base}-{$attempt}";

            try {
                $this->claimCandidate($url, $candidate, false);

                return $candidate;
            } catch (UniqueConstraintViolationException) {
                // The unique index arbitrates concurrent claims. Try the next bounded candidate.
            }
        }

        throw new ShortCodeAllocationExhausted;
    }

    private function claimCandidate(Url $url, string $candidate, bool $custom): void
    {
        DB::transaction(function () use ($url, $candidate, $custom): void {
            $url->update([
                'short_code' => $candidate,
                'is_custom' => $custom,
            ]);
        });
    }
}
