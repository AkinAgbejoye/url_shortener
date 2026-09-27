<?php

namespace App\Services;

use App\Exceptions\CustomAliasConflict;
use App\Exceptions\ShortCodeAllocationExhausted;
use App\Models\Url;
use App\Support\OperationalMetrics;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShortCodeAllocator
{
    private OperationalMetrics $metrics;

    public function __construct(
        private readonly Base62Service $base62,
        ?OperationalMetrics $metrics = null,
    ) {
        $this->metrics = $metrics ?? app(OperationalMetrics::class);
    }

    public function claim(Url $url, ?string $customAlias): string
    {
        if ($customAlias !== null) {
            try {
                $this->claimCandidate($url, $customAlias, true);
            } catch (UniqueConstraintViolationException) {
                $this->metrics->aliasAllocation('custom', 'conflict');
                Log::info('url_alias_allocation_conflict', [
                    'alias_type' => 'custom',
                    'outcome' => 'conflict',
                ]);

                throw new CustomAliasConflict;
            }

            $this->metrics->aliasAllocation('custom', 'claimed');

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
                $this->metrics->aliasAllocation('generated', 'claimed');

                return $candidate;
            } catch (UniqueConstraintViolationException) {
                $this->metrics->aliasAllocation('generated', 'retry');
                Log::info('url_alias_allocation_retry', [
                    'alias_type' => 'generated',
                    'outcome' => 'retry',
                    'attempt' => $attempt + 1,
                    'max_attempts' => $attempts,
                ]);
            }
        }

        $this->metrics->aliasAllocation('generated', 'exhausted');
        Log::warning('url_alias_allocation_exhausted', [
            'alias_type' => 'generated',
            'outcome' => 'exhausted',
            'max_attempts' => $attempts,
        ]);

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
