<?php

namespace App\Services;

use App\Models\ApiKey;
use App\Models\Game;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Full, site and game keys: issue, revoke and present them. Shop keys are issued by BetShopService.
 */
class ApiKeyService
{
    /**
     * Full keys keep their original 32-character format. Site and game keys carry their scope as a
     * prefix, like shop keys, so a leaked key says what it can reach.
     *
     * @return array{api_key: ApiKey, plain_key: string}
     */
    public function issue(string $name, string $scope, ?Game $game = null): array
    {
        $plainKey = match ($scope) {
            ApiKey::SCOPE_SITE => 'site_'.Str::random(40),
            ApiKey::SCOPE_GAME => 'game_'.Str::random(40),
            default => Str::random(32),
        };

        $apiKey = ApiKey::create([
            'key' => $plainKey,
            'name' => $name,
            'scope' => $scope,
            'game_id' => $scope === ApiKey::SCOPE_GAME ? $game?->id : null,
            'is_active' => true,
        ]);

        return ['api_key' => $apiKey, 'plain_key' => $plainKey];
    }

    /**
     * Safe to call twice: a revoked key keeps who revoked it and when.
     */
    public function revoke(ApiKey $apiKey, ?string $actor): ApiKey
    {
        if ($apiKey->is_active) {
            $apiKey->update(['is_active' => false, 'revoked_at' => now(), 'revoked_by' => $actor]);
            Cache::forget(ApiKey::multiplierCacheKey($apiKey->key));
        }

        return $apiKey->fresh();
    }

    /**
     * Never includes the key itself.
     *
     * @return array<string, mixed>
     */
    public function present(ApiKey $apiKey): array
    {
        return [
            'id' => $apiKey->id,
            'name' => $apiKey->name,
            'scope' => $apiKey->scope,
            'game' => $apiKey->game?->code,
            'bet_shop_no' => $apiKey->betShop?->shop_no,
            'is_active' => $apiKey->is_active,
            'rate_limit_multiplier' => $apiKey->rate_limit_multiplier,
            'revoked_at' => $apiKey->revoked_at?->toIso8601String(),
            'revoked_by' => $apiKey->revoked_by,
            'created_at' => $apiKey->created_at?->toIso8601String(),
        ];
    }
}
