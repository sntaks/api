<?php

namespace App\Http\Resources;

use App\Models\ReferralBonus;
use App\Services\FinanceMasker;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReferralResource extends JsonResource
{
    /**
     * The referred customer's phone is masked, so a referrer never sees their referrals' contact details.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'referrer_id' => $this->referrer_id,
            'referred_id' => $this->referred_id,
            'referred_name' => $this->referred?->name,
            'referred_phone' => app(FinanceMasker::class)->phone($this->referred?->phone_no),
            'code_used' => $this->code_used,
            'status' => $this->status(),
            'verified_at' => $this->verified_at?->toIso8601String(),
            'first_deposited_at' => $this->first_deposited_at?->toIso8601String(),
            'earned' => $this->whenLoaded('bonuses', fn () => round((float) $this->bonuses->sum('amount'), 2)),
            'bonuses' => $this->whenLoaded('bonuses', fn () => $this->bonuses->map(fn (ReferralBonus $bonus) => [
                'milestone' => $bonus->milestone,
                'amount' => (float) $bonus->amount,
                'paid_at' => $bonus->created_at?->toIso8601String(),
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * pending_verification, then verified, then deposited once the first deposit is in.
     */
    private function status(): string
    {
        return match (true) {
            $this->verified_at === null => 'pending_verification',
            $this->first_deposited_at !== null => 'deposited',
            default => 'verified',
        };
    }
}
