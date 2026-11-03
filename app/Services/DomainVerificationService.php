<?php

namespace App\Services;

use App\Contracts\DomainControlVerifier;
use App\Models\DomainVerification;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;

class DomainVerificationService
{
    public function __construct(private readonly DomainControlVerifier $verifier) {}

    /** @return array{verification: DomainVerification, token: string} */
    public function challenge(User $owner, string $domain, string $method): array
    {
        $domain = strtolower(rtrim(trim($domain), '.'));
        if (! $this->validDomain($domain)
            || ! in_array($method, ['dns_txt', 'well_known', 'html_meta'], true)) {
            throw new InvalidArgumentException('Invalid domain verification request.');
        }
        $token = 'shortly-verification='.Str::random(48);
        $secret = (string) config('app.key');
        $verification = DomainVerification::create([
            'owner_id' => $owner->id, 'public_id' => 'dom_'.Str::ulid(), 'domain' => $domain,
            'domain_hash' => hash_hmac('sha256', $domain, $secret), 'method' => $method,
            'challenge_token' => $token, 'challenge_token_hash' => hash_hmac('sha256', $token, $secret),
            'challenge_expires_at' => now()->addMinutes(max(5, (int) config('website_intelligence.crawling.challenge_minutes', 30))),
        ]);

        return ['verification' => $verification, 'token' => $token];
    }

    public function confirm(DomainVerification $verification): bool
    {
        if ($verification->challenge_expires_at->isPast() || $verification->revoked_at !== null) {
            return false;
        }
        if (! $this->verifier->verify($verification->domain, $verification->method, $verification->challenge_token)) {
            return false;
        }
        $verification->forceFill([
            'verified_at' => now(),
            'verified_until' => now()->addDays(max(1, (int) config('website_intelligence.crawling.verification_days', 7))),
        ])->save();

        return true;
    }

    private function validDomain(string $domain): bool
    {
        if ($domain === '' || strlen($domain) > 253 || filter_var($domain, FILTER_VALIDATE_IP) || ! str_contains($domain, '.')) {
            return false;
        }
        foreach (explode('.', $domain) as $label) {
            if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $label)) {
                return false;
            }
        }

        return true;
    }
}
