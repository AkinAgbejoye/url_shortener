<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteImportAsset extends Model
{
    protected $fillable = ['site_import_id', 'public_id', 'source_path_hash', 'media_type', 'bytes', 'content_hash', 'content', 'scan_result'];

    protected $hidden = ['source_path_hash', 'content'];

    protected function casts(): array
    {
        return ['content' => 'encrypted', 'scan_result' => 'array'];
    }

    /** @return BelongsTo<SiteImport, $this> */
    public function siteImport(): BelongsTo
    {
        return $this->belongsTo(SiteImport::class);
    }
}
