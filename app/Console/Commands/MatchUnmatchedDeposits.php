<?php

namespace App\Console\Commands;

use App\Services\UnmatchedDepositService;
use Illuminate\Console\Command;

class MatchUnmatchedDeposits extends Command
{
    protected $signature = 'deposits:match-unmatched {--dry-run : List what would be assigned without changing anything}';

    protected $description = 'Credit unmatched deposits whose bill ref now matches a customer by account number, player number or verified phone';

    public function __construct(private UnmatchedDepositService $unmatched)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $results = $this->unmatched->matchByBillRef($dryRun);

        if ($results->isEmpty()) {
            $this->info('No unmatched deposit matches a customer.');

            return Command::SUCCESS;
        }

        $this->table(
            ['Deposit', 'M-Pesa ID', 'Amount', 'Bill ref', 'Customer', 'Account no', 'Matched by', 'Result'],
            $results->map(fn (array $row) => [
                $row['deposit_id'], $row['trans_id'], number_format($row['amount'], 2), $row['bill_ref_no'],
                $row['customer_id'], $row['account_no'], $row['matched_by'], $row['message'],
            ])->all()
        );

        if ($dryRun) {
            $this->info("Dry run: {$results->count()} deposit(s) would be assigned, totalling KES ".number_format($results->sum('amount'), 2).'. Nothing was changed.');

            return Command::SUCCESS;
        }

        $assigned = $results->where('assigned', true);
        $this->info("Assigned {$assigned->count()} deposit(s), totalling KES ".number_format($assigned->sum('amount'), 2).'.');

        $failed = $results->where('assigned', false);
        if ($failed->isNotEmpty()) {
            $this->warn("{$failed->count()} deposit(s) could not be assigned; see the Result column.");

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
