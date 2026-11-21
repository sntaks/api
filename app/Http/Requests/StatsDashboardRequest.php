<?php

namespace App\Http\Requests;

use App\Services\FinanceDateRange;
use App\Services\Stats\StatsPlayersService;
use App\Services\Stats\StatsRange;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The finance report query (from, to, group_by, exclude_test, product, game, same validation and
 * product_filter_not_supported rules) plus a named `period` and the `compare` window.
 */
class StatsDashboardRequest extends FinanceReportRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'period' => ['nullable', 'in:'.implode(',', StatsRange::PERIODS), 'prohibits:from,to'],
            'compare' => ['nullable', 'in:'.implode(',', StatsRange::COMPARES)],
            'sort' => ['nullable', 'in:'.implode(',', StatsPlayersService::SORTS)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function dateRange(int $defaultDays = 1): FinanceDateRange
    {
        if (! $this->filled('period')) {
            return parent::dateRange($defaultDays);
        }

        return FinanceDateRange::fromArray(StatsRange::period((string) $this->input('period')) + [
            'group_by' => $this->input('group_by'),
            'exclude_test' => $this->boolean('exclude_test', true),
        ]);
    }

    public function statsRange(): StatsRange
    {
        return StatsRange::compared($this->dateRange(), (string) $this->input('compare', StatsRange::COMPARE_PREVIOUS_PERIOD));
    }
}
