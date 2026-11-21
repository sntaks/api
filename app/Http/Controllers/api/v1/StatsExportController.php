<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StatsDashboardRequest;
use App\Services\FinanceDateRange;
use App\Services\Stats\StatsDashboardService;
use App\Services\Stats\StatsPaymentsService;
use App\Services\Stats\StatsPlayersService;
use App\Services\Stats\StatsPromotionsService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The stats answers as CSV, with the same query as the endpoint they come from.
 */
class StatsExportController extends Controller
{
    private const REPORTS = ['stats-games', 'stats-players-top', 'stats-promotions', 'stats-payments'];

    public function __construct(
        private StatsDashboardService $stats,
        private StatsPlayersService $players,
        private StatsPromotionsService $promotions,
        private StatsPaymentsService $payments,
    ) {}

    public function __invoke(StatsDashboardRequest $request, string $report): JsonResponse|StreamedResponse
    {
        if (! in_array($report, self::REPORTS, true)) {
            return response()->json(['success' => false, 'message' => 'Unknown report. Choose one of: '.implode(', ', self::REPORTS)], 404);
        }

        $range = $request->dateRange();
        $filters = $request->productFilters();

        [$columns, $rows] = match ($report) {
            'stats-games' => [
                ['product', 'game', 'revenue_model', 'bets', 'players', 'voids', 'turnover', 'payouts', 'ggr', 'hold', 'rtp', 'avg_stake', 'free_bet_value', 'promotions', 'net', 'winnings_tax_withheld'],
                $this->stats->games($range, $filters)['by_product'],
            ],
            'stats-players-top' => [
                ['customer_id', 'player_no', 'display_name', 'turnover', 'payouts', 'net_loss', 'ggr', 'wins', 'deposits', 'withdrawals', 'games_played', 'last_active'],
                $this->players->top($range, $filters, (string) $request->input('sort', 'turnover'), (int) $request->input('limit', 100))['players'],
            ],
            'stats-promotions' => [
                ['type', 'scope', 'is_cost', 'amount', 'items', 'players'],
                $this->promotions->promotions($range, $filters)['by_type'],
            ],
            'stats-payments' => $this->paymentRows($range),
        };

        $filename = "{$report}-{$range->from->toDateString()}-{$range->to->toDateString()}.csv";

        return response()->streamDownload(function () use ($columns, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $columns, ',', '"', '');

            foreach ($rows as $row) {
                fputcsv($out, array_map(fn (string $column) => $this->cell($row[$column] ?? null), $columns), ',', '"', '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Deposits and withdrawals paid per day.
     *
     * @return array{0: list<string>, 1: list<array<string, mixed>>}
     */
    private function paymentRows(FinanceDateRange $range): array
    {
        $payments = $this->payments->payments($range);
        $withdrawals = collect($payments['withdrawals']['by_day'])->keyBy('date');

        return [
            ['date', 'deposits', 'deposit_amount', 'withdrawals_paid', 'withdrawal_amount'],
            array_map(fn (array $day) => [
                'date' => $day['date'],
                'deposits' => $day['count'],
                'deposit_amount' => $day['amount'],
                'withdrawals_paid' => $withdrawals[$day['date']]['count'] ?? 0,
                'withdrawal_amount' => $withdrawals[$day['date']]['amount'] ?? 0,
            ], $payments['deposits']['by_day']),
        ];
    }

    /**
     * One CSV cell; text a spreadsheet would run as a formula is prefixed with an apostrophe.
     */
    private function cell(mixed $value): string|int|float
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'yes' : 'no',
            is_int($value), is_float($value) => $value,
            preg_match('/^[=+\-@\t\r]/', (string) $value) === 1 => "'".$value,
            default => (string) $value,
        };
    }
}
