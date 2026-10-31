<?php

namespace App\Services;

use App\Models\LedgerEntry;
use App\Models\Wager;
use App\Models\WagerSettlement;
use App\Models\WinningsTaxCharge;

/**
 * Keeps the winnings tax owed to KRA: one charge row per taxed settlement, and a credit row when that
 * settlement is reversed. Written by WagerService in the same transaction as the winnings_tax ledger entry.
 */
class WinningsTaxService
{
    /**
     * The tax withheld by a settlement, from its winnings_tax ledger entry.
     */
    public function recordCharge(WagerSettlement $settlement, Wager $wager, LedgerEntry $entry): WinningsTaxCharge
    {
        return $this->record($settlement, $wager, $entry, WinningsTaxCharge::KIND_CHARGE, (float) $entry->debit);
    }

    /**
     * The tax given back when a taxed settlement is reversed: a negative row, whatever happened to the charge.
     * A charge already paid to KRA stays on its remittance; the credit lowers the next return instead.
     */
    public function recordReversal(WagerSettlement $settlement, Wager $wager, LedgerEntry $entry): WinningsTaxCharge
    {
        return $this->record($settlement, $wager, $entry, WinningsTaxCharge::KIND_REVERSAL, -(float) $entry->credit);
    }

    private function record(WagerSettlement $settlement, Wager $wager, LedgerEntry $entry, string $kind, float $amount): WinningsTaxCharge
    {
        return WinningsTaxCharge::create([
            'wager_settlement_id' => $settlement->id,
            'kind' => $kind,
            'wager_id' => $wager->id,
            'product_id' => $wager->product_id,
            'customer_id' => $entry->customer_id,
            'wallet_id' => $entry->wallet_id,
            'ledger_entry_id' => $entry->id,
            'tax_base' => $settlement->tax_base,
            'rate' => $settlement->tax_rate,
            'amount' => $amount,
            'charged_at' => now(),
        ]);
    }
}
