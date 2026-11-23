<?php

namespace App\Http\Controllers\api\v1;

use App\Domain\Jet\EventPublisher;
use App\Domain\Jet\JetContext;
use App\Domain\Jet\PresentationService;
use App\Domain\Jet\SnapshotService;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\JetBet;
use App\Models\JetRound;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Kadi Jet public reads (/api/v1/jet). bootstrap and snapshot also take an optional player bearer.
 */
class JetController extends Controller
{
    public function bootstrap(Request $request, SnapshotService $snapshots, JetContext $context): JsonResponse
    {
        $product = $context->product();
        $money = fn ($value) => $value === null ? null : number_format((float) $value, 2, '.', '');

        return response()->json([
            ...$snapshots->read($this->player($request)),
            'constraints' => [
                'min_stake' => $money($product->min_stake),
                'max_stake' => $money($product->max_stake),
                'max_payout' => $money($product->max_payout),
                'winnings_tax_rate' => (string) ($product->winnings_tax_rate ?? '0'),
                // KadiApi withholds tax on the winnings (payout minus stake), not on the gross payout
                'tax_basis' => 'winnings',
                'decimal_places' => 2,
                'auto_cashout_min' => '1.01',
                'auto_cashout_equality' => 'loses',
                'multiple_bets' => true,
                'timestamp_precision' => 'seconds',
                'rounding' => 'half-up',
                'available' => $product->isAvailable(),
            ],
            'realtime' => [
                'protocol' => 'pusher',
                'key' => config('broadcasting.connections.reverb.key'),
                'host' => config('jet.realtime.host'),
                'port' => config('jet.realtime.port'),
                'scheme' => config('jet.realtime.scheme'),
                'path' => '',
                'auth_endpoint' => '/broadcasting/auth',
                'public_channel' => EventPublisher::PUBLIC_STREAM,
                'event' => 'jet.v1',
            ],
        ]);
    }

    public function snapshot(Request $request, SnapshotService $snapshots): JsonResponse
    {
        return response()->json($snapshots->read($this->player($request)));
    }

    public function rounds(Request $request, SnapshotService $snapshots): JsonResponse
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:1000000']]);

        return response()->json(JetRound::where('status', JetRound::STATUS_SETTLED)->orderByDesc('round_number')
            ->paginate(25, ['*'], 'page', $data['page'] ?? 1)->through(fn (JetRound $round) => $snapshots->round($round)));
    }

    public function round(string $number, SnapshotService $snapshots): JsonResponse
    {
        abort_unless(ctype_digit($number), 404);

        return response()->json(['data' => $snapshots->round(JetRound::where('round_number', (int) $number)->firstOrFail())]);
    }

    public function presentation(PresentationService $presentation): JsonResponse
    {
        return response()->json($presentation->read());
    }

    /**
     * The current round's real bets (at most 50, biggest stakes first): a public name, the stake and, once cashed
     * out, the multiplier and what was paid. Never a phone number, player id or auto target.
     */
    public function liveBets(): JsonResponse
    {
        $round = JetRound::latest('id')->first();
        $bets = $round
            ? JetBet::where('jet_round_id', $round->id)->with('customer:id,name,player_no')->orderByDesc('stake')->orderBy('id')->limit(50)->get()
            : collect();

        return response()->json([
            'schema_version' => 1,
            'round_number' => $round?->round_number,
            'data' => $bets->map(fn (JetBet $bet) => [
                'display_name' => $bet->customer?->displayName() ?? 'Player',
                'stake' => (string) $bet->stake,
                'cashout_multiplier' => $bet->status === JetBet::STATUS_WON ? $bet->cashout_multiplier : null,
                'payout' => $bet->status === JetBet::STATUS_WON ? $bet->net_payout : null,
                'status' => $bet->status === JetBet::STATUS_VOID ? 'cancelled' : $bet->status,
            ])->values(),
        ]);
    }

    private function player(Request $request): ?Customer
    {
        return $request->attributes->get('jet_player');
    }
}
