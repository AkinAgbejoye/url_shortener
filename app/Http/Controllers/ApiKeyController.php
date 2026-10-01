<?php

namespace App\Http\Controllers;

use App\Http\Requests\RevokeApiKeyRequest;
use App\Http\Requests\StoreApiKeyRequest;
use App\Models\ApiKey;
use App\Services\ApiKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ApiKeyController extends Controller
{
    public function __construct(private readonly ApiKeyService $apiKeys) {}

    public function index(Request $request): JsonResponse
    {
        $maxPerPage = (int) config('url_shortener.api_keys.max_per_page', 25);
        $defaultPerPage = (int) config('url_shortener.api_keys.per_page', 10);
        $perPage = min($maxPerPage, max(1, $request->integer('per_page', $defaultPerPage)));
        $keys = ApiKey::query()
            ->whereBelongsTo($request->user())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $keys->getCollection()
                ->map(fn (ApiKey $apiKey): array => $this->resource($apiKey))
                ->values(),
            'meta' => [
                'current_page' => $keys->currentPage(),
                'last_page' => $keys->lastPage(),
                'per_page' => $keys->perPage(),
                'total' => $keys->total(),
                'allowed_scopes' => config('url_shortener.api_keys.scopes'),
                'limits' => [
                    'max_active_per_user' => config('url_shortener.api_keys.max_active_per_user'),
                    'name_max_length' => config('url_shortener.api_keys.name_max_length'),
                    'max_expiration_days' => config('url_shortener.api_keys.max_expiration_days'),
                ],
            ],
        ]);
    }

    public function store(StoreApiKeyRequest $request): JsonResponse
    {
        try {
            $created = $this->apiKeys->create(
                $request->user(),
                trim((string) $request->validated('name')),
                $request->scopes(),
                $request->validated('expires_at'),
            );
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'api_key' => $this->resource($created['api_key']),
            'plain_text_key' => $created['plain_text_key'],
            'message' => 'Copy this API key now. You will not be able to view it again.',
        ], 201);
    }

    public function destroy(RevokeApiKeyRequest $request, ApiKey $apiKey): JsonResponse
    {
        abort_unless($apiKey->user_id === $request->user()->id, 404);

        return response()->json([
            'api_key' => $this->resource($this->apiKeys->revoke($apiKey)),
            'message' => 'API key revoked.',
        ]);
    }

    /** @return array<string, mixed> */
    private function resource(ApiKey $apiKey): array
    {
        return [
            'id' => $apiKey->id,
            'name' => $apiKey->name,
            'public_id' => $apiKey->public_id,
            'scopes' => $apiKey->scopes,
            'status' => $apiKey->status(),
            'expires_at' => $apiKey->expires_at?->utc()->toIso8601String(),
            'last_used_at' => $apiKey->last_used_at?->utc()->toIso8601String(),
            'revoked_at' => $apiKey->revoked_at?->utc()->toIso8601String(),
            'created_at' => $apiKey->created_at?->utc()->toIso8601String(),
            'updated_at' => $apiKey->updated_at?->utc()->toIso8601String(),
        ];
    }
}
