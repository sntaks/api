<?php

namespace App\Services;

use App\Models\ApiKey;
use App\Models\BetShop;
use App\Models\BetShopWallet;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Onboarding and running bet shops from GMS: the shop record and its float wallet, its status, and the
 * API keys its shop system uses. A shop key only works on /v1/shop/* and always acts for its own shop.
 */
class BetShopService
{
    public const CODE_FLOAT_NOT_EMPTY = 'float_not_empty';

    public const CODE_SHOP_CLOSED = 'shop_closed';

    /**
     * @param  array{shop_no: string, name: string, owner_name: string, owner_phone: string, location?: ?string, notes?: ?string}  $data
     */
    public function create(array $data, ?string $actor): BetShop
    {
        return DB::transaction(function () use ($data, $actor) {
            $shop = BetShop::create([
                'shop_no' => strtoupper($data['shop_no']),
                'name' => $data['name'],
                'owner_name' => $data['owner_name'],
                'owner_phone' => $data['owner_phone'],
                'location' => $data['location'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => BetShop::STATUS_ACTIVE,
                'created_by' => $actor,
            ]);

            BetShopWallet::create(['bet_shop_id' => $shop->id, 'balance' => 0]);

            return $shop->load('wallet');
        });
    }

    /**
     * @param  array<string, mixed>  $data  Any of name, owner_name, owner_phone, location, notes.
     */
    public function update(BetShop $shop, array $data): BetShop
    {
        $shop->update(array_intersect_key($data, array_flip(['name', 'owner_name', 'owner_phone', 'location', 'notes'])));

        return $shop->fresh('wallet');
    }

    /**
     * Suspend, reactivate or close a shop. A closed shop is final, and a shop can only be closed once its
     * float is empty (redeem or move what is left first).
     *
     * @return array{success: bool, message: string, status_code: int, shop?: BetShop, code?: string}
     */
    public function changeStatus(BetShop $shop, string $status, ?string $reason, ?string $actor): array
    {
        return DB::transaction(function () use ($shop, $status, $reason, $actor) {
            $shop = BetShop::lockForUpdate()->findOrFail($shop->id);

            if ($shop->status === BetShop::STATUS_CLOSED) {
                return ['success' => false, 'message' => 'The shop is closed', 'status_code' => 409, 'code' => self::CODE_SHOP_CLOSED, 'shop' => $shop];
            }

            if ($status === BetShop::STATUS_CLOSED) {
                $float = BetShopWallet::lockForUpdate()->where('bet_shop_id', $shop->id)->first();

                if ($float && round((float) $float->balance, 2) !== 0.0) {
                    return ['success' => false, 'message' => 'The shop still holds float, so it cannot be closed', 'status_code' => 409, 'code' => self::CODE_FLOAT_NOT_EMPTY, 'shop' => $shop];
                }
            }

            $shop->update(['status' => $status, 'status_reason' => $reason]);

            if ($status === BetShop::STATUS_CLOSED) {
                $this->revokeAllKeys($shop, $actor);
            }

            Log::info('Bet shop status changed', ['bet_shop_id' => $shop->id, 'status' => $status, 'reason' => $reason, 'by' => $actor]);

            return ['success' => true, 'message' => "Shop {$status}", 'status_code' => 200, 'shop' => $shop->fresh('wallet')];
        });
    }

    /**
     * Issue a new key for the shop's system. The key is returned once and never shown again.
     *
     * @return array{success: bool, message: string, status_code: int, api_key?: ApiKey, plain_key?: string, code?: string}
     */
    public function issueKey(BetShop $shop, string $name): array
    {
        if ($shop->status === BetShop::STATUS_CLOSED) {
            return ['success' => false, 'message' => 'The shop is closed', 'status_code' => 409, 'code' => self::CODE_SHOP_CLOSED];
        }

        $plainKey = 'shop_'.Str::random(40);

        $apiKey = ApiKey::create([
            'key' => $plainKey,
            'name' => $name,
            'scope' => ApiKey::SCOPE_SHOP,
            'bet_shop_id' => $shop->id,
            'is_active' => true,
        ]);

        return ['success' => true, 'message' => 'Key issued. Store it now: it is not shown again.', 'status_code' => 201, 'api_key' => $apiKey, 'plain_key' => $plainKey];
    }

    /**
     * @return array{success: bool, message: string, status_code: int, api_key?: ApiKey}
     */
    public function revokeKey(int $keyId, ?string $actor): array
    {
        $apiKey = ApiKey::where('scope', ApiKey::SCOPE_SHOP)->find($keyId);

        if (! $apiKey) {
            return ['success' => false, 'message' => 'Shop key not found', 'status_code' => 404];
        }

        if ($apiKey->is_active) {
            $apiKey->update(['is_active' => false, 'revoked_at' => now(), 'revoked_by' => $actor]);
        }

        return ['success' => true, 'message' => 'Key revoked', 'status_code' => 200, 'api_key' => $apiKey->fresh()];
    }

    /**
     * @param  array{status?: ?string}  $filters
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return BetShop::with('wallet')
            ->withCount(['apiKeys as active_keys' => fn ($query) => $query->where('is_active', true)])
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->orderBy('shop_no')
            ->paginate($perPage);
    }

    /**
     * @return array<string, mixed>
     */
    public function present(BetShop $shop): array
    {
        return [
            'id' => $shop->id,
            'shop_no' => $shop->shop_no,
            'float_bill_ref' => config('bet_shops.bill_ref_prefix').$shop->shop_no,
            'name' => $shop->name,
            'owner_name' => $shop->owner_name,
            'owner_phone' => $shop->owner_phone,
            'location' => $shop->location,
            'status' => $shop->status,
            'status_reason' => $shop->status_reason,
            'notes' => $shop->notes,
            'float_balance' => round((float) ($shop->wallet?->balance ?? 0), 2),
            'active_keys' => $shop->active_keys ?? $shop->apiKeys()->where('is_active', true)->count(),
            'created_by' => $shop->created_by,
            'created_at' => $shop->created_at?->toIso8601String(),
            'updated_at' => $shop->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentKey(ApiKey $apiKey): array
    {
        return [
            'id' => $apiKey->id,
            'name' => $apiKey->name,
            'bet_shop_id' => $apiKey->bet_shop_id,
            'key_hint' => '…'.substr((string) $apiKey->key, -4),
            'is_active' => (bool) $apiKey->is_active,
            'created_at' => $apiKey->created_at?->toIso8601String(),
            'revoked_at' => $apiKey->revoked_at?->toIso8601String(),
            'revoked_by' => $apiKey->revoked_by,
        ];
    }

    private function revokeAllKeys(BetShop $shop, ?string $actor): void
    {
        $shop->apiKeys()->where('is_active', true)->update(['is_active' => false, 'revoked_at' => now(), 'revoked_by' => $actor]);
    }
}
