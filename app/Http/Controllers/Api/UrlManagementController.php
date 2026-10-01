<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShowUrlAnalyticsRequest;
use App\Http\Requests\UpdateUrlExpirationRequest;
use App\Models\Url;
use App\Services\UrlAnalyticsQuery;
use App\Services\UrlManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class UrlManagementController extends Controller
{
    public function analytics(
        string $shortCode,
        ShowUrlAnalyticsRequest $request,
        UrlAnalyticsQuery $analytics,
    ): JsonResponse {
        return response()->json($analytics->forUrl(
            $shortCode,
            $request->managementToken(),
            $request->rangeDays(),
            $request->user(),
        ));
    }

    public function show(string $shortCode, Request $request, UrlManagementService $manager): JsonResponse
    {
        return response()->json($this->resource(
            $manager->inspect($shortCode, $this->token($request), $request->user()),
        ));
    }

    public function update(
        string $shortCode,
        UpdateUrlExpirationRequest $request,
        UrlManagementService $manager,
    ): JsonResponse {
        return response()->json($this->resource($manager->updateExpiration(
            $shortCode,
            $request->managementToken(),
            $request->expiresAt(),
            $request->user(),
        )));
    }

    public function disable(string $shortCode, Request $request, UrlManagementService $manager): JsonResponse
    {
        return response()->json($this->resource(
            $manager->disable($shortCode, $this->token($request), $request->user()),
        ));
    }

    public function enable(string $shortCode, Request $request, UrlManagementService $manager): JsonResponse
    {
        return response()->json($this->resource(
            $manager->enable($shortCode, $this->token($request), $request->user()),
        ));
    }

    public function destroy(string $shortCode, Request $request, UrlManagementService $manager): Response
    {
        $manager->delete($shortCode, $this->token($request), $request->user());

        return response()->noContent();
    }

    public function claim(string $shortCode, Request $request, UrlManagementService $manager): JsonResponse
    {
        return response()->json($this->resource(
            $manager->claim($shortCode, $this->token($request), $request->user()),
        ));
    }

    private function token(Request $request): ?string
    {
        $token = $request->header('X-Management-Token');

        return is_string($token) ? $token : null;
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
            'created_at' => $url->created_at?->utc()->toIso8601String(),
            'updated_at' => $url->updated_at?->utc()->toIso8601String(),
        ];
    }
}
