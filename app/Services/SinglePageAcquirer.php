<?php

namespace App\Services;

use App\Contracts\RemotePageTransport;
use App\Exceptions\AcquisitionFailure;
use Closure;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;

class SinglePageAcquirer
{
    public function __construct(
        private readonly AcquisitionUrlPolicy $policy,
        private readonly RemotePageTransport $transport,
        private readonly SafePageExtractor $extractor,
    ) {}

    /** @return array{source_url: string, extraction: array<string, mixed>, provenance: array<string, mixed>} */
    public function acquire(string $url, Closure $cancelled): array
    {
        $startedAt = hrtime(true);
        $maxRedirects = max(0, (int) config('website_intelligence.budgets.max_redirects', 3));

        for ($redirects = 0; ; $redirects++) {
            $this->assertWithinRuntime($startedAt);
            if ($cancelled()) {
                throw new AcquisitionFailure('cancelled');
            }

            $target = $this->policy->approve($url);
            $response = $this->transport->fetch($target);
            $this->assertWithinRuntime($startedAt);

            if (! $this->peerWasApproved($response->peerIp, $target->approvedIps)) {
                throw new AcquisitionFailure('peer_address_mismatch');
            }

            if (in_array($response->status, [301, 302, 303, 307, 308], true)) {
                if ($redirects >= $maxRedirects) {
                    throw new AcquisitionFailure('redirect_limit');
                }
                $location = $response->header('location');
                if ($location === null || trim($location) === '') {
                    throw new AcquisitionFailure('invalid_redirect');
                }
                try {
                    $url = (string) UriResolver::resolve(new Uri($target->url), new Uri(trim($location)));
                } catch (\Throwable) {
                    throw new AcquisitionFailure('invalid_redirect');
                }

                continue;
            }

            if ($response->status < 200 || $response->status >= 300) {
                throw new AcquisitionFailure('http_status');
            }
            if ($cancelled()) {
                throw new AcquisitionFailure('cancelled');
            }

            return $this->extractor->extract(
                body: $response->body,
                contentType: $response->header('content-type') ?? '',
                contentEncoding: $response->header('content-encoding'),
                sourceUrl: $target->url,
                status: $response->status,
            );
        }
    }

    /** @param list<string> $approvedIps */
    private function peerWasApproved(string $peerIp, array $approvedIps): bool
    {
        $packedPeer = @inet_pton(trim($peerIp, '[]'));
        if ($packedPeer === false || ! $this->policy->publicIp(trim($peerIp, '[]'))) {
            return false;
        }

        foreach ($approvedIps as $approvedIp) {
            if (@inet_pton($approvedIp) === $packedPeer) {
                return true;
            }
        }

        return false;
    }

    private function assertWithinRuntime(int $startedAt): void
    {
        $elapsed = (hrtime(true) - $startedAt) / 1_000_000_000;
        if ($elapsed > max(1, (int) config('website_intelligence.budgets.max_job_runtime_seconds', 120))) {
            throw new AcquisitionFailure('runtime_limit');
        }
    }
}
