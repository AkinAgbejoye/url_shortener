<?php

namespace App\Services;

use App\Exceptions\AcquisitionFailure;
use DOMDocument;
use DOMElement;
use DOMXPath;

class SafePageExtractor
{
    /** @return array{source_url: string, extraction: array<string, mixed>, provenance: array<string, mixed>} */
    public function extract(string $body, string $contentType, ?string $contentEncoding, string $sourceUrl, int $status): array
    {
        $compressedBytes = strlen($body);
        $decoded = $this->decode($body, $contentEncoding);
        $mime = strtolower(trim(explode(';', $contentType, 2)[0]));
        if (! in_array($mime, ['text/html', 'application/xhtml+xml', 'text/plain'], true)) {
            throw new AcquisitionFailure('unsupported_content');
        }

        if ($mime === 'text/plain') {
            $text = $this->boundedText($decoded);
            $extraction = [
                'identity' => ['title' => null, 'description' => null, 'canonical_url' => null],
                'title' => null,
                'headings' => [],
                'heading_details' => [],
                'text' => $text,
                'links' => [],
                'structured_data' => [],
                'pricing_signals' => [],
                'calls_to_action' => [],
                'contact_methods' => [],
                'technology_indicators' => [],
            ];
        } else {
            $extraction = $this->html($decoded);
        }

        $parts = parse_url($sourceUrl);
        $origin = strtolower((string) ($parts['scheme'] ?? '')).'://'.strtolower((string) ($parts['host'] ?? ''));

        return [
            'source_url' => $sourceUrl,
            'extraction' => $extraction,
            'provenance' => [
                'source_origin' => $origin,
                'source_hash' => hash('sha256', $sourceUrl),
                'fetched_at' => now()->utc()->toIso8601String(),
                'status' => $status,
                'content_type' => $mime,
                'compressed_bytes' => $compressedBytes,
                'decompressed_bytes' => strlen($decoded),
                'content_hash' => hash('sha256', $decoded),
                'javascript_rendered' => false,
            ],
        ];
    }

    private function decode(string $body, ?string $contentEncoding): string
    {
        $maxCompressed = max(1024, (int) config('website_intelligence.budgets.max_compressed_bytes', 1000000));
        $maxDecoded = max(1024, (int) config('website_intelligence.budgets.max_decompressed_bytes', 5000000));
        if (strlen($body) > $maxCompressed) {
            throw new AcquisitionFailure('compressed_body_too_large');
        }

        $encoding = strtolower(trim((string) $contentEncoding));
        if ($encoding === '' || $encoding === 'identity') {
            return strlen($body) > $maxDecoded ? throw new AcquisitionFailure('body_too_large') : $body;
        }
        if (! in_array($encoding, ['gzip', 'x-gzip'], true)) {
            throw new AcquisitionFailure('unsupported_content_encoding');
        }

        $inflater = @inflate_init(ZLIB_ENCODING_GZIP);
        if ($inflater === false) {
            throw new AcquisitionFailure('malformed_compressed_body');
        }

        $decoded = '';
        $length = strlen($body);
        for ($offset = 0; $offset < $length; $offset += 1024) {
            $flush = $offset + 1024 >= $length ? ZLIB_FINISH : ZLIB_SYNC_FLUSH;
            $chunk = @inflate_add($inflater, substr($body, $offset, 1024), $flush);
            if ($chunk === false) {
                throw new AcquisitionFailure('malformed_compressed_body');
            }
            $decoded .= $chunk;
            if (strlen($decoded) > $maxDecoded) {
                throw new AcquisitionFailure('body_too_large');
            }
        }

        return $decoded;
    }

    /** @return array<string, mixed> */
    private function html(string $html): array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            if (! $document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT)) {
                throw new AcquisitionFailure('malformed_document');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('//*');
        if ($nodes === false || $nodes->length > max(1, (int) config('website_intelligence.budgets.max_dom_nodes', 20000))) {
            throw new AcquisitionFailure('document_too_complex');
        }

        $titleNode = $document->getElementsByTagName('title')->item(0);
        $title = $titleNode ? $this->boundedText($titleNode->textContent, 300) : null;
        $description = $this->metaContent($xpath, 'description');
        $canonical = null;
        foreach ($xpath->query('//link[contains(concat(" ", translate(@rel, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), " "), " canonical ")]') ?: [] as $link) {
            if ($link instanceof DOMElement) {
                $canonical = $this->safeLink(trim($link->getAttribute('href')));
                break;
            }
        }
        $structuredData = $this->structuredData($document);
        $callsToAction = $this->callsToAction($xpath);
        $contactMethods = $this->contactMethods($document);
        $technologies = $this->technologyIndicators($html, $xpath);

        foreach (['script', 'style', 'noscript', 'template', 'svg', 'iframe', 'object', 'embed', 'form', 'input', 'button'] as $tag) {
            $matches = $document->getElementsByTagName($tag);
            while ($matches->length > 0) {
                $node = $matches->item(0);
                $node?->parentNode?->removeChild($node);
            }
        }
        foreach ($xpath->query('//@*') ?: [] as $attribute) {
            if (str_starts_with(strtolower($attribute->nodeName), 'on') || strtolower($attribute->nodeName) === 'style') {
                $attribute->ownerElement?->removeAttribute($attribute->nodeName);
            }
        }

        $headings = [];
        $headingDetails = [];
        foreach ($xpath->query('//h1|//h2|//h3|//h4|//h5|//h6') ?: [] as $heading) {
            $value = $this->boundedText($heading->textContent, 500);
            if ($value !== '' && count($headings) < 100) {
                $headings[] = $value;
                $headingDetails[] = ['level' => strtolower($heading->nodeName), 'text' => $value];
            }
        }

        $links = [];
        $maxLinks = max(0, (int) config('website_intelligence.budgets.max_links', 500));
        foreach ($document->getElementsByTagName('a') as $anchor) {
            if (! $anchor instanceof DOMElement || count($links) >= $maxLinks) {
                break;
            }
            $href = trim($anchor->getAttribute('href'));
            $safeHref = $this->safeLink($href);
            if ($safeHref !== null) {
                $links[] = $safeHref;
            }
        }

        $readableText = $this->boundedText($document->textContent);
        $contactMethods = array_values(array_unique(array_slice([
            ...$contactMethods,
            ...$this->textContacts($readableText),
        ], 0, 50)));

        return [
            'identity' => ['title' => $title === '' ? null : $title, 'description' => $description, 'canonical_url' => $canonical],
            'title' => $title === '' ? null : $title,
            'headings' => array_values(array_unique($headings)),
            'heading_details' => $headingDetails,
            'text' => $readableText,
            'links' => array_values(array_unique($links)),
            'structured_data' => $structuredData,
            'pricing_signals' => $this->pricingSignals($readableText),
            'calls_to_action' => $callsToAction,
            'contact_methods' => $contactMethods,
            'technology_indicators' => $technologies,
        ];
    }

    private function metaContent(DOMXPath $xpath, string $name): ?string
    {
        $query = sprintf('//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="%s"]/@content', $name);
        $node = ($xpath->query($query) ?: null)?->item(0);
        $value = $node ? $this->boundedText($node->nodeValue ?? '', 500) : '';

        return $value === '' ? null : $value;
    }

    /** @return list<array{type: ?string, name: ?string, price: ?string, currency: ?string}> */
    private function structuredData(DOMDocument $document): array
    {
        $items = [];
        $limit = min(50, max(0, (int) config('website_intelligence.reports.max_structured_items', 50)));
        foreach ($document->getElementsByTagName('script') as $script) {
            if (count($items) >= $limit || strtolower(trim($script->getAttribute('type'))) !== 'application/ld+json') {
                continue;
            }
            $raw = trim($script->textContent);
            if ($raw === '' || strlen($raw) > 100000) {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                continue;
            }
            $candidates = array_is_list($decoded) ? $decoded : ($decoded['@graph'] ?? [$decoded]);
            foreach (is_array($candidates) ? $candidates : [] as $candidate) {
                if (! is_array($candidate) || count($items) >= $limit) {
                    continue;
                }
                $offer = is_array($candidate['offers'] ?? null) ? $candidate['offers'] : [];
                if (array_is_list($offer)) {
                    $offer = is_array($offer[0] ?? null) ? $offer[0] : [];
                }
                $items[] = [
                    'type' => $this->boundedScalar($candidate['@type'] ?? null, 100),
                    'name' => $this->boundedScalar($candidate['name'] ?? null, 300),
                    'price' => $this->boundedScalar($offer['price'] ?? $candidate['price'] ?? null, 100),
                    'currency' => $this->boundedScalar($offer['priceCurrency'] ?? $candidate['priceCurrency'] ?? null, 10),
                ];
            }
        }

        return $items;
    }

    /** @return list<string> */
    private function callsToAction(DOMXPath $xpath): array
    {
        $values = [];
        foreach ($xpath->query('//a|//button|//input[@type="submit"]') ?: [] as $node) {
            $text = $node instanceof DOMElement && $node->nodeName === 'input'
                ? $node->getAttribute('value')
                : $node->textContent;
            $text = $this->boundedText($text, 200);
            if ($text !== '' && preg_match('/\b(?:buy|shop|subscribe|sign up|register|book|contact|download|start|try|get started|request|learn more)\b/i', $text)) {
                $values[] = $text;
            }
            if (count($values) >= 50) {
                break;
            }
        }

        return array_values(array_unique($values));
    }

    /** @return list<string> */
    private function contactMethods(DOMDocument $document): array
    {
        $values = [];
        foreach ($document->getElementsByTagName('a') as $anchor) {
            $href = trim($anchor->getAttribute('href'));
            if (preg_match('/^(?:mailto|tel):[^\s<>]{1,300}$/i', $href)) {
                $values[] = $href;
            }
            if (count($values) >= 50) {
                break;
            }
        }

        return array_values(array_unique($values));
    }

    /** @return list<string> */
    private function textContacts(string $text): array
    {
        preg_match_all('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,63}\b/i', $text, $emails);
        preg_match_all('/(?<!\w)(?:\+?\d[\d .()-]{6,}\d)(?!\w)/', $text, $phones);

        return array_values(array_unique(array_slice([
            ...($emails[0] ?? []),
            ...array_map(static fn (string $phone): string => trim($phone), $phones[0] ?? []),
        ], 0, 50)));
    }

    /** @return list<string> */
    private function technologyIndicators(string $html, DOMXPath $xpath): array
    {
        $values = [];
        $generator = ($xpath->query('//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="generator"]/@content') ?: null)?->item(0);
        if ($generator !== null && ($value = $this->boundedText($generator->nodeValue ?? '', 200)) !== '') {
            $values[] = 'Generator: '.$value;
        }
        foreach (['WordPress' => '/wp-(?:content|includes)/i', 'Shopify' => '/cdn\.shopify\.com|Shopify\.theme/i', 'Next.js' => '/__NEXT_DATA__/', 'React' => '/data-reactroot|react-dom/i'] as $name => $pattern) {
            if (preg_match($pattern, $html)) {
                $values[] = $name;
            }
        }

        return array_values(array_unique(array_slice($values, 0, 20)));
    }

    /** @return list<string> */
    private function pricingSignals(string $text): array
    {
        preg_match_all('/(?:[$€£]\s?\d[\d,.]*|\b\d[\d,.]*\s?(?:USD|EUR|GBP)\b)/iu', $text, $matches);

        return array_values(array_unique(array_slice($matches[0] ?? [], 0, 50)));
    }

    private function boundedScalar(mixed $value, int $max): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $bounded = $this->boundedText((string) $value, $max);

        return $bounded === '' ? null : $bounded;
    }

    private function boundedText(string $text, ?int $max = null): string
    {
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $text));
        $limit = $max ?? max(1, (int) config('website_intelligence.budgets.max_text_bytes', 500000));

        return substr($normalized, 0, $limit);
    }

    private function safeLink(string $href): ?string
    {
        if ($href === '' || strlen($href) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $href)) {
            return null;
        }
        if (str_starts_with($href, '/') && ! str_starts_with($href, '//')) {
            return explode('#', $href, 2)[0];
        }
        $parts = parse_url($href);
        if (! is_array($parts) || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return explode('#', $href, 2)[0];
    }
}
