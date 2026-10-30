<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListWagersRequest;
use App\Http\Resources\WagerAdminResource;
use App\Http\Resources\WagerAdminSettlementResource;
use App\Http\Resources\WagerHistoryResource;
use App\Models\LedgerEntry;
use App\Models\Wager;
use App\Models\WagerSettlement;
use App\Services\CustomerService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * Wager history for kadi (a customer's own bets) and GMS (search and detail, full key).
 */
class WagerAdminController extends Controller
{
    public function __construct(private CustomerService $customers) {}

    /**
     * A customer's bets, newest first. Tombstones are not bets and never appear.
     */
    public function customerIndex(ListWagersRequest $request, string $encryptedIdentifier): JsonResponse
    {
        $customer = $this->customers->getCustomer($encryptedIdentifier);

        if (! $customer) {
            return $this->notFound('customer_not_found', 'No customer has that identifier');
        }

        $filters = $request->validated();

        $page = $this->filtered(Wager::query()->where('customer_id', $customer->id)->where('is_tombstone', false), $filters)
            ->paginate((int) ($filters['per_page'] ?? 50))
            ->withQueryString();

        return $this->pagedResponse($page, fn (Wager $wager) => WagerHistoryResource::make($wager)->resolve());
    }

    /**
     * GMS search over every game's wagers, newest first.
     */
    public function index(ListWagersRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $query = Wager::query()
            ->when(isset($filters['customer']), fn (Builder $query) => $query->where('customer_id', $filters['customer']))
            ->when(isset($filters['reference']), fn (Builder $query) => $query->where('reference', $filters['reference']))
            ->when(isset($filters['from']), fn (Builder $query) => $query->where('placed_at', '>=', $filters['from'].' 00:00:00'))
            ->when(isset($filters['to']), fn (Builder $query) => $query->where('placed_at', '<=', $filters['to'].' 23:59:59'));

        $page = $this->filtered($query, $filters)->paginate((int) ($filters['per_page'] ?? 50))->withQueryString();

        return $this->pagedResponse($page, fn (Wager $wager) => WagerAdminResource::make($wager)->resolve());
    }

    /**
     * One wager with every settlement version and the ledger entries that reference the wager or a settlement.
     */
    public function detail(string $id): JsonResponse
    {
        $wager = ctype_digit($id) ? Wager::with(['product.game', 'round'])->find((int) $id) : null;

        if (! $wager) {
            return $this->notFound('wager_not_found', 'No wager has that id');
        }

        $settlements = $wager->settlements()->orderBy('version')->orderBy('id')->get();

        $entries = LedgerEntry::query()
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $own) => $own->where('referenceable_type', Wager::class)->where('referenceable_id', $wager->id))
                ->orWhere(fn (Builder $mine) => $mine->where('referenceable_type', WagerSettlement::class)->whereIn('referenceable_id', $settlements->modelKeys())))
            ->orderBy('id')
            ->get();

        return response()->json(['success' => true, 'data' => [
            'wager' => WagerAdminResource::make($wager),
            'settlements' => WagerAdminSettlementResource::collection($settlements),
            'ledger_entries' => $entries->map(fn (LedgerEntry $entry) => [
                'id' => $entry->id,
                'entry_id' => $entry->entry_id,
                'entry_type' => $entry->entry_type,
                'referenceable' => $entry->referenceable_type === Wager::class ? 'wager' : 'settlement',
                'referenceable_id' => $entry->referenceable_id,
                'wallet_type' => $entry->wallet_type,
                'wallet_id' => $entry->wallet_id,
                'debit' => (float) $entry->debit,
                'credit' => (float) $entry->credit,
                'balance_before' => (float) $entry->balance_before,
                'balance_after' => (float) $entry->balance_after,
                'status' => $entry->status,
                'created_at' => $entry->created_at?->toIso8601String(),
            ])->values(),
        ]]);
    }

    /**
     * The status, product and game filters both lists share, newest first.
     *
     * @param  Builder<Wager>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Wager>
     */
    private function filtered(Builder $query, array $filters): Builder
    {
        return $query
            ->with(['product.game', 'round'])
            ->when(isset($filters['status']), fn (Builder $query) => $query->where('status', $filters['status']))
            ->when(isset($filters['product']), fn (Builder $query) => $query->whereHas('product', fn (Builder $product) => $product->where('code', $filters['product'])))
            ->when(isset($filters['game']), fn (Builder $query) => $query->whereHas('product.game', fn (Builder $game) => $game->where('code', $filters['game'])))
            ->orderByDesc('placed_at')
            ->orderByDesc('id');
    }

    private function notFound(string $code, string $message): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'code' => $code, 'retryable' => false], 404);
    }
}
