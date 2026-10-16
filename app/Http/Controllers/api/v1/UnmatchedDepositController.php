<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DepositResolutionResource;
use App\Models\Deposit;
use App\Services\BetShopFloatService;
use App\Services\FinanceMasker;
use App\Services\UnmatchedDepositService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UnmatchedDepositController extends Controller
{
    public function __construct(
        private UnmatchedDepositService $unmatched,
        private BetShopFloatService $shopFloat,
    ) {}

    /**
     * Deposits whose account number matched no customer (status 0), oldest first, each with suggested
     * customers. Pass `status=refunded`, `status=assigned`, `status=house_funded` or `status=shop_float` to see resolved ones instead. An unmatched deposit whose bill ref names a bet shop (SHOP-<shop_no>) carries that shop in `bet_shop`, ready to credit with POST /deposits/{id}/shop-float.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:unmatched,assigned,refunded,house_funded,shop_float'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $status = $filters['status'] ?? 'unmatched';

        $deposits = Deposit::query()
            ->with(['resolution.customer', 'resolution.ledgerEntry', 'resolution.shopFloatPurchase.betShop'])
            ->when($status === 'unmatched', fn (Builder $query) => $query->where('status', Deposit::STATUS_UNMATCHED)->orderBy('id'))
            ->when($status !== 'unmatched', fn (Builder $query) => $query->whereHas('resolution', fn (Builder $resolution) => $resolution->where('action', $status))->orderByDesc('id'))
            ->when(isset($filters['from']), fn (Builder $query) => $query->where('created_at', '>=', $filters['from'].' 00:00:00'))
            ->when(isset($filters['to']), fn (Builder $query) => $query->where('created_at', '<=', $filters['to'].' 23:59:59'))
            ->paginate((int) ($filters['per_page'] ?? 50))
            ->withQueryString();

        $masker = app(FinanceMasker::class);
        $unmatchedTotal = Deposit::where('status', Deposit::STATUS_UNMATCHED);

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => [
                    'unmatched_count' => (clone $unmatchedTotal)->count(),
                    'unmatched_amount' => round((float) (clone $unmatchedTotal)->sum('trans_amount'), 2),
                ],
                'items' => $deposits->getCollection()->map(fn (Deposit $deposit) => [
                    'id' => $deposit->id,
                    'trans_id' => $deposit->trans_id,
                    'trans_time' => $deposit->trans_time,
                    'amount' => (float) $deposit->trans_amount,
                    'bill_ref_no' => $deposit->bill_ref_no,
                    'msisdn' => $masker->phone($deposit->msisdn),
                    'name' => $deposit->name,
                    'status' => (int) $deposit->status,
                    'created_at' => $deposit->created_at?->toIso8601String(),
                    'suggestions' => $status === 'unmatched' ? $this->unmatched->suggestionsFor($deposit) : [],
                    'bet_shop' => $status === 'unmatched' ? $this->shopFor($deposit) : null,
                    'resolution' => $deposit->resolution ? DepositResolutionResource::make($deposit->resolution) : null,
                ])->values(),
                'pagination' => [
                    'page' => $deposits->currentPage(),
                    'per_page' => $deposits->perPage(),
                    'total' => $deposits->total(),
                    'last_page' => $deposits->lastPage(),
                ],
            ],
        ]);
    }

    /**
     * Run the account-number matcher (the same as `php artisan deposits:match-unmatched`): assign every
     * unmatched deposit whose bill ref now matches a customer's account number exactly. Never by phone.
     * `dry_run` (default true) only lists what would be assigned.
     */
    public function match(Request $request): JsonResponse
    {
        $validated = $request->validate(['dry_run' => ['nullable', 'boolean']]);
        $dryRun = (bool) ($validated['dry_run'] ?? true);

        $results = $this->unmatched->matchByAccountNumber($dryRun, $this->actorFor($request));
        $assigned = $results->where('assigned', true);

        return response()->json([
            'success' => true,
            'data' => [
                'dry_run' => $dryRun,
                'matched' => $results->count(),
                'matched_amount' => round((float) $results->sum('amount'), 2),
                'assigned' => $assigned->count(),
                'assigned_amount' => round((float) $assigned->sum('amount'), 2),
                'items' => $results->values(),
            ],
        ]);
    }

    /**
     * Credit an unmatched deposit to a customer's wallet.
     */
    public function assign(Request $request, string $encryptedIdentifier): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => ['required', 'integer', 'min:1'],
            'note' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        return $this->respond($this->unmatched->assign((int) $encryptedIdentifier, (int) $validated['customer_id'], $validated['note'], $this->actorFor($request)));
    }

    /**
     * Record that an unmatched deposit was reversed to the payer on the M-Pesa portal.
     */
    public function refund(Request $request, string $encryptedIdentifier): JsonResponse
    {
        $validated = $request->validate([
            'mpesa_reference' => ['required', 'string', 'regex:/^[A-Za-z0-9]{6,30}$/'],
            'note' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        return $this->respond($this->unmatched->refund((int) $encryptedIdentifier, strtoupper($validated['mpesa_reference']), $validated['note'], $this->actorFor($request)));
    }

    /**
     * A refused request carries `code` (e.g. `already_resolved`, `reference_used`) and, when a field is at
     * fault, `errors` shaped like a validation error.
     *
     * @param  array{success: bool, message: string, status_code: int, deposit?: Deposit, code?: string, errors?: array<string, list<string>>}  $result
     */
    private function respond(array $result): JsonResponse
    {
        $deposit = $result['deposit'] ?? null;

        return response()->json(array_filter([
            'success' => $result['success'],
            'code' => $result['code'] ?? null,
            'message' => $result['message'],
            'errors' => $result['errors'] ?? null,
            'data' => $deposit ? [
                'id' => $deposit->id,
                'trans_id' => $deposit->trans_id,
                'amount' => (float) $deposit->trans_amount,
                'bill_ref_no' => $deposit->bill_ref_no,
                'status' => (int) $deposit->status,
                'resolution' => $deposit->resolution ? DepositResolutionResource::make($deposit->resolution) : null,
            ] : null,
        ], fn ($value) => $value !== null), $result['status_code']);
    }

    /**
     * The bet shop an unmatched deposit's bill ref names, when it does.
     *
     * @return array{id: int, shop_no: string, name: string, status: string}|null
     */
    private function shopFor(Deposit $deposit): ?array
    {
        $shop = $this->shopFloat->shopFromBillRef($deposit->bill_ref_no);

        return $shop ? ['id' => $shop->id, 'shop_no' => $shop->shop_no, 'name' => $shop->name, 'status' => $shop->status] : null;
    }
}
