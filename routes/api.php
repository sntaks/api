<?php

use App\Http\Controllers\api\v1\ApiKeyController;
use App\Http\Controllers\api\v1\B2CBalanceController;
use App\Http\Controllers\api\v1\B2CBalanceTimeoutController;
use App\Http\Controllers\api\v1\B2Controller;
use App\Http\Controllers\api\v1\B2CPaymentController;
use App\Http\Controllers\api\v1\B2CResultController;
use App\Http\Controllers\api\v1\B2CTimeOutController;
use App\Http\Controllers\api\v1\BetShopController;
use App\Http\Controllers\api\v1\BetShopFloatController;
use App\Http\Controllers\api\v1\BetShopLoadController;
use App\Http\Controllers\api\v1\C2BBalanceResultController;
use App\Http\Controllers\api\v1\C2BBalanceTimeoutController;
use App\Http\Controllers\api\v1\CoinBuyController;
use App\Http\Controllers\api\v1\CoinController;
use App\Http\Controllers\api\v1\CoinExchangeController;
use App\Http\Controllers\api\v1\CoinTransferController;
use App\Http\Controllers\api\v1\CompetitionTransactionController;
use App\Http\Controllers\api\v1\CompetitionWalletController;
use App\Http\Controllers\api\v1\CompetitionWalletTransferPayoutController;
use App\Http\Controllers\api\v1\CompetitionWalletWithdrawController;
use App\Http\Controllers\api\v1\ComplaintController;
use App\Http\Controllers\api\v1\ConfirmationController;
use App\Http\Controllers\api\v1\CustomerController;
use App\Http\Controllers\api\v1\CustomerPromotionController;
use App\Http\Controllers\api\v1\CustomerStatusController;
use App\Http\Controllers\api\v1\DecryptIdentifierController;
use App\Http\Controllers\api\v1\DepositController;
use App\Http\Controllers\api\v1\DropConnectionController;
use App\Http\Controllers\api\v1\EmailVerifiedController;
use App\Http\Controllers\api\v1\EncryptIdentifierController;
use App\Http\Controllers\api\v1\ExciseDutyController;
use App\Http\Controllers\api\v1\FinanceDrilldownController;
use App\Http\Controllers\api\v1\FinanceExpenseController;
use App\Http\Controllers\api\v1\FinanceExportController;
use App\Http\Controllers\api\v1\FinanceReportController;
use App\Http\Controllers\api\v1\GameCatalogController;
use App\Http\Controllers\api\v1\GameCreditController;
use App\Http\Controllers\api\v1\GameRefundController;
use App\Http\Controllers\api\v1\GameTransactionController;
use App\Http\Controllers\api\v1\GameWalletController;
use App\Http\Controllers\api\v1\GameWalletWithdrawController;
use App\Http\Controllers\api\v1\HouseFundingController;
use App\Http\Controllers\api\v1\LaunchTokenController;
use App\Http\Controllers\api\v1\PlayerSessionController;
use App\Http\Controllers\api\v1\PlaygroundController;
use App\Http\Controllers\api\v1\ProductController;
use App\Http\Controllers\api\v1\PromoCodeController;
use App\Http\Controllers\api\v1\PurchaseController;
use App\Http\Controllers\api\v1\ReferralB2CBalanceController;
use App\Http\Controllers\api\v1\ReferralB2CBalanceTimeoutController;
use App\Http\Controllers\api\v1\ReferralB2CResultController;
use App\Http\Controllers\api\v1\ReferralB2CTimeoutController;
use App\Http\Controllers\api\v1\ReferralController;
use App\Http\Controllers\api\v1\ReferralWithdrawalController;
use App\Http\Controllers\api\v1\RegisterC2BUrlsController;
use App\Http\Controllers\api\v1\ResponsibleGamblingController;
use App\Http\Controllers\api\v1\ShopController;
use App\Http\Controllers\api\v1\ShopWithdrawalController;
use App\Http\Controllers\api\v1\StatsController;
use App\Http\Controllers\api\v1\StkCallbackController;
use App\Http\Controllers\api\v1\StkDepositController;
use App\Http\Controllers\api\v1\StkLoadController;
use App\Http\Controllers\api\v1\TransactionController;
use App\Http\Controllers\api\v1\UnmatchedDepositController;
use App\Http\Controllers\api\v1\ValidationController;
use App\Http\Controllers\api\v1\WagerAdminController;
use App\Http\Controllers\api\v1\WagerController;
use App\Http\Controllers\api\v1\WagerRoundController;
use App\Http\Controllers\api\v1\WalletController;
use App\Http\Controllers\api\v1\WalletTransactionController;
use App\Http\Controllers\api\v1\WalletTransferController;
use App\Http\Controllers\api\v1\WinningsTaxController;
use App\Http\Controllers\api\v1\WithdrawalApprovalController;
use App\Http\Controllers\api\v1\WithdrawalController;
use App\Http\Controllers\api\v1\WithdrawController;
use Illuminate\Support\Facades\Route;

Route::prefix('/v1')->group(function () {
    Route::middleware(['apikey.checker', 'log.requests'])->group(function () {
        Route::get('/customers', [CustomerController::class, 'index']);
        Route::post('/customers', [CustomerController::class, 'store'])->middleware(['idempotency', 'throttle:write']);
        Route::get('/customers/search', [CustomerController::class, 'search']);
        Route::post('/customers/referrals', [CustomerController::class, 'customersReferrals']);
        // Customer Leaderboard Route
        Route::post('/customers/leaderboard', [CustomerController::class, 'customer_leaderboard']);
        Route::post('/customers/combined-leaderboard', [CustomerController::class, 'combined_leaderboard']);

        Route::get('/wallets', [WalletController::class, 'index']);

        Route::get('/wallets/transactions', [WalletTransactionController::class, 'index']);
        Route::get('/wallets/today', [WalletTransactionController::class, 'showDailyAmount']);

        Route::get('/coins', [CoinController::class, 'index']);

        Route::get('/purchases', [PurchaseController::class, 'index']);
        Route::post('/purchases/referrals', [PurchaseController::class, 'referralPurchases']);

        Route::get('/deposits', [DepositController::class, 'index']);
        Route::post('/deposits', [DepositController::class, 'store'])->middleware(['idempotency', 'throttle:write']);
        // Unmatched deposits (account number matched no customer)
        Route::get('/deposits/unmatched', [UnmatchedDepositController::class, 'index']);
        Route::post('/deposits/unmatched/match', [UnmatchedDepositController::class, 'match'])->middleware(['idempotency', 'throttle:write']);

        Route::get('/withdraws', [WithdrawController::class, 'index']);

        Route::get('/game/wallets', [GameWalletController::class, 'index']);
        Route::post('/game/wallets', [GameWalletController::class, 'store'])->middleware(['idempotency', 'throttle:write']);

        Route::get('/game/results', [GameWalletController::class, 'game_results']);

        Route::get('/game/bets', [GameTransactionController::class, 'index']);
        Route::post('/game/bets', [GameTransactionController::class, 'store'])->middleware(['idempotency', 'throttle:write']);

        Route::post('/game/credit', GameCreditController::class)->middleware(['idempotency', 'throttle:write']);

        Route::post('/game/refund', GameRefundController::class)->middleware(['idempotency', 'throttle:write']);

        Route::get('/competition/wallets', [CompetitionWalletController::class, 'index']);
        Route::post('/competition/wallets', [CompetitionWalletController::class, 'store'])->middleware(['idempotency', 'throttle:write']);

        Route::get('/competition/transactions', [CompetitionTransactionController::class, 'index']);
        Route::post('/competition/transactions', [CompetitionTransactionController::class, 'store'])->middleware(['idempotency', 'throttle:write']);

        // Competition Wallet Payout Transaction Route
        Route::post('/competition/payout', CompetitionWalletTransferPayoutController::class)->middleware(['idempotency', 'throttle:write']);

        // Customer referrals
        Route::get('/referrals', [ReferralController::class, 'index']);
        Route::get('/referrals/lookup', [ReferralController::class, 'lookup']);
        Route::get('/referral-withdrawals', [ReferralWithdrawalController::class, 'index']);

        // Promo codes for the signup bonus (GMS) and the signup screen lookup
        Route::get('/promo-codes', [PromoCodeController::class, 'index']);
        Route::post('/promo-codes', [PromoCodeController::class, 'store'])->middleware(['idempotency', 'throttle:write']);
        Route::get('/promo-codes/lookup', [PromoCodeController::class, 'lookup']);

        // Complaints and disputed transactions
        Route::get('/complaints', [ComplaintController::class, 'index']);
        Route::post('/complaints', [ComplaintController::class, 'store'])->middleware(['idempotency', 'throttle:write']);

        // Get B2C Balance
        Route::get('/b2c/balance', [B2Controller::class, 'index']);
        // Send from the main B2C shortcode to any phone (no wallet, no ledger)
        Route::post('/b2c/send', B2CPaymentController::class)->middleware(['throttle:financial', 'idempotency.required', 'idempotency']);

        // Bet shops (GMS)
        Route::get('/bet-shops', [BetShopController::class, 'index']);
        Route::post('/bet-shops', [BetShopController::class, 'store'])->middleware(['idempotency', 'throttle:write']);
        Route::get('/bet-shops/loads', [BetShopLoadController::class, 'index']);
        Route::get('/bet-shops/withdrawals', [ShopWithdrawalController::class, 'gmsIndex']);

        // Games and products: kadi's lobby lists the active games, GMS manages both
        Route::get('/games', [GameCatalogController::class, 'index']);
        Route::put('/games/{code}', [GameCatalogController::class, 'update'])->middleware('throttle:write');
        Route::get('/products', [ProductController::class, 'index']);
        Route::put('/products/{code}', [ProductController::class, 'update'])->middleware('throttle:write');

        // API keys (GMS): list every key, issue a game's keys (not idempotent on purpose: a stored response would keep the new key for 24 hours)
        Route::get('/api-keys', [ApiKeyController::class, 'index']);
        Route::post('/games/{code}/keys', [ApiKeyController::class, 'issueGameKey'])->middleware('throttle:write');

        // Withdrawals held for approval (GMS): list, approve (pays M-Pesa), reject (refunds)
        Route::get('/withdrawals/pending', [WithdrawalApprovalController::class, 'pending']);
        Route::get('/withdrawals/approvals', [WithdrawalApprovalController::class, 'index']);
        Route::post('/withdrawals/{id}/approve', [WithdrawalApprovalController::class, 'approve'])->middleware(['idempotency', 'throttle:write']);
        Route::post('/withdrawals/{id}/reject', [WithdrawalApprovalController::class, 'reject'])->middleware(['idempotency', 'throttle:write']);

        // Responsible gambling flags (GMS): deposits credited although the player's own rules would have refused them
        Route::get('/responsible-gambling/flags', [ResponsibleGamblingController::class, 'flags']);
        Route::post('/responsible-gambling/flags/{id}/resolve', [ResponsibleGamblingController::class, 'resolveFlag'])->middleware('throttle:write');

        // A game swaps the launch token kadi gave its player for a player token (game keys only; never idempotent: a stored response would replay a live token)
        Route::post('/launch-tokens/redeem', [LaunchTokenController::class, 'redeem'])->middleware('throttle:write');

        // A game server reading its player (game key + player token)
        Route::middleware('player.token')->group(function () {
            Route::get('/me', [PlayerSessionController::class, 'me']);
            Route::get('/me/balance', [PlayerSessionController::class, 'balance']);

            // Place a bet on a house-banked product: idempotent by (product, reference), not by the idempotency middleware
            Route::post('/wagers', [WagerController::class, 'store'])->middleware('throttle:wagers');
            // Bet and result in one call for games that resolve at once (slots, scratch cards); same natural-key idempotency
            Route::post('/wagers/instant', [WagerController::class, 'instant'])->middleware('throttle:wagers');
        });

        // Settle a bet (game key only: settlements happen after the player left). Idempotent by (product, reference, version), not by the idempotency middleware
        Route::post('/wagers/{reference}/settle', [WagerController::class, 'settle'])->middleware('throttle:wagers');

        // Take back a settlement and reopen the bet (game key only). Replay-safe by (product, reference, version), not by the idempotency middleware
        Route::post('/wagers/{reference}/reverse', [WagerController::class, 'reverse'])->middleware('throttle:wagers');

        // Settle or void every wager of a round together (game key only). Rerunnable: only open, never-settled wagers are touched
        Route::post('/rounds/{reference}/settle', [WagerRoundController::class, 'settle'])->middleware('throttle:wagers');
        Route::post('/rounds/{reference}/void', [WagerRoundController::class, 'void'])->middleware('throttle:wagers');

        // Reconcile one bet after a timeout (game key only)
        Route::get('/wagers/{reference}', [WagerController::class, 'show'])->middleware('throttle:wagers');

        // GMS (full key): search, one wager with its settlements and ledger lines, and void a stuck open bet. {id} is checked in the controller
        Route::get('/wagers', [WagerAdminController::class, 'index']);
        Route::get('/wagers/{id}/detail', [WagerAdminController::class, 'detail']);
        Route::post('/wagers/{id}/void', [WagerController::class, 'adminVoid'])->middleware('throttle:write');

        // Shop system: shop API keys only, always acting for the key's own shop
        Route::prefix('/shop')->middleware(['shop.key', 'throttle:shop'])->group(function () {
            Route::get('/me', [ShopController::class, 'me']);
            Route::get('/players/{playerNo}', [ShopController::class, 'player'])->middleware('throttle:shop-lookup');
            Route::post('/loads', [ShopController::class, 'storeLoad'])->middleware(['idempotency.required', 'idempotency']);
            Route::get('/loads', [ShopController::class, 'loads']);
            Route::get('/withdrawals', [ShopController::class, 'withdrawals']);
            Route::post('/withdrawals/{withdrawalId}/pay', [ShopController::class, 'payWithdrawal'])->middleware(['idempotency.required', 'idempotency']);
            Route::post('/withdrawals/{withdrawalId}/reject', [ShopController::class, 'rejectWithdrawal'])->middleware('idempotency');
        });

        // Stats routes
        Route::middleware('throttle:stats')->group(function () {
            Route::get('/stats/customers', [StatsController::class, 'customerStats']);
            Route::get('/stats/income', [StatsController::class, 'incomeStats']);
            Route::get('/stats/income/daily-30-days', [StatsController::class, 'dailyIncomeStats30Days']);
            Route::get('/stats/purchases', [StatsController::class, 'purchaseStats']);
            Route::get('/stats/played', [StatsController::class, 'playedStats']);
            Route::get('/stats/retention', [StatsController::class, 'retentionRates']);
            Route::post('/stats/purchases/referrals', [StatsController::class, 'purchaseReferralsStats']);
            Route::post('/stats/customers/referrals', [StatsController::class, 'customerReferralStats']);
            Route::post('/stats/customers/played', [StatsController::class, 'playedByPlayerStats']);
            Route::get('/stats/referrals', [ReferralController::class, 'programStats']);

            // Finance reports
            Route::prefix('/finance')->group(function () {
                Route::get('/summary', [FinanceReportController::class, 'summary']);
                Route::get('/income-statement', [FinanceReportController::class, 'incomeStatement']);
                Route::get('/house-banked', [FinanceReportController::class, 'houseBanked']);
                Route::get('/cash-flow', [FinanceReportController::class, 'cashFlow']);
                Route::get('/balance-sheet', [FinanceReportController::class, 'balanceSheet']);
                Route::get('/trial-balance', [FinanceReportController::class, 'trialBalance']);
                Route::get('/reconciliation', [FinanceReportController::class, 'reconciliation']);
                Route::get('/taxes', [FinanceReportController::class, 'taxes']);
                Route::get('/expenses', [FinanceDrilldownController::class, 'expenses']);

                // Excise duty on deposits
                Route::get('/excise-duty', [ExciseDutyController::class, 'summary']);
                Route::get('/excise-duty/charges', [ExciseDutyController::class, 'charges']);
                Route::get('/excise-duty/returns', [ExciseDutyController::class, 'returns']);
                Route::get('/excise-duty/remittances', [ExciseDutyController::class, 'remittances']);

                // Winnings tax on house-banked wins
                Route::get('/winnings-tax', [WinningsTaxController::class, 'summary']);
                Route::get('/winnings-tax/charges', [WinningsTaxController::class, 'charges']);
                Route::get('/winnings-tax/returns', [WinningsTaxController::class, 'returns']);
                Route::get('/winnings-tax/remittances', [WinningsTaxController::class, 'remittances']);

                // Drill-downs and CSV export
                Route::get('/deposits', [FinanceDrilldownController::class, 'deposits']);
                Route::get('/withdrawals', [FinanceDrilldownController::class, 'withdrawals']);
                Route::get('/purchases', [FinanceDrilldownController::class, 'purchases']);
                Route::get('/games', [FinanceDrilldownController::class, 'games']);
                Route::get('/competitions', [FinanceDrilldownController::class, 'competitions']);
                Route::get('/open-wallets', [FinanceDrilldownController::class, 'openWallets']);
                Route::get('/bet-shops', [FinanceDrilldownController::class, 'betShops']);
                Route::get('/bet-shops/loads', [FinanceDrilldownController::class, 'betShopLoads']);
                Route::get('/bet-shops/withdrawals', [FinanceDrilldownController::class, 'betShopWithdrawals']);
                Route::get('/ledger', [FinanceDrilldownController::class, 'ledger']);
                Route::get('/adjustments', [FinanceDrilldownController::class, 'adjustments']);
                Route::get('/disputes', [FinanceDrilldownController::class, 'disputes']);
                Route::get('/referrals', [FinanceDrilldownController::class, 'referrals']);
                Route::get('/referrals/bonuses', [FinanceDrilldownController::class, 'referralBonuses']);
                Route::get('/referrals/withdrawals', [FinanceDrilldownController::class, 'referralWithdrawals']);
                Route::get('/promotions', [FinanceDrilldownController::class, 'promotions']);
                Route::get('/house-funding', [FinanceDrilldownController::class, 'houseFunding']);
                Route::get('/customers/top', [FinanceDrilldownController::class, 'topCustomers']);
                Route::get('/export/{report}', FinanceExportController::class);
            });
        });

        // Finance: record an expense
        Route::post('/finance/expenses', [FinanceExpenseController::class, 'store'])->middleware(['idempotency', 'throttle:write']);
        Route::post('/finance/excise-duty/remittances', [ExciseDutyController::class, 'store'])->middleware(['idempotency', 'throttle:write']);
        Route::post('/finance/winnings-tax/remittances', [WinningsTaxController::class, 'store'])->middleware(['idempotency', 'throttle:write']);

        Route::get('/playground', [PlaygroundController::class, 'index']);
        Route::post('/playground', [PlaygroundController::class, 'store']);

        Route::middleware('decrypt.identifier')->group(function () {
            // Customer Routes
            Route::get('/customers/{encryptedIdentifier}', [CustomerController::class, 'show']);
            Route::put('/customers/{encryptedIdentifier}', [CustomerController::class, 'update']);
            Route::put('/customers/{encryptedIdentifier}/wallet', [CustomerController::class, 'update_wallet']);
            Route::delete('/customers/{encryptedIdentifier}', [CustomerController::class, 'destroy']);
            // Account status (GMS): set with a reason, and the history of changes
            Route::put('/customers/{encryptedIdentifier}/status', [CustomerStatusController::class, 'update'])->middleware(['idempotency', 'throttle:write']);
            Route::get('/customers/{encryptedIdentifier}/status-changes', [CustomerStatusController::class, 'index']);
            // Responsible gambling: kadi shows the player's state and starts a break (never ends one); GMS reads the history
            Route::get('/customers/{encryptedIdentifier}/responsible-gambling', [ResponsibleGamblingController::class, 'show']);
            Route::post('/customers/{encryptedIdentifier}/exclusion', [ResponsibleGamblingController::class, 'storeExclusion'])->middleware(['idempotency', 'throttle:write']);
            Route::get('/customers/{encryptedIdentifier}/exclusions', [ResponsibleGamblingController::class, 'exclusions']);
            Route::put('/customers/{encryptedIdentifier}/deposit-limits/{period}', [ResponsibleGamblingController::class, 'setDepositLimit'])->middleware(['idempotency', 'throttle:write']);
            // Customer Email Verification Routes
            Route::get('/customers/{encryptedIdentifier}/verify-email', EmailVerifiedController::class);
            // Customer Transactions Routes
            Route::post('/customers/transactions/{encryptedIdentifier}', [CustomerController::class, 'customer_transactions']);
            // Customer Games Played Route
            Route::get('/customers/played/{encryptedIdentifier}', [CustomerController::class, 'customer_played']);
            // Customer latest 10 games, tournaments and jackpots (for filing complaints)
            Route::get('/customers/played/recent/{encryptedIdentifier}', [CustomerController::class, 'customer_recent_played']);
            // Customer Referral Routes
            Route::get('/customers/{encryptedIdentifier}/referral-code', [ReferralController::class, 'showCode']);
            Route::put('/customers/{encryptedIdentifier}/referral-code', [ReferralController::class, 'saveCode'])->middleware('throttle:write');
            Route::post('/customers/{encryptedIdentifier}/referral/verified', [ReferralController::class, 'verified'])->middleware('throttle:write');
            // Customer verification (every player) and promotions
            Route::post('/customers/{encryptedIdentifier}/verified', [CustomerPromotionController::class, 'verified'])->middleware('throttle:write');
            Route::get('/customers/{encryptedIdentifier}/promotions', [CustomerPromotionController::class, 'index']);
            Route::post('/promo-codes/{encryptedIdentifier}/deactivate', [PromoCodeController::class, 'deactivate'])->middleware(['idempotency', 'throttle:write']);
            Route::get('/customers/{encryptedIdentifier}/referrals', [ReferralController::class, 'customerReferrals']);
            Route::get('/customers/{encryptedIdentifier}/referrals/stats', [ReferralController::class, 'customerStats']);
            Route::get('/customers/{encryptedIdentifier}/referral-wallet', [ReferralController::class, 'wallet']);
            Route::get('/customers/{encryptedIdentifier}/referral-wallet/withdrawals', [ReferralWithdrawalController::class, 'customerWithdrawals']);
            Route::post('/customers/{encryptedIdentifier}/referral-wallet/withdraw', [ReferralWithdrawalController::class, 'store'])->middleware(['throttle:financial', 'idempotency']);
            // Referral withdrawal admin (GMS)
            Route::get('/referral-withdrawals/{encryptedIdentifier}', [ReferralWithdrawalController::class, 'show']);
            Route::post('/referral-withdrawals/{encryptedIdentifier}/settle', [ReferralWithdrawalController::class, 'settle'])->middleware(['idempotency', 'throttle:write']);
            // Customer Purchases Routes
            Route::get('/customers/purchases/{encryptedIdentifier}', [CustomerController::class, 'customer_purchases']);
            // Finance: one customer's wallet statement
            Route::get('/finance/customers/{encryptedIdentifier}/statement', [FinanceDrilldownController::class, 'customerStatement'])->middleware('throttle:stats');
            // Finance: void an expense
            Route::post('/finance/expenses/{encryptedIdentifier}/void', [FinanceExpenseController::class, 'void'])->middleware(['idempotency', 'throttle:write']);
            // Finance: void an excise duty remittance
            Route::post('/finance/excise-duty/remittances/{encryptedIdentifier}/void', [ExciseDutyController::class, 'void'])->middleware(['idempotency', 'throttle:write']);
            // Finance: void a winnings tax remittance
            Route::post('/finance/winnings-tax/remittances/{encryptedIdentifier}/void', [WinningsTaxController::class, 'void'])->middleware(['idempotency', 'throttle:write']);

            // Wallet Routes
            Route::get('/wallets/{encryptedIdentifier}', [WalletController::class, 'show']);
            Route::put('/wallets/{encryptedIdentifier}', [WalletController::class, 'update']);
            Route::put('/wallets/{encryptedIdentifier}/balance', [WalletController::class, 'update_balance']);
            Route::put('/wallets/{encryptedIdentifier}/withdraw', [WalletController::class, 'reduce_balance']);
            Route::put('/wallets/{encryptedIdentifier}/deposit', [WalletController::class, 'add_balance']);

            // Wallet Transactions Routes
            Route::get('/wallets/transactions/{encryptedIdentifier}', [WalletTransactionController::class, 'show']);

            // Purchases Routes
            Route::get('/purchases/{encryptedIdentifier}', [PurchaseController::class, 'show']);
            Route::delete('/purchases/{encryptedIdentifier}', [PurchaseController::class, 'destroy']);

            // Coins Wallets Routes
            Route::get('/coins/{encryptedIdentifier}', [CoinController::class, 'show']);
            Route::put('/coins/{encryptedIdentifier}', [CoinController::class, 'update']);

            // Transactions Routes
            Route::get('/transactions', [TransactionController::class, 'index']);
            Route::get('/transactions/{encryptedIdentifier}', [TransactionController::class, 'show']);

            // Deposit/Payment Routes
            Route::get('/deposits/{encryptedIdentifier}', [DepositController::class, 'show']);
            Route::post('/deposits/{encryptedIdentifier}/assign', [UnmatchedDepositController::class, 'assign'])->middleware(['idempotency', 'throttle:write']);
            Route::post('/deposits/{encryptedIdentifier}/refund', [UnmatchedDepositController::class, 'refund'])->middleware(['idempotency', 'throttle:write']);
            // Owner money paid to the paybill: credit it to the house wallet, or void that
            Route::post('/deposits/{encryptedIdentifier}/house-funding', [HouseFundingController::class, 'store'])->middleware(['idempotency', 'throttle:write']);
            Route::post('/finance/house-funding/{encryptedIdentifier}/void', [HouseFundingController::class, 'void'])->middleware(['idempotency', 'throttle:write']);
            // A shop owner's paybill payment (bill ref SHOP-<shop_no>): credit it to the shop float, or void that
            Route::post('/deposits/{encryptedIdentifier}/shop-float', [BetShopFloatController::class, 'store'])->middleware(['idempotency', 'throttle:write']);
            Route::post('/bet-shops/float-purchases/{encryptedIdentifier}/void', [BetShopFloatController::class, 'void'])->middleware(['idempotency', 'throttle:write']);

            // Bet shops (GMS)
            Route::get('/bet-shops/{encryptedIdentifier}', [BetShopController::class, 'show']);
            Route::put('/bet-shops/{encryptedIdentifier}', [BetShopController::class, 'update'])->middleware('throttle:write');
            Route::post('/bet-shops/{encryptedIdentifier}/suspend', [BetShopController::class, 'suspend'])->middleware(['idempotency', 'throttle:write']);
            Route::post('/bet-shops/{encryptedIdentifier}/activate', [BetShopController::class, 'activate'])->middleware(['idempotency', 'throttle:write']);
            Route::post('/bet-shops/{encryptedIdentifier}/close', [BetShopController::class, 'close'])->middleware(['idempotency', 'throttle:write']);
            Route::get('/bet-shops/{encryptedIdentifier}/keys', [BetShopController::class, 'keys']);
            // Not idempotent on purpose: a stored response would keep the new key in the database for 24 hours
            Route::post('/bet-shops/{encryptedIdentifier}/keys', [BetShopController::class, 'issueKey'])->middleware('throttle:write');
            Route::post('/bet-shop-keys/{encryptedIdentifier}/revoke', [BetShopController::class, 'revokeKey'])->middleware(['idempotency', 'throttle:write']);
            // Any API key, whatever its scope (GMS)
            Route::post('/api-keys/{encryptedIdentifier}/revoke', [ApiKeyController::class, 'revoke'])->middleware(['idempotency', 'throttle:write']);
            Route::get('/bet-shops/{encryptedIdentifier}/float-purchases', [BetShopFloatController::class, 'index']);
            // Float paid back to an owner outside the API, recorded by GMS
            Route::post('/bet-shops/{encryptedIdentifier}/float-redemptions', [BetShopFloatController::class, 'redeem'])->middleware(['idempotency', 'throttle:write']);
            Route::get('/bet-shops/{encryptedIdentifier}/float-redemptions', [BetShopFloatController::class, 'redemptions']);
            Route::post('/bet-shops/float-redemptions/{encryptedIdentifier}/void', [BetShopFloatController::class, 'voidRedemption'])->middleware(['idempotency', 'throttle:write']);
            Route::get('/bet-shops/{encryptedIdentifier}/loads', [BetShopLoadController::class, 'index']);
            Route::get('/bet-shops/loads/{encryptedIdentifier}', [BetShopLoadController::class, 'show']);
            Route::post('/bet-shops/loads/{encryptedIdentifier}/reverse', [BetShopLoadController::class, 'reverse'])->middleware(['idempotency', 'throttle:write']);
            Route::get('/bet-shops/{encryptedIdentifier}/withdrawals', [ShopWithdrawalController::class, 'gmsIndex']);
            // Cash withdrawals at a bet shop, asked for by the player
            Route::post('/customers/{encryptedIdentifier}/shop-withdrawals', [ShopWithdrawalController::class, 'store'])->middleware(['throttle:financial', 'idempotency']);
            Route::get('/customers/{encryptedIdentifier}/shop-withdrawals', [ShopWithdrawalController::class, 'index']);
            Route::post('/customers/{encryptedIdentifier}/shop-withdrawals/{withdrawalId}/cancel', [ShopWithdrawalController::class, 'cancel'])->middleware(['idempotency', 'throttle:write']);

            // A player's bet history (site key)
            Route::get('/customers/{encryptedIdentifier}/wagers', [WagerAdminController::class, 'customerIndex']);

            // Launch a game for a logged-in kadi player (site key; never idempotent: a stored response would replay a live token)
            Route::post('/customers/{encryptedIdentifier}/launch-tokens', [LaunchTokenController::class, 'store'])->middleware('throttle:write');
            // The player cancels their own withdrawal held for approval (site key); the money returns to the wallet
            Route::post('/customers/{encryptedIdentifier}/withdrawals/{id}/cancel', [WithdrawalApprovalController::class, 'cancel'])->middleware(['idempotency', 'throttle:write']);
            // End a player's game sessions, e.g. on kadi logout (site key)
            Route::post('/customers/{encryptedIdentifier}/player-tokens/revoke', [PlayerSessionController::class, 'revoke'])->middleware('throttle:write');

            // Wallet Transfer Routes
            Route::post('/wallets/transfer/{encryptedIdentifier}', WalletTransferController::class)->middleware(['idempotency', 'throttle:write']);

            // Coins Action Routes
            Route::post('/coins/buy/{encryptedIdentifier}', CoinBuyController::class)->middleware(['idempotency', 'throttle:write']);
            Route::post('/coins/transfer/{encryptedIdentifier}', CoinTransferController::class)->middleware(['idempotency', 'throttle:write']);
            Route::put('/coins/exchange/{encryptedIdentifier}', CoinExchangeController::class)->middleware(['idempotency', 'throttle:write']);

            // Withdraw Transaction Routes
            Route::get('/withdraws/{encryptedIdentifier}', [WithdrawController::class, 'show']);

            // Sensitive operation routes here
            Route::middleware(['throttle:financial', 'idempotency'])->group(function () {
                // StkPush / M-PESA Express Deposit
                Route::post('/deposits/{encryptedIdentifier}', StkDepositController::class);
                Route::post('/load/{encryptedIdentifier}', StkLoadController::class);
                // Withdrawal route (initiates B2C Payout)
                Route::post('/withdraw/{encryptedIdentifier}', WithdrawalController::class);
            });

            // Game Wallets Routes
            Route::get('/game/wallets/{encryptedIdentifier}', [GameWalletController::class, 'show']);
            Route::put('/game/wallets/{encryptedIdentifier}', [GameWalletController::class, 'update']);
            Route::delete('/game/wallets/{encryptedIdentifier}', [GameWalletController::class, 'destroy']);

            // Game Incomes Routes
            Route::post('/game/income', [GameWalletController::class, 'game_income']);

            // Game Wallets Drop Connection
            Route::post('/game/drop/{encryptedIdentifier}', DropConnectionController::class)->middleware(['idempotency', 'throttle:write']);

            // Game Wallets Deposit Transactions Routes
            Route::get('/game/bets/{encryptedIdentifier}', [GameTransactionController::class, 'show']);
            Route::put('/game/bets/{encryptedIdentifier}', [GameTransactionController::class, 'update']);
            Route::delete('/game/bets/{encryptedIdentifier}', [GameTransactionController::class, 'destroy']);

            // Game Wallet Payout Transaction Route
            Route::post('/game/withdraw/{encryptedIdentifier}', GameWalletWithdrawController::class)->middleware(['idempotency', 'throttle:write']);

            // Competition Wallets Routes
            Route::get('/competitions/{encryptedIdentifier}', [CompetitionWalletController::class, 'show_competitions']);
            Route::get('/competition/wallets/{encryptedIdentifier}', [CompetitionWalletController::class, 'show']);
            Route::put('/competition/wallets/{encryptedIdentifier}', [CompetitionWalletController::class, 'update']);
            Route::delete('/competition/wallets/{encryptedIdentifier}', [CompetitionWalletController::class, 'destroy']);

            // Income By Competition Type & Rounds
            Route::post('/competition/income/{encryptedIdentifier}', [CompetitionWalletController::class, 'competition_income']);
            // Competition Results & Income Breakdown
            Route::get('competition/results/{encryptedIdentifier}', [CompetitionWalletController::class, 'competition_results']);
            // Competition Awards
            Route::get('/competition/awards/{encryptedIdentifier}', [CompetitionWalletController::class, 'competition_awards']);

            // Competition Wallets Deposit Transactions Routes
            Route::get('/competition/transactions/{encryptedIdentifier}', [CompetitionTransactionController::class, 'show']);
            Route::put('/competition/transactions/{encryptedIdentifier}', [CompetitionTransactionController::class, 'update']);
            Route::delete('/competition/transactions/{encryptedIdentifier}', [CompetitionTransactionController::class, 'destroy']);

            // Competition Wallet Payout Transaction Route
            Route::post('/competition/withdraw/{encryptedIdentifier}', CompetitionWalletWithdrawController::class)->middleware(['idempotency', 'throttle:write']);

            // Complaint Routes
            Route::get('/complaints/{encryptedIdentifier}', [ComplaintController::class, 'show']);
            Route::post('/complaints/{encryptedIdentifier}/resolve', [ComplaintController::class, 'resolve'])->middleware(['idempotency', 'throttle:write']);
            Route::post('/complaints/{encryptedIdentifier}/reject', [ComplaintController::class, 'reject'])->middleware(['idempotency', 'throttle:write']);
            Route::post('/complaints/{encryptedIdentifier}/cancel', [ComplaintController::class, 'cancel'])->middleware(['idempotency', 'throttle:write']);

            // Playground Routes
            Route::get('/playground/{encryptedIdentifier}', [PlaygroundController::class, 'show'])->withoutMiddleware('decrypt.identifier');
            Route::put('/playground/{encryptedIdentifier}', [PlaygroundController::class, 'update']);
            Route::delete('/playground/{encryptedIdentifier}', [PlaygroundController::class, 'destroy']);
        });

        // Encrypt & Decrypt Identifier
        Route::post('/encrypt', EncryptIdentifierController::class);
        Route::post('/decrypt', DecryptIdentifierController::class);

        // Register C2B Callback URLs
        Route::get('/c2b/register', RegisterC2BUrlsController::class);

        /*Route::middleware('decryptIdentifier')->get('/test/{encryptedIdentifier}', function ($decryptedIdentifier) {
            return response()->json(['decrypted' => $decryptedIdentifier]);
        });*/
    });

    // Callback URLS
    Route::middleware('throttle:callback')->group(function () {
        Route::post('/stk/callback', StkCallbackController::class);
        Route::post('/b2c/result', B2CResultController::class);
        Route::post('/b2c/timeout', B2CTimeOutController::class);
        Route::post('/balance/b2c/result', B2CBalanceController::class);
        Route::post('/balance/b2c/timeout', B2CBalanceTimeoutController::class);
        Route::post('/balance/c2b/result', C2BBalanceResultController::class);
        Route::post('/balance/c2b/timeout', C2BBalanceTimeoutController::class);

        // Referral payouts (referral B2C shortcode)
        Route::post('/referral/b2c/result', ReferralB2CResultController::class);
        Route::post('/referral/b2c/timeout', ReferralB2CTimeoutController::class);
        Route::post('/referral/balance/b2c/result', ReferralB2CBalanceController::class);
        Route::post('/referral/balance/b2c/timeout', ReferralB2CBalanceTimeoutController::class);

        // Kadi Kings
        Route::post('/c2b/confirm', ConfirmationController::class);
        Route::post('/c2b/validate', ValidationController::class);

        // Kizuka C2B Endpoints
        Route::post('/km/c2b/confirm', ConfirmationController::class);
        Route::post('/km/c2b/validate', ValidationController::class);
    });
});
