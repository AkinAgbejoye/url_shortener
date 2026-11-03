<?php

namespace App\Data;

final readonly class CrawlFetchResult
{
    public function __construct(public int $status, public string $contentType, public string $body) {}
}
