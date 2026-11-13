<?php

namespace App\Http\Requests\Predict;

use App\Http\Requests\Concerns\FailsWithApiError;
use App\Http\Requests\PagedListRequest;
use App\Models\PredictBet;

/**
 * Filters for a player's prediction bets. `settled` is every bet with a final result: won, lost or void.
 */
class ListPredictBetsRequest extends PagedListRequest
{
    use FailsWithApiError;

    public const STATUS_SETTLED = 'settled';

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'status' => ['nullable', 'in:'.implode(',', array_keys(self::statusGroups()))],
        ];
    }

    /**
     * The bet statuses the `status` filter stands for, or null without a filter.
     *
     * @return list<string>|null
     */
    public function statuses(): ?array
    {
        return self::statusGroups()[$this->validated('status')] ?? null;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function statusGroups(): array
    {
        return [
            PredictBet::STATUS_OPEN => [PredictBet::STATUS_OPEN],
            PredictBet::STATUS_WON => [PredictBet::STATUS_WON],
            PredictBet::STATUS_LOST => [PredictBet::STATUS_LOST],
            PredictBet::STATUS_VOID => [PredictBet::STATUS_VOID],
            self::STATUS_SETTLED => [PredictBet::STATUS_WON, PredictBet::STATUS_LOST, PredictBet::STATUS_VOID],
        ];
    }
}
