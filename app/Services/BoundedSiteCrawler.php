<?php

namespace App\Services;

use App\Contracts\CrawlPageFetcher;
use App\Models\CrawlJob;
use App\Models\CrawlPage;
use App\Models\ModerationDomainBlock;
use Illuminate\Support\Str;
use RuntimeException;

class BoundedSiteCrawler
{
    public function __construct(private readonly CrawlPageFetcher $fetcher, private readonly ?MonitoringEvidenceCollector $evidence = null) {}

    /** @return array<string, int> */
    public function crawl(CrawlJob $job): array
    {
        $verification = $job->verification;
        $origin = 'https://'.$verification->domain;
        if (! $verification->current()
            || ModerationDomainBlock::query()->where('domain_hash', $verification->domain_hash)->whereNull('released_at')->exists()) {
            throw new RuntimeException('authorization_expired');
        }
        $robotsResult = $this->fetcher->fetch($origin.'/robots.txt');
        $robots = $robotsResult->status === 404 ? RobotsPolicy::parse('') : RobotsPolicy::parse($robotsResult->body);
        if ($robotsResult->status !== 404 && ($robotsResult->status < 200 || $robotsResult->status >= 300)) {
            throw new RuntimeException('robots_unavailable');
        }
        $job->forceFill(['robots_policy' => $robots->snapshot()])->save();

        $queue = [[$origin.'/', 0]];
        foreach ($robots->sitemaps as $sitemap) {
            if ($this->sameOrigin($sitemap, $origin)) {
                $queue[] = [$sitemap, 0];
            }
        }
        $seen = [];
        $canonicals = [];
        $fetched = $bytes = $blocked = $deduplicated = 0;
        $policy = $job->policy;
        while ($queue !== [] && $fetched < $policy['max_pages'] && $bytes < $policy['max_bytes']) {
            [$url, $depth] = array_shift($queue);
            $url = $this->normalize($url);
            $hash = hash('sha256', $url);
            if (isset($seen[$hash]) || $depth > $policy['max_depth'] || ! $this->eligible($url, $origin, $policy)) {
                continue;
            }
            $seen[$hash] = true;
            $job->refresh();
            $verification->refresh();
            if ($job->cancel_requested_at !== null || $job->state === 'paused') {
                break;
            }
            if (! $verification->current() || ModerationDomainBlock::query()->where('domain_hash', $verification->domain_hash)->whereNull('released_at')->exists()) {
                throw new RuntimeException('authorization_expired');
            }
            $path = parse_url($url, PHP_URL_PATH) ?: '/';
            if (! $robots->allows($path)) {
                $blocked++;

                continue;
            }
            $result = $this->fetcher->fetch($url);
            $pageBytes = strlen($result->body);
            if ($bytes + $pageBytes > $policy['max_bytes']) {
                break;
            }
            $bytes += $pageBytes;
            $fetched++;
            $canonical = $this->canonical($result->body, $url);
            $canonicalHash = $canonical === null ? null : hash('sha256', $canonical);
            $outcome = 'fetched';
            if ($canonicalHash !== null && isset($canonicals[$canonicalHash])) {
                $outcome = 'duplicate';
                $deduplicated++;
            }
            if ($canonicalHash !== null) {
                $canonicals[$canonicalHash] = true;
            }
            CrawlPage::create([
                'crawl_job_id' => $job->id, 'public_id' => 'page_'.Str::ulid(), 'url' => $url,
                'url_hash' => $hash, 'canonical_hash' => $canonicalHash, 'depth' => $depth,
                'status_code' => $result->status, 'bytes' => $pageBytes, 'content_hash' => hash('sha256', $result->body),
                'evidence_snapshot' => ($this->evidence ?? app(MonitoringEvidenceCollector::class))->snapshot($result->body, $result->contentType),
                'outcome' => $outcome, 'fetched_at' => now(),
            ]);
            if ($outcome === 'fetched' && $depth < $policy['max_depth']) {
                foreach ($this->discover($result->body, $result->contentType, $url) as $link) {
                    $queue[] = [$link, $depth + 1];
                }
            }
        }

        return compact('fetched', 'bytes', 'blocked', 'deduplicated');
    }

    /** @param array<string, mixed> $policy */
    private function eligible(string $url, string $origin, array $policy): bool
    {
        if (! $this->sameOrigin($url, $origin)) {
            return false;
        }
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        foreach ($policy['exclude_paths'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return false;
            }
        }
        $included = false;
        foreach ($policy['include_paths'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $included = true;
            }
        }
        if (! $included) {
            return false;
        }
        $query = parse_url($url, PHP_URL_QUERY) ?: '';
        parse_str($query, $params);

        return count($params) <= 5 && ! preg_match('~/(?:19|20)\d{2}/(?:0?[1-9]|1[0-2])(?:/|$)~', $path) && ! isset($params['session']) && ! isset($params['sid']);
    }

    /** @return list<string> */
    private function discover(string $body, string $contentType, string $base): array
    {
        if (str_contains($contentType, 'xml')) {
            preg_match_all('~<loc>\s*([^<]+)\s*</loc>~i', mb_strcut($body, 0, 1000000, 'UTF-8'), $matches);

            return array_slice(array_values(array_unique(array_map('html_entity_decode', $matches[1] ?? []))), 0, 500);
        }
        $dom = new \DOMDocument;
        if (! @$dom->loadHTML($body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            return [];
        }
        $links = [];
        foreach ($dom->getElementsByTagName('a') as $anchor) {
            $href = trim($anchor->getAttribute('href'));
            if ($href !== '') {
                $links[] = $this->resolve($base, $href);
            }
        }

        return array_slice(array_values(array_unique(array_filter($links))), 0, 500);
    }

    private function canonical(string $html, string $base): ?string
    {
        $dom = new \DOMDocument;
        if (! @$dom->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            return null;
        }
        foreach ($dom->getElementsByTagName('link') as $link) {
            if (strtolower($link->getAttribute('rel')) === 'canonical') {
                return $this->resolve($base, $link->getAttribute('href'));
            }
        }

        return null;
    }

    private function resolve(string $base, string $relative): string
    {
        if (filter_var($relative, FILTER_VALIDATE_URL)) {
            return $relative;
        }
        $parts = parse_url($base);
        $origin = $parts['scheme'].'://'.$parts['host'];
        if (str_starts_with($relative, '/')) {
            return $origin.$relative;
        }

        return $origin.rtrim(dirname($parts['path'] ?? '/'), '/').'/'.$relative;
    }

    private function normalize(string $url): string
    {
        return strtok($url, '#') ?: $url;
    }

    private function sameOrigin(string $url, string $origin): bool
    {
        return strtolower((string) parse_url($url, PHP_URL_SCHEME).'://'.(string) parse_url($url, PHP_URL_HOST)) === strtolower($origin);
    }
}
