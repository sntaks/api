<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\FinanceListRequest;
use App\Services\ExciseDutyReportService;
use App\Services\FinanceBetShopReportService;
use App\Services\FinanceDateRange;
use App\Services\FinanceDisputeReportService;
use App\Services\FinanceExpenseService;
use App\Services\FinanceGameReportService;
use App\Services\FinanceLedgerReportService;
use App\Services\FinanceListing;
use App\Services\FinancePaymentReportService;
use App\Services\FinancePromotionReportService;
use App\Services\FinanceReferralReportService;
use App\Services\FinanceReportService;
use App\Services\FinanceTaxService;
use App\Services\HouseFundingService;
use Generator;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceExportController extends Controller
{
    private const REPORTS = [
        'ledger', 'deposits', 'withdrawals', 'purchases', 'adjustments', 'games', 'competitions',
        'customers-top', 'cash-flow', 'income-statement', 'income-by-product', 'trial-balance', 'expenses', 'taxes',
        'excise-duty', 'excise-duty-charges', 'excise-duty-returns', 'excise-duty-remittances', 'disputes',
        'referral-bonuses', 'referral-withdrawals', 'promotions', 'house-funding', 'open-wallets', 'bet-shops', 'bet-shop-loads', 'bet-shop-withdrawals',
    ];

    /** Days the top customers report covers when no range is given. */
    private const TOP_CUSTOMERS_DAYS = 30;

    public function __construct(
        private FinancePaymentReportService $payments,
        private FinanceLedgerReportService $ledger,
        private FinanceGameReportService $games,
        private FinanceReportService $reports,
        private FinanceExpenseService $expenses,
        private FinanceTaxService $taxes,
        private ExciseDutyReportService $exciseDuty,
        private FinanceDisputeReportService $disputes,
        private FinanceReferralReportService $referrals,
        private FinancePromotionReportService $promotions,
        private HouseFundingService $houseFunding,
        private FinanceBetShopReportService $betShops,
    ) {}

    /**
     * Stream a report as CSV. Takes the same range and filters as the report itself and exports
     * every row, not one page.
     */
    public function __invoke(FinanceListRequest $request, string $report): JsonResponse|StreamedResponse
    {
        if (! in_array($report, self::REPORTS, true)) {
            return response()->json(['success' => false, 'message' => 'Unknown report. Choose one of: '.implode(', ', self::REPORTS)], 404);
        }

        $range = $request->dateRange($report === 'customers-top' ? self::TOP_CUSTOMERS_DAYS : 1);
        $filters = $request->filters();

        [$columns, $rows] = match ($report) {
            'ledger' => $this->fromListing($this->ledger->ledger($range, $filters)),
            'deposits' => $this->fromListing($this->payments->deposits($range, $filters)),
            'withdrawals' => $this->fromListing($this->payments->withdrawals($range, $filters)),
            'purchases' => $this->fromListing($this->payments->purchases($range, $filters)),
            'adjustments' => $this->fromListing($this->ledger->adjustments($range, $filters)),
            'games' => $this->fromListing($this->games->games($range, $filters)),
            'competitions' => $this->fromListing($this->games->competitions($range, $filters)),
            'customers-top' => $this->fromListing($this->ledger->topCustomers($range, $filters)),
            'cash-flow' => $this->cashFlowRows($range),
            'income-statement' => $this->incomeStatementRows($range, $filters),
            'income-by-product' => $this->incomeByProductRows($range, $filters),
            'trial-balance' => $this->trialBalanceRows($range, $filters),
            'expenses' => $this->fromListing($this->expenses->listing($range, $filters)),
            'taxes' => $this->taxRows($range),
            'excise-duty' => $this->exciseDutyRows($range),
            'excise-duty-charges' => $this->fromListing($this->exciseDuty->charges($range, $filters)),
            'excise-duty-returns' => $this->exciseDutyReturnRows($range),
            'excise-duty-remittances' => $this->fromListing($this->exciseDuty->remittances($range, $filters)),
            'disputes' => $this->fromListing($this->disputes->listing($range, $filters)),
            'referral-bonuses' => $this->fromListing($this->referrals->bonuses($range, $filters)),
            'referral-withdrawals' => $this->fromListing($this->referrals->withdrawals($range, $filters)),
            'promotions' => $this->fromListing($this->promotions->credits($range, $filters)),
            'house-funding' => $this->fromListing($this->houseFunding->listing($range, $filters)),
            'open-wallets' => $this->fromListing($this->games->openWallets($range, $filters)),
            'bet-shops' => $this->fromListing($this->betShops->shops($range, $filters)),
            'bet-shop-loads' => $this->fromListing($this->betShops->loads($range, $filters)),
            'bet-shop-withdrawals' => $this->fromListing($this->betShops->withdrawals($range, $filters)),
        };

        $filename = "finance-{$report}-{$range->from->toDateString()}-{$range->to->toDateString()}.csv";

        return response()->streamDownload(function () use ($columns, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 byte order mark so Excel reads names correctly
            fputcsv($out, $columns, ',', '"', '');

            foreach ($rows as $row) {
                fputcsv($out, array_map(fn (string $column) => $this->cell($row[$column] ?? null), $columns), ',', '"', '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{0: list<string>, 1: iterable<array<string, mixed>>}
     */
    private function fromListing(FinanceListing $listing): array
    {
        return [$listing->columns, $listing->rows()];
    }

    /**
     * @return array{0: list<string>, 1: Generator<int, array<string, mixed>>}
     */
    private function cashFlowRows(FinanceDateRange $range): array
    {
        $columns = ['period', 'wallet_deposit', 'load', 'gift', 'emoji', 'unmatched', 'house_funding', 'shop_float', 'other', 'cash_in_total', 'paid', 'pending', 'failed', 'excise_withheld', 'excise_remitted', 'net_cash', 'referral_paid', 'referral_pending', 'referral_failed', 'deposit_refunded', 'shop_float_redeemed', 'shop_cash_loaded', 'shop_cash_paid_out', 'winnings_tax_withheld', 'winnings_tax_remitted'];

        $rows = (function () use ($range) {
            foreach ($this->reports->cashFlow($range)['series'] as $row) {
                yield ['period' => $row['period']]
                    + $row['cash_in']
                    + ['cash_in_total' => $row['cash_in']['total']]
                    + $row['cash_out']
                    + ['excise_withheld' => $row['excise_duty']['withheld'], 'excise_remitted' => $row['excise_duty']['remitted']]
                    + ['net_cash' => $row['net_cash']]
                    + ['referral_paid' => $row['referral_payouts']['paid'], 'referral_pending' => $row['referral_payouts']['pending'], 'referral_failed' => $row['referral_payouts']['failed']]
                    + ['deposit_refunded' => $row['deposit_refunds']['refunded']]
                    + ['shop_float_redeemed' => $row['shop_float']['redeemed'], 'shop_cash_loaded' => $row['shop_cash']['loaded'], 'shop_cash_paid_out' => $row['shop_cash']['paid_out']]
                    + ['winnings_tax_withheld' => $row['winnings_tax']['withheld'], 'winnings_tax_remitted' => $row['winnings_tax']['remitted']];
            }
        })();

        return [$columns, $rows];
    }

    /**
     * @param  array<string, string>  $filters
     * @return array{0: list<string>, 1: Generator<int, array<string, mixed>>}
     */
    private function incomeStatementRows(FinanceDateRange $range, array $filters = []): array
    {
        $columns = ['period', 'games', 'tournaments', 'jackpots', 'competitions_unattributed', 'gift_emoji_sales', 'other', 'total', 'expenses', 'net_income', 'referral_payouts', 'promotions', 'house_banked', 'wager_promotions', 'winnings_tax_withheld'];

        $rows = (function () use ($range, $filters) {
            yield from $this->reports->incomeStatement($range, $filters)['series'];
        })();

        return [$columns, $rows];
    }

    /**
     * One row per product, then the platform row, over the whole range.
     *
     * @param  array<string, string>  $filters
     * @return array{0: list<string>, 1: Generator<int, array<string, mixed>>}
     */
    private function incomeByProductRows(FinanceDateRange $range, array $filters): array
    {
        $columns = ['product', 'game', 'revenue_model', 'revenue', 'promotions', 'winnings_tax_withheld', 'net'];

        $rows = (function () use ($range, $filters) {
            $byProduct = $this->reports->incomeStatement($range, $filters)['by_product'];

            yield from $byProduct['products'];
            yield [
                'product' => 'platform',
                'revenue' => $byProduct['platform']['revenue'],
                'promotions' => $byProduct['platform']['promotions'] + $byProduct['platform']['referral_payouts'] + $byProduct['platform']['expenses'],
                'net' => $byProduct['platform']['net'],
            ];
        })();

        return [$columns, $rows];
    }

    /**
     * @return array{0: list<string>, 1: Generator<int, array<string, mixed>>}
     */
    private function taxRows(FinanceDateRange $range): array
    {
        $columns = ['tax', 'label', 'base', 'kind', 'rate', 'base_amount', 'estimated_amount'];

        $rows = (function () use ($range) {
            foreach ($this->taxes->estimate($range)['taxes'] as $key => $line) {
                yield ['tax' => $key] + $line;
            }
        })();

        return [$columns, $rows];
    }

    /**
     * @return array{0: list<string>, 1: Generator<int, array<string, mixed>>}
     */
    private function exciseDutyRows(FinanceDateRange $range): array
    {
        $columns = ['period', 'deposits', 'gross_deposits', 'excise_charged', 'excise_reversed', 'excise_net', 'excise_remitted'];

        $rows = (function () use ($range) {
            yield from $this->exciseDuty->summary($range)['series'];
        })();

        return [$columns, $rows];
    }

    /**
     * @return array{0: list<string>, 1: Generator<int, array<string, mixed>>}
     */
    private function exciseDutyReturnRows(FinanceDateRange $range): array
    {
        $columns = ['period', 'period_start', 'period_end', 'due_date', 'charges', 'gross_deposits', 'excise_charged', 'excise_reversed', 'excise_due', 'excise_remitted', 'outstanding', 'overdue'];

        $rows = (function () use ($range) {
            yield from $this->exciseDuty->returns($range);
        })();

        return [$columns, $rows];
    }

    /**
     * @param  array<string, string>  $filters
     * @return array{0: list<string>, 1: Generator<int, array<string, mixed>>}
     */
    private function trialBalanceRows(FinanceDateRange $range, array $filters = []): array
    {
        $columns = ['account', 'entry_type', 'category', 'unit', 'debit', 'credit', 'entries'];

        $rows = (function () use ($range, $filters) {
            yield from $this->reports->trialBalance($range, $filters)['lines'];
        })();

        return [$columns, $rows];
    }

    /**
     * One CSV cell. Arrays become JSON, booleans yes/no, and text a spreadsheet would run as a
     * formula is prefixed with an apostrophe so it stays text.
     */
    private function cell(mixed $value): string|int|float
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'yes' : 'no',
            is_array($value) => (string) json_encode($value),
            is_int($value), is_float($value) => $value,
            preg_match('/^[=+\-@\t\r]/', (string) $value) === 1 => "'".$value,
            default => (string) $value,
        };
    }
}
