<?php

namespace App\Http\Controllers;

use App\Models\Url;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OwnerUrlController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(25, max(1, $request->integer('per_page', 10)));
        $urls = Url::query()
            ->owned($request->user())
            ->withSum('dailyAnalytics as total_redirects', 'redirect_count')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $urls->getCollection()
                ->map(fn (Url $url): array => $this->resource($url))
                ->values(),
            'meta' => [
                'current_page' => $urls->currentPage(),
                'last_page' => $urls->lastPage(),
                'per_page' => $urls->perPage(),
                'total' => $urls->total(),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function resource(Url $url): array
    {
        return [
            'id' => $url->id,
            'short_code' => $url->short_code,
            'short_url' => url('/'.$url->short_code),
            'long_url' => $url->long_url,
            'expires_at' => $url->expires_at?->utc()->toIso8601String(),
            'disabled_at' => $url->disabled_at?->utc()->toIso8601String(),
            'status' => $url->lifecycleState()->value,
            'origin' => $url->is_custom ? 'custom' : 'generated',
            'total_redirects' => (int) ($url->total_redirects ?? 0),
            'created_at' => $url->created_at?->utc()->toIso8601String(),
            'updated_at' => $url->updated_at?->utc()->toIso8601String(),
        ];
    }
}
