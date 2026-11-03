<?php

namespace App\Contracts;

use App\Data\CrawlFetchResult;

interface CrawlPageFetcher
{
    public function fetch(string $url): CrawlFetchResult;
}
