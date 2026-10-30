<?php

namespace App\Services;

use App\Models\LedgerEntry;

/**
 * Maps ledger entries onto the accounts and categories in config/finance.php, so every
 * report classifies money the same way.
 */
class ChartOfAccounts
{
    public const UNCATEGORISED = 'uncategorised';

    private const REVERSAL_SUFFIX = '_reversal';

    public function isReversal(string $entryType): bool
    {
        return str_ends_with($entryType, self::REVERSAL_SUFFIX);
    }

    /**
     * The reporting category of an entry type. A reversal takes the category of the entry it reverses.
     */
    public function categoryFor(string $entryType): string
    {
        $baseType = $this->isReversal($entryType)
            ? substr($entryType, 0, -strlen(self::REVERSAL_SUFFIX))
            : $entryType;

        return config("finance.entry_types.{$baseType}", self::UNCATEGORISED);
    }

    /**
     * Every entry type that reports under a category, including the reversal of each.
     *
     * @return list<string>
     */
    public function entryTypesFor(string $category): array
    {
        $types = array_keys(array_filter(config('finance.entry_types'), fn (string $value) => $value === $category));

        return array_merge($types, array_map(fn (string $type) => $type.self::REVERSAL_SUFFIX, $types));
    }

    /**
     * The account a wallet belongs to. The house wallet is kept apart from customer wallets.
     */
    public function accountFor(string $walletType, int $walletId): string
    {
        return match ($walletType) {
            LedgerEntry::WALLET_TYPE_GAME => 'game_escrow',
            LedgerEntry::WALLET_TYPE_COMPETITION => 'competition_escrow',
            LedgerEntry::WALLET_TYPE_COIN => 'coin_wallets',
            LedgerEntry::WALLET_TYPE_DISPUTE => 'disputed_funds',
            LedgerEntry::WALLET_TYPE_REFERRAL => 'referral_wallets',
            LedgerEntry::WALLET_TYPE_BET_SHOP => 'bet_shop_floats',
            LedgerEntry::WALLET_TYPE_SHOP_WITHDRAWAL => 'shop_withdrawals',
            LedgerEntry::WALLET_TYPE_WAGER => 'open_wagers',
            default => $walletId === (int) config('wallets.house_wallet_id', 1) ? 'house_wallet' : 'customer_wallets',
        };
    }

    /**
     * @return array{label: string, type: string}|null
     */
    public function account(string $account): ?array
    {
        return config("finance.accounts.{$account}");
    }
}
