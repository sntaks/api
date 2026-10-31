<?php

namespace App\Http\Requests;

use App\Models\Game;
use App\Models\Product;
use App\Services\FinanceDateRange;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class FinanceReportRequest extends FormRequest
{
    /**
     * Reports built from ledger entries, which carry a product: only these take a product or game filter.
     * Every other report (cash, deposits, withdrawals, excise, referrals, bet shops, expenses, balances)
     * refuses it (D29), so a platform-wide number is never shown under a product heading.
     */
    public const PRODUCT_FILTERED_ROUTES = [
        'api/v1/finance/ledger',
        'api/v1/finance/adjustments',
        'api/v1/finance/customers/top',
        'api/v1/finance/trial-balance',
        'api/v1/finance/income-statement',
        'api/v1/finance/winnings-tax/charges',
    ];

    /**
     * The CSV exports of those reports.
     */
    public const PRODUCT_FILTERED_EXPORTS = ['ledger', 'adjustments', 'customers-top', 'trial-balance', 'income-statement', 'income-by-product'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'group_by' => ['nullable', 'in:'.implode(',', [
                FinanceDateRange::GROUP_BY_DAY,
                FinanceDateRange::GROUP_BY_WEEK,
                FinanceDateRange::GROUP_BY_MONTH,
            ])],
            'exclude_test' => ['nullable', 'in:0,1,true,false'],
            'as_of' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'product' => ['nullable', 'string', 'max:50', Rule::exists('products', 'code')],
            'game' => ['nullable', 'string', 'max:50', Rule::exists('games', 'code')],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $maxDays = (int) config('finance.reports.max_range_days');

                if ($this->dateRange()->days() > $maxDays) {
                    $validator->errors()->add('to', "The date range may not be longer than {$maxDays} days.");
                }

                if ($this->filled('product') && $this->filled('game')
                    && Product::where('code', $this->input('product'))->value('game_id') !== Game::where('code', $this->input('game'))->value('id')) {
                    $validator->errors()->add('product', 'The product does not belong to that game.');
                }
            },
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty() || ! ($this->filled('product') || $this->filled('game')) || $this->takesProductFilter()) {
                    return;
                }

                throw new HttpResponseException(response()->json([
                    'success' => false,
                    'message' => 'This report covers the whole platform and cannot be filtered by product or game.',
                    'code' => 'product_filter_not_supported',
                ], 422));
            },
        ];
    }

    /**
     * The product and game codes sent, for a report built from ledger entries.
     *
     * @return array{product?: string, game?: string}
     */
    public function productFilters(): array
    {
        return array_filter($this->only(['product', 'game']), fn ($value) => $value !== null && $value !== '');
    }

    private function takesProductFilter(): bool
    {
        $uri = $this->route()?->uri();

        if ($uri === 'api/v1/finance/export/{report}') {
            return in_array($this->route('report'), self::PRODUCT_FILTERED_EXPORTS, true);
        }

        return in_array($uri, self::PRODUCT_FILTERED_ROUTES, true);
    }

    /**
     * @param  int  $defaultDays  Length of the window when `from` is not sent, ending on `to` (or today).
     */
    public function dateRange(int $defaultDays = 1): FinanceDateRange
    {
        $to = $this->input('to');
        $from = $this->input('from');

        if (! $from && $defaultDays > 1) {
            $from = CarbonImmutable::parse($to ?? 'today', config('app.timezone'))->subDays($defaultDays - 1)->toDateString();
        }

        return FinanceDateRange::fromArray([
            'from' => $from,
            'to' => $to,
            'group_by' => $this->input('group_by'),
            'exclude_test' => $this->boolean('exclude_test', true),
        ]);
    }
}
