<?php

namespace App\Domain\Jet;

use App\Models\Customer;
use App\Models\JetBet;
use Illuminate\Support\Facades\DB;

/**
 * M1 operation-key idempotency: a key is the player's, across command types. The same key and body replays the
 * stored result; a changed body is IDEMPOTENCY_CONFLICT. The result, the bet and the wager commit together.
 */
class OperationService
{
    public function __construct(private SnapshotService $snapshots) {}

    /**
     * @param  array<string, mixed>  $payload  the command's fingerprint fields
     * @param  callable(string $wagerReference): JetBet  $command
     * @return array{operation_id: string, bet: array<string, mixed>, snapshot: array<string, mixed>}
     */
    public function execute(Customer $customer, string $key, array $payload, callable $command): array
    {
        return JetLock::run(function () use ($customer, $key, $payload, $command) {
            $hash = hash('sha256', $key);
            $fingerprint = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
            $existing = DB::table('jet_operations')->where('customer_id', $customer->id)->where('key_hash', $hash)->first();

            if ($existing) {
                if (! hash_equals($existing->fingerprint, $fingerprint)) {
                    throw new JetException('IDEMPOTENCY_CONFLICT', 409);
                }

                return $this->storedResult($existing);
            }

            $bet = $command(hash('sha256', "jet:v1:{$customer->id}:{$key}"));
            $result = ['operation_id' => $hash, 'bet' => $this->snapshots->bet($bet->load('round')), 'snapshot' => $this->snapshots->read($customer)];

            DB::table('jet_operations')->insert([
                'customer_id' => $customer->id,
                'key_hash' => $hash,
                'fingerprint' => $fingerprint,
                'result' => json_encode($result, JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            return $result;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function lookup(Customer $customer, string $key): array
    {
        $row = DB::table('jet_operations')->where('customer_id', $customer->id)->where('key_hash', hash('sha256', $key))->first();

        if (! $row) {
            throw new JetException('OPERATION_NOT_FOUND', 404, 'reconcile');
        }

        return $this->storedResult($row);
    }

    /**
     * @return array<string, mixed>
     */
    private function storedResult(object $row): array
    {
        $expired = $row->result === null || now()->subDays((int) config('jet.operation_ttl_days'))->greaterThan($row->created_at);

        if ($expired) {
            throw new JetException('OPERATION_EXPIRED', 410, 'reconcile');
        }

        return json_decode($row->result, true, 512, JSON_THROW_ON_ERROR);
    }
}
