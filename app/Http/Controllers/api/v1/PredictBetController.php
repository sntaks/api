<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Requests\PagedListRequest;
use App\Http\Requests\Predict\PlacePredictBetRequest;
use App\Http\Resources\Predict\PredictBetResource;
use App\Models\PredictBet;
use App\Services\CustomerService;
use App\Services\Predict\BetService;
use App\Services\Predict\MarketQuery;
use Illuminate\Http\JsonResponse;

/**
 * A player's prediction bets, through the Kadi site (site key + encrypted customer identifier).
 */
class PredictBetController extends PredictController
{
    public function __construct(private BetService $bets, private MarketQuery $markets, private CustomerService $customers) {}

    public function store(PlacePredictBetRequest $request, string $encryptedIdentifier): JsonResponse
    {
        $customer = $this->customers->getCustomer($encryptedIdentifier);

        if (! $customer) {
            return $this->refusal('customer_not_found', 'No customer has that identifier', 404);
        }

        $data = $request->validated();
        $market = $this->markets->findPublic((int) $data['market_id']);

        if (! $market) {
            return $this->refusal('market_not_found', 'No market has that id', 404);
        }

        return $this->respond($this->bets->place($customer, $market, $data), fn (array $placed) => [
            'bet' => PredictBetResource::make($placed['bet']->loadMissing('market.pool'))->resolve(),
            'balance' => $placed['balance'],
        ]);
    }

    public function index(PagedListRequest $request, string $encryptedIdentifier): JsonResponse
    {
        $customer = $this->customers->getCustomer($encryptedIdentifier);

        if (! $customer) {
            return $this->refusal('customer_not_found', 'No customer has that identifier', 404);
        }

        $page = PredictBet::with('market.pool')
            ->where('customer_id', $customer->id)
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('id')
            ->paginate((int) ($request->validated()['per_page'] ?? 20))
            ->withQueryString();

        return $this->pagedResponse($page, fn (PredictBet $bet) => PredictBetResource::make($bet)->resolve());
    }
}
