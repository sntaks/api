<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Range, paging and filters for the drill-down reports. Each report reads only the filters that
 * apply to it.
 */
class FinanceListRequest extends FinanceReportRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('finance.lists.max_per_page')],
            'status' => ['nullable', 'string', 'max:20'],
            'kind' => ['nullable', 'string', 'max:30'],
            'type' => ['nullable', 'string', 'max:30'],
            'entry_type' => ['nullable', 'string', 'max:50'],
            'category' => ['nullable', 'string', 'max:30'],
            'wallet_type' => ['nullable', 'in:wallet,game_wallet,competition_wallet,coin_wallet,dispute,referral,bet_shop,shop_withdrawal,wager'],
            'wallet_id' => ['nullable', 'integer', 'min:1'],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'bet_shop_id' => ['nullable', 'integer', 'min:1'],
            'game_type' => ['nullable', 'in:1,2'],
            'jp_rounds' => ['nullable', 'integer', 'min:0'],
            'outcome' => ['nullable', 'in:open,completed,dropped,refunded,closed_empty'],
            'players' => ['nullable', 'integer', 'min:1', 'max:20'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', 'in:net_gaming,deposited,staked,won,withdrawn,balance,shop_loaded,shop_withdrawn'],
            'idle_minutes' => ['nullable', 'integer', 'min:0', 'max:525600'],
        ];
    }

    public function page(): int
    {
        return max(1, (int) $this->input('page', 1));
    }

    public function perPage(): int
    {
        return (int) $this->input('per_page', config('finance.lists.default_per_page'));
    }

    /**
     * The filters that were actually sent.
     *
     * @return array<string, string>
     */
    public function filters(): array
    {
        return array_filter(
            $this->only([
                'status', 'kind', 'type', 'entry_type', 'category', 'wallet_type', 'wallet_id', 'customer_id', 'bet_shop_id',
                'game_type', 'jp_rounds', 'outcome', 'players', 'limit', 'sort', 'idle_minutes',
            ]),
            fn ($value) => $value !== null && $value !== ''
        );
    }
}
