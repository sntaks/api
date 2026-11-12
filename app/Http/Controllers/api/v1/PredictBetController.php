<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Requests\PagedListRequest;
use App\Http\Requests\Predict\CreatePredictQuoteRequest;
use App\Http\Requests\Predict\PlacePredictBetRequest;
use App\Http\Resources\Predict\PredictBetResource;
use App\Http\Resources\Predict\PredictQuoteResource;
use App\Models\Customer;
use App\Models\PredictBet;
use App\Models\PredictMarket;
use App\Services\CustomerService;
use App\Services\Predict\BetService;
use App\Services\Predict\MarketQuery;
use App\Services\Predict\PredictResult;
use App\Services\Predict\QuoteService;
use Illuminate\Http\JsonResponse;

/**
 * A player's prediction bets, through the Kadi site (site key + encrypted customer identifier).
 */
class PredictBetController extends PredictController
{
    public function __construct(private BetService $bets, private QuoteService $quotes, private MarketQuery $markets, private CustomerService $customers) {}

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

        $result = $this->bets->place($customer, $market, $data);

        if ($result->isRefused() && $result->refusal['code'] === 'price_changed') {
            return $this->requote($result, $customer, $market, $data);
        }

        return $this->respond($result, fn (array $placed) => [
            'bet' => PredictBetResource::make($placed['bet']->loadMissing('market.pool'))->resolve(),
            'balance' => $placed['balance'],
        ]);
    }

    /**
     * A fixed-odds quote on an imported market, held for a few seconds.
     */
    public function quote(CreatePredictQuoteRequest $request, string $encryptedIdentifier): JsonResponse
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

        return $this->respond($this->quotes->create($customer, $market, $data['side'], $data['stake']), fn (array $quoted) => [
            'quote' => PredictQuoteResource::make($quoted['quote']->setRelation('market', $quoted['market']))->resolve(),
        ]);
    }

    /**
     * The price moved against the house since the quote: answer 409 `price_changed` with a fresh quote the player
     * can accept (or the reason none can be given now).
     *
     * @param  array<string, mixed>  $data
     */
    private function requote(PredictResult $refused, Customer $customer, PredictMarket $market, array $data): JsonResponse
    {
        $fresh = $this->quotes->create($customer, $market, $data['side'], $data['stake']);
        $extra = $refused->refusal['extra'];

        if (! $fresh->isRefused()) {
            $extra['quote'] = PredictQuoteResource::make($fresh->data['quote']->setRelation('market', $market))->resolve();
        }

        return $this->refusal('price_changed', $refused->refusal['message'], 409, $extra);
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
