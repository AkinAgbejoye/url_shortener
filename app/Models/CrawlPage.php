<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrawlPage extends Model
{
    protected $fillable = ['crawl_job_id', 'public_id', 'url', 'url_hash', 'canonical_hash', 'depth', 'status_code', 'bytes', 'content_hash', 'evidence_snapshot', 'outcome', 'fetched_at'];

    protected $hidden = ['url', 'url_hash', 'canonical_hash'];

    protected function casts(): array
    {
        return ['url' => 'encrypted', 'evidence_snapshot' => 'array', 'fetched_at' => 'immutable_datetime'];
    }
}
