<?php

namespace App\Services;

use App\Contracts\CrawlPageFetcher;
use App\Contracts\RemotePageTransport;
use App\Data\CrawlFetchResult;
use App\Exceptions\AcquisitionFailure;

class SecureCrawlPageFetcher implements CrawlPageFetcher
{
    public function __construct(private readonly AcquisitionUrlPolicy $policy, private readonly RemotePageTransport $transport) {}

    public function fetch(string $url): CrawlFetchResult
    {
        $target = $this->policy->approve($url);
        $response = $this->transport->fetch($target);
        if ($response->peerIp !== '' && ! in_array($response->peerIp, $target->resolvedIps, true)) {
            throw new AcquisitionFailure('peer_address_mismatch');
        }

        return new CrawlFetchResult($response->status, strtolower(trim(explode(';', $response->header('content-type') ?? '')[0])), $response->body);
    }
}
