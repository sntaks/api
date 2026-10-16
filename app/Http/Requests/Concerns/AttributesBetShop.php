<?php

namespace App\Http\Requests\Concerns;

use App\Models\BetShop;
use Closure;

/**
 * The optional `bet_shop_no` a stake or competition entry is sent with when it is made at a bet shop. It
 * must name an active shop; it is stored as `bet_shop_id` on the stake.
 */
trait AttributesBetShop
{
    /**
     * @return array<int, mixed>
     */
    protected function betShopNoRules(): array
    {
        return ['nullable', 'string', 'max:10', function (string $attribute, mixed $value, Closure $fail) {
            $shop = BetShop::where('shop_no', strtoupper(trim((string) $value)))->first();

            if (! $shop) {
                $fail('No bet shop has that number.');
            } elseif (! $shop->isActive()) {
                $fail('The bet shop is not active.');
            }
        }];
    }

    /**
     * The validated stake, with `bet_shop_no` swapped for the shop's `bet_shop_id` (null when not sent).
     *
     * @return array<string, mixed>
     */
    public function stakeData(): array
    {
        $data = $this->validated();
        $shopNo = $data['bet_shop_no'] ?? null;
        unset($data['bet_shop_no']);

        return $data + [
            'bet_shop_id' => blank($shopNo) ? null : BetShop::where('shop_no', strtoupper(trim($shopNo)))->value('id'),
        ];
    }
}
