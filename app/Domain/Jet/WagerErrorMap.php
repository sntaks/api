<?php

namespace App\Domain\Jet;

use App\Services\WagerResult;

/**
 * Turns a refused wager into the M1 error the Jet client already knows. Unknown codes are a server error:
 * the command rolled back, so the client reconciles with the same operation key.
 */
class WagerErrorMap
{
    private const CODES = [
        'insufficient_funds' => ['INSUFFICIENT_FUNDS', 'correct_request'],
        'stake_below_limit' => ['LIMIT_EXCEEDED', 'correct_request'],
        'stake_above_limit' => ['LIMIT_EXCEEDED', 'correct_request'],
        'payout_above_limit' => ['LIMIT_EXCEEDED', 'correct_request'],
        'deposit_limit' => ['LIMIT_EXCEEDED', 'correct_request'],
        'player_excluded' => ['SELF_EXCLUDED', 'correct_request'],
        'player_inactive' => ['PLAYER_DISABLED', 'correct_request'],
        'player_suspended' => ['PLAYER_DISABLED', 'correct_request'],
        'product_not_active' => ['FORBIDDEN', 'correct_request'],
        'product_not_allowed' => ['FORBIDDEN', 'correct_request'],
        'round_closed' => ['ROUND_CLOSED', 'reconcile'],
        'reference_conflict' => ['IDEMPOTENCY_CONFLICT', 'correct_request'],
        'wager_not_open' => ['ALREADY_SETTLED', 'reconcile'],
        'wager_voided' => ['ALREADY_SETTLED', 'reconcile'],
    ];

    public static function toException(WagerResult $result): JetException
    {
        $refusal = $result->refusal ?? ['code' => 'server_error', 'status' => 500, 'extra' => []];
        [$code, $retry] = self::CODES[$refusal['code']] ?? ['SERVER_ERROR', 'reconcile'];
        $status = $code === 'SERVER_ERROR' ? 500 : (int) $refusal['status'];

        return new JetException($code, $status, $retry, $refusal['extra'] ?? []);
    }
}
