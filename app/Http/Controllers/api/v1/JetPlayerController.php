<?php

namespace App\Http\Controllers\api\v1;

use App\Domain\Jet\BetService;
use App\Domain\Jet\CashoutService;
use App\Domain\Jet\JetException;
use App\Domain\Jet\JetLock;
use App\Domain\Jet\OperationService;
use App\Domain\Jet\SessionCodeService;
use App\Domain\Jet\SnapshotService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Jet\PlaceJetBetRequest;
use App\Models\Customer;
use App\Models\JetBet;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Kadi Jet player routes (/api/v1/jet, Bearer player token for game jet) and the launch code exchange.
 */
class JetPlayerController extends Controller
{
    /**
     * Trades a one-time launch code for the player token (single use, 60 s). Any bad, used or expired code gets
     * the same answer.
     */
    public function session(Request $request, SessionCodeService $codes): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'size:40']]);
        $session = $codes->redeem($data['code']);

        if (! $session) {
            throw new JetException('UNAUTHENTICATED', 401);
        }

        $customer = Customer::findOrFail($session['customer_id']);

        return response()->json([
            'token' => $session['token'],
            'expires_at' => $session['expires_at']->toIso8601String(),
            'player' => ['id' => $customer->id, 'display_name' => $customer->displayName()],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $customer = $this->player($request);
        $wallet = Wallet::where('customer_id', $customer->id)->first();

        return response()->json([
            'player' => ['id' => $customer->id, 'display_name' => $customer->displayName()],
            'balance' => [
                'amount' => number_format((float) ($wallet->balance ?? 0), 2, '.', ''),
                'currency' => config('jet.currency'),
                'revision' => (int) ($wallet->wallet_version ?? 0),
            ],
            'wallet_version' => (int) ($wallet->wallet_version ?? 0),
        ]);
    }

    public function place(PlaceJetBetRequest $request, OperationService $operations, BetService $bets): JsonResponse
    {
        $customer = $this->player($request);
        $data = $request->validated();
        $autoCashout = $data['auto_cashout'] ?? null;
        $payload = ['type' => 'place', 'round_number' => (int) $data['round_number'], 'stake' => $data['stake'], 'auto_cashout' => $autoCashout];

        $result = $operations->execute($customer, $this->operationKey($request), $payload,
            fn (string $reference) => $bets->place($customer, (int) $data['round_number'], $data['stake'], $autoCashout, $reference));

        return response()->json($result, 201);
    }

    public function cashout(Request $request, string $bet, OperationService $operations, CashoutService $cashouts): JsonResponse
    {
        $request->validate(['multiplier' => ['prohibited'], 'at_multiplier' => ['prohibited']]);
        $customer = $this->player($request);

        if (! ctype_digit($bet) || ! JetBet::whereKey((int) $bet)->where('customer_id', $customer->id)->exists()) {
            throw new JetException('NOT_FOUND', 404);
        }

        return response()->json($operations->execute($customer, $this->operationKey($request), ['type' => 'cashout', 'bet_id' => (int) $bet],
            fn () => $cashouts->cashOut($customer, (int) $bet)));
    }

    /**
     * The player's resolved bets (won, lost, cancelled), newest first, 25 a page (M6).
     */
    public function history(Request $request, SnapshotService $snapshots): JsonResponse
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:1000000']]);
        $customer = $this->player($request);

        return response()->json(JetLock::run(function () use ($customer, $data, $snapshots) {
            $bets = JetBet::where('customer_id', $customer->id)->where('status', '!=', JetBet::STATUS_ACTIVE)
                ->with('round')->orderByDesc('id')->paginate(25, ['*'], 'page', $data['page'] ?? 1);

            return [
                'schema_version' => 1,
                'server_now' => now()->toISOString(),
                'private_revision' => (int) (DB::table('jet_private_streams')->where('customer_id', $customer->id)->value('revision') ?? 0),
                'currency' => config('jet.currency'),
                'data' => $bets->map(fn (JetBet $bet) => $snapshots->bet($bet))->all(),
                'current_page' => $bets->currentPage(),
                'last_page' => $bets->lastPage(),
                'per_page' => $bets->perPage(),
                'total' => $bets->total(),
            ];
        }));
    }

    public function operation(Request $request, string $key, OperationService $operations): JsonResponse
    {
        if (strlen($key) > 128) {
            throw new JetException('NOT_FOUND', 404);
        }

        return response()->json($operations->lookup($this->player($request), $key));
    }

    private function player(Request $request): Customer
    {
        return $request->attributes->get('jet_player') ?? throw new JetException('UNAUTHENTICATED', 401);
    }

    private function operationKey(Request $request): string
    {
        $key = (string) $request->header('Idempotency-Key', '');

        if (! preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $key)) {
            throw new JetException('VALIDATION_FAILED', 422);
        }

        return $key;
    }
}
