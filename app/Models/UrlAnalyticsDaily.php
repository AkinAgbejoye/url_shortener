<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UrlAnalyticsDaily extends Model
{
    protected $table = 'url_analytics_daily';

    protected $fillable = [
        'url_id',
        'date',
        'redirect_count',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'redirect_count' => 'integer',
        ];
    }

    /** @return BelongsTo<Url, $this> */
    public function url(): BelongsTo
    {
        return $this->belongsTo(Url::class);
    }
}
