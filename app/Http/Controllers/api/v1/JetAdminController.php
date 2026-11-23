<?php

namespace App\Http\Controllers\api\v1;

use App\Domain\Jet\JetException;
use App\Domain\Jet\JetSettings;
use App\Domain\Jet\RoundScheduler;
use App\Domain\Jet\SettlementService;
use App\Domain\Jet\SnapshotService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Jet\UpdateJetSettingsRequest;
use App\Http\Requests\Jet\VoidJetRoundRequest;
use App\Models\JetBet;
use App\Models\JetRound;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Kadi Jet for GMS (full key): settings, rounds, a round's bets and proof, voiding a broken round, engine health.
 * Every change is written to jet_audit_logs. A round's crash point and seeds stay hidden until it has crashed
 * (seeds until settled), here as everywhere.
 */
class JetAdminController extends Controller
{
    private const PER_PAGE = 50;

    public function settings(JetSettings $settings): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->settingsView($settings)]);
    }

    public function updateSettings(UpdateJetSettingsRequest $request, JetSettings $settings): JsonResponse
    {
        $data = $request->validated();
        $changes = array_intersect_key($data, array_flip(JetSettings::EDITABLE));
        $before = $settings->current();

        DB::transaction(function () use ($settings, $changes, $data, $before, $request) {
            $settings->update($changes, $data['admin_user']);
            $this->audit($request, 'settings_updated', null, $data['admin_user'], $data['reason'],
                array_intersect_key($before, $changes), array_intersect_key($settings->current(), $changes));
        });

        return response()->json(['success' => true, 'message' => 'Settings saved; they apply from the next round', 'data' => $this->settingsView($settings)]);
    }

    public function rounds(Request $request, SnapshotService $snapshots): JsonResponse
    {
        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', 'in:'.implode(',', [JetRound::STATUS_SCHEDULED, JetRound::STATUS_BETTING, JetRound::STATUS_RUNNING, JetRound::STATUS_CRASHED, JetRound::STATUS_SETTLED, JetRound::STATUS_VOIDING, JetRound::STATUS_VOIDED])],
        ]);

        $page = JetRound::query()
            ->when($data['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->withCount('bets')->withSum('bets', 'stake')->withSum('bets', 'payout')
            ->orderByDesc('round_number')->paginate(self::PER_PAGE, ['*'], 'page', $data['page'] ?? 1);

        return $this->pagedResponse($page, fn (JetRound $round) => [
            ...$this->roundView($round, $snapshots),
            'bets_count' => $round->bets_count,
            'stakes' => $this->money($round->bets_sum_stake),
            'payouts' => $this->money($round->bets_sum_payout),
        ]);
    }

    public function round(string $number, SnapshotService $snapshots): JsonResponse
    {
        $round = $this->findRound($number);

        if (! $round) {
            return $this->refusal('round_not_found', 'No round has that number', 404);
        }

        $bets = JetBet::where('jet_round_id', $round->id)->with('customer:id,name,player_no')->orderBy('id')->get();
        $won = $bets->where('status', JetBet::STATUS_WON);

        return response()->json(['success' => true, 'data' => [
            'round' => $this->roundView($round, $snapshots),
            'totals' => [
                'bets' => $bets->count(),
                'stakes' => $this->money($bets->sum(fn (JetBet $bet) => (float) $bet->stake)),
                'winners' => $won->count(),
                'payouts' => $this->money($won->sum(fn (JetBet $bet) => (float) $bet->payout)),
                'tax' => $this->money($won->sum(fn (JetBet $bet) => (float) $bet->tax)),
                'net_payouts' => $this->money($won->sum(fn (JetBet $bet) => (float) $bet->net_payout)),
            ],
            'bets' => $bets->map(fn (JetBet $bet) => [
                'id' => $bet->id,
                'customer_id' => $bet->customer_id,
                'display_name' => $bet->customer?->displayName(),
                'wager_reference' => $bet->wager_reference,
                'stake' => $bet->stake,
                'auto_cashout' => $bet->auto_cashout,
                'cap_multiplier' => $bet->cap_multiplier,
                'cashout_multiplier' => $bet->cashout_multiplier,
                'payout' => $bet->payout,
                'tax' => $bet->tax,
                'net_payout' => $bet->net_payout,
                'status' => $bet->status,
                'placed_at' => $bet->placed_at?->toIso8601String(),
                'cashed_out_at' => $bet->cashed_out_at?->toIso8601String(),
            ])->values(),
        ]]);
    }

    /**
     * Voids a broken round that has not crashed: open bets are refunded, bets already cashed out keep their win.
     * A crashed round is being settled by the loop and cannot be voided here.
     */
    public function void(VoidJetRoundRequest $request, string $number, SettlementService $settlement, SnapshotService $snapshots): JsonResponse
    {
        $data = $request->validated();
        $round = $this->findRound($number);

        if (! $round) {
            return $this->refusal('round_not_found', 'No round has that number', 404);
        }

        if ($round->status === JetRound::STATUS_CRASHED) {
            return $this->refusal('round_settling', 'This round has crashed and is being settled', 409);
        }

        $before = $round->status;

        try {
            $round = $settlement->void($round, $data['reason'], "gms:{$data['admin_user']}");
        } catch (JetException) {
            return $this->refusal('round_finished', 'This round is already settled or voided', 409);
        }

        $this->audit($request, 'round_voided', $round->round_number, $data['admin_user'], $data['reason'], ['status' => $before], ['status' => $round->status]);

        return response()->json(['success' => true, 'message' => 'Round voided', 'data' => $this->roundView($round, $snapshots)]);
    }

    public function health(): JsonResponse
    {
        $heartbeat = Cache::get(RoundScheduler::HEARTBEAT_KEY);
        $heartbeatAge = $heartbeat ? (int) CarbonImmutable::parse($heartbeat)->diffInSeconds(now(), true) : null;
        $state = DB::table('jet_contract_state')->where('id', 1)->first();
        $round = JetRound::latest('id')->first();
        $oldestPending = DB::table('jet_outbox')->whereNull('published_at')->count();
        $reverbReachable = $this->reverbReachable();
        $loopAlive = $heartbeatAge !== null && $heartbeatAge <= 30;

        return response()->json(['success' => true, 'data' => [
            'healthy' => $loopAlive && $reverbReachable,
            'loop' => [
                'alive' => $loopAlive,
                'heartbeat_at' => $heartbeat,
                'heartbeat_age_seconds' => $heartbeatAge,
                'lease_held' => $state?->loop_owner !== null && $state?->lease_until !== null && $state->lease_until > now()->format('Y-m-d H:i:s.u'),
            ],
            'round' => $round ? ['round_number' => $round->round_number, 'status' => $round->status] : null,
            'outbox_pending' => $oldestPending,
            'reverb_reachable' => $reverbReachable,
        ]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsView(JetSettings $settings): array
    {
        return [...$settings->current(), 'editable' => JetSettings::EDITABLE, 'read_only' => ['house_edge', 'acceleration_k']];
    }

    /**
     * @return array<string, mixed>
     */
    private function roundView(JetRound $round, SnapshotService $snapshots): array
    {
        return [
            ...$snapshots->round($round),
            'status' => $round->status,
            'settled_at' => $round->settled_at?->toIso8601String(),
            'voided_at' => $round->voided_at?->toIso8601String(),
            'void_reason' => $round->void_reason,
            'voided_by' => $round->voided_by,
        ];
    }

    private function findRound(string $number): ?JetRound
    {
        return ctype_digit($number) ? JetRound::where('round_number', (int) $number)->first() : null;
    }

    private function reverbReachable(): bool
    {
        $host = (string) config('broadcasting.connections.reverb.options.host');
        $port = (int) config('broadcasting.connections.reverb.options.port');

        if ($host === '' || $port <= 0) {
            return false;
        }

        $socket = @fsockopen($host, $port, $errorCode, $errorMessage, 1.0);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function audit(Request $request, string $action, ?int $roundNumber, string $adminUser, ?string $reason, ?array $before, ?array $after): void
    {
        DB::table('jet_audit_logs')->insert([
            'action' => $action,
            'round_number' => $roundNumber,
            'admin_user' => $adminUser,
            'reason' => $reason,
            'before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after' => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR),
            'api_key_id' => $request->attributes->get('api_key')?->id,
            'ip' => $request->ip(),
            'created_at' => now(),
        ]);
    }

    private function refusal(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'code' => $code], $status);
    }

    private function money(float|int|string|null $amount): string
    {
        return number_format((float) ($amount ?? 0), 2, '.', '');
    }
}
