<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SettleRoundRequest;
use App\Http\Requests\VoidRoundRequest;
use App\Http\Resources\WagerRoundResource;
use App\Services\WagerRoundResult;
use App\Services\WagerRoundService;
use Illuminate\Http\JsonResponse;

/**
 * Settle or void every wager of a round together (game key only, no player token).
 */
class WagerRoundController extends Controller
{
    public function __construct(private WagerRoundService $rounds) {}

    public function settle(SettleRoundRequest $request, string $reference): JsonResponse
    {
        $data = $request->validated();

        return $this->respond($this->rounds->settle(
            $request->attributes->get('api_key'),
            $data['product'],
            $reference,
            $data['winners'],
            (bool) ($data['final'] ?? true),
        ));
    }

    public function void(VoidRoundRequest $request, string $reference): JsonResponse
    {
        $data = $request->validated();

        return $this->respond($this->rounds->void($request->attributes->get('api_key'), $data['product'], $reference, $data['reason'] ?? null));
    }

    private function respond(WagerRoundResult $result): JsonResponse
    {
        if ($result->isRefused()) {
            $refusal = $result->refusal;

            return response()->json([
                'success' => false,
                'message' => $refusal['message'],
                'code' => $refusal['code'],
                'retryable' => $refusal['status'] >= 500 || $refusal['status'] === 429,
            ] + $refusal['extra'], $refusal['status']);
        }

        $data = [
            'round' => WagerRoundResource::make($result->round),
            'counts' => $result->counts,
        ];

        // A settle always lists the winners it could not pay; a void has no winners
        if ($result->skippedWinners !== null) {
            $data['skipped_winners'] = $result->skippedWinners;
        }

        return response()->json(['success' => true, 'data' => $data]);
    }
}
