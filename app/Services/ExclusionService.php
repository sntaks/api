<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerExclusion;
use DateInterval;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A player takes a break from play (D12): a cool-off or a self-exclusion. A break can be extended but never
 * shortened or ended early, by the player or anyone else, so there is no method that does either. A longer
 * cool-off never downgrades a running self-exclusion.
 *
 * A break follows the person, not the account: it keeps the phone and national ID it was taken with, and
 * every account with that phone or ID (existing, new, or changed onto it later) is on the same break.
 */
class ExclusionService
{
    public function __construct(private PlayerTokenService $playerTokens) {}

    /**
     * @return array{exclusion: CustomerExclusion|null, refusal: GateResult|null}
     */
    public function start(Customer $customer, string $type, string $duration, ?string $actor): array
    {
        $interval = config("responsible_gambling.exclusions.{$type}.{$duration}");

        return DB::transaction(function () use ($customer, $type, $duration, $interval, $actor) {
            // Serialises two breaks started at once for the same player.
            Customer::whereKey($customer->id)->lockForUpdate()->first();

            $running = $customer->activeExclusion();
            // Whole seconds, as the column stores them, so an identical break does not count as a longer one.
            $endsAt = now()->add(new DateInterval($interval))->startOfSecond();

            if ($running && $running->ends_at->gte($endsAt)) {
                return ['exclusion' => null, 'refusal' => GateResult::refuse(
                    'exclusion_shorter',
                    'The current break already lasts longer. A break can be made longer, but not shorter.',
                    422,
                    ['excluded_until' => $running->ends_at->toIso8601String()],
                )];
            }

            $keepsSelfExclusion = $running?->type === CustomerExclusion::TYPE_SELF_EXCLUSION;

            $exclusion = $this->record($customer, $keepsSelfExclusion ? CustomerExclusion::TYPE_SELF_EXCLUSION : $type, $duration, $endsAt, $actor);

            foreach ($this->sameIdentity(Customer::query(), $customer->phone_hash, $customer->id_no)->whereKeyNot($customer->id)->get() as $other) {
                $this->inheritFor($other);
            }

            return ['exclusion' => $exclusion, 'refusal' => null];
        });
    }

    /**
     * Put a customer on the longest running break taken with their phone or national ID by another account,
     * unless their own break already lasts longer. Called when an account is created, or its phone or
     * national ID changes, and for the player's other accounts when a break starts.
     */
    public function inheritFor(Customer $customer): ?CustomerExclusion
    {
        if (blank($customer->phone_hash) && blank($customer->id_no)) {
            return null;
        }

        $source = $this->sameIdentity(CustomerExclusion::query(), $customer->phone_hash, $customer->id_no)
            ->where('customer_id', '!=', $customer->id)
            ->where('ends_at', '>', now())
            ->orderByDesc('ends_at')
            ->first();

        $own = $customer->activeExclusion();

        if (! $source || ($own && $own->ends_at->gte($source->ends_at))) {
            return null;
        }

        $type = $own?->type === CustomerExclusion::TYPE_SELF_EXCLUSION ? CustomerExclusion::TYPE_SELF_EXCLUSION : $source->type;

        return $this->record($customer, $type, $source->duration, $source->ends_at, "inherited:exclusion:{$source->id}");
    }

    private function record(Customer $customer, string $type, string $duration, mixed $endsAt, ?string $actor): CustomerExclusion
    {
        $exclusion = CustomerExclusion::create([
            'customer_id' => $customer->id,
            'type' => $type,
            'duration' => $duration,
            'starts_at' => now(),
            'ends_at' => $endsAt,
            'actor' => $actor,
            'phone_hash' => $customer->phone_hash,
            'id_no' => blank($customer->id_no) ? null : (string) $customer->id_no,
        ]);

        $this->playerTokens->revokeAll($customer);

        return $exclusion;
    }

    /**
     * Rows that share the phone hash or the national ID; never matches on an empty value.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function sameIdentity(Builder $query, ?string $phoneHash, mixed $idNo): Builder
    {
        $idNo = blank($idNo) ? null : (string) $idNo;

        return $query->where(function (Builder $query) use ($phoneHash, $idNo) {
            $query->whereRaw('1 = 0');

            if (filled($phoneHash)) {
                $query->orWhere('phone_hash', $phoneHash);
            }

            if ($idNo !== null) {
                $query->orWhere('id_no', $idNo);
            }
        });
    }
}
