<?php

namespace App\Http\Resources;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\NewAccessToken;

/** @mixin NewAccessToken */
class AuthTokenResource extends JsonResource
{
    public static $wrap = null;

    public function withResponse(Request $request, JsonResponse $response): void
    {
        $response->header('Cache-Control', 'no-store');
    }

    public function toArray(Request $request): array
    {
        return [
            'user' => new UserResource($this->accessToken->tokenable),
            'token' => (string) $this->plainTextToken,
            'token_type' => 'Bearer',
            /** @format date-time */
            'expires_at' => (string) $this->accessToken->expires_at->toISOString(),
        ];
    }
}
