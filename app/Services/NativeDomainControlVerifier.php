<?php

namespace App\Services;

use App\Contracts\CrawlPageFetcher;
use App\Contracts\DomainControlVerifier;
use Throwable;

class NativeDomainControlVerifier implements DomainControlVerifier
{
    public function __construct(private readonly CrawlPageFetcher $fetcher) {}

    public function verify(string $domain, string $method, string $token): bool
    {
        try {
            return match ($method) {
                'dns_txt' => $this->dns($domain, $token),
                'well_known' => hash_equals($token, trim($this->fetcher->fetch('https://'.$domain.'/.well-known/shortly-verification')->body)),
                'html_meta' => $this->meta($this->fetcher->fetch('https://'.$domain.'/')->body, $token),
                default => false,
            };
        } catch (Throwable) {
            return false;
        }
    }

    private function dns(string $domain, string $token): bool
    {
        $records = dns_get_record('_shortly-verification.'.$domain, DNS_TXT);
        foreach (is_array($records) ? $records : [] as $record) {
            if (is_string($record['txt'] ?? null) && hash_equals($token, trim($record['txt']))) {
                return true;
            }
        }

        return false;
    }

    private function meta(string $html, string $token): bool
    {
        $dom = new \DOMDocument;
        if (! @$dom->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            return false;
        }
        foreach ($dom->getElementsByTagName('meta') as $meta) {
            if (strtolower($meta->getAttribute('name')) === 'shortly-verification'
                && hash_equals($token, trim($meta->getAttribute('content')))) {
                return true;
            }
        }

        return false;
    }
}
