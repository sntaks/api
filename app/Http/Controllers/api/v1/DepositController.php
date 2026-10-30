<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDepositRequest;
use App\Http\Resources\DepositResource;
use App\Models\Deposit;
use App\Models\Wallet;
use App\Services\DepositAccountResolver;
use App\Services\ExciseDutyService;
use App\Services\HouseFundingService;
use App\Services\LedgerService;
use App\Services\ReferralService;
use App\Services\WalletDepositService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class DepositController extends Controller
{
    public function __construct(
        private LedgerService $ledgerService,
        private ExciseDutyService $exciseDutyService,
        private ReferralService $referralService,
        private HouseFundingService $houseFunding,
        private DepositAccountResolver $resolver,
        private WalletDepositService $walletDeposits,
    ) {}

    /**
     * All deposits; `matched_by` (account_no, player_no, phone, phone_other_payer) keeps only those matched that way.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $deposits = Deposit::with('exciseDutyCharge')
            ->when($request->filled('matched_by'), fn ($query) => $query->where('matched_by', $request->string('matched_by')->toString()))
            ->get();

        return DepositResource::collection($deposits);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreDepositRequest $request): JsonResponse
    {
        // Check for duplicate trans_id
        $transId = $request->input('TransID');
        if (Deposit::where('trans_id', $transId)->exists()) {
            return response()->json([
                'message' => 'Already processed',
            ], 200);
        }

        // Mapping the incoming validated data to the Deposit table columns
        $depositData = [
            'trans_id' => $request->input('TransID'),
            'trans_type' => $request->input('TransactionType'),
            'trans_time' => date('Y-m-d H:i:s', strtotime($request->input('TransTime'))),
            'trans_amount' => $request->input('TransAmount'),
            'short_code' => $request->input('BusinessShortCode'),
            'bill_ref_no' => $request->input('BillRefNumber'),
            'msisdn' => $request->input('MSISDN'),
            'name' => $request->input('FirstName').' '.$request->input('MiddleName').' '.$request->input('LastName'),
        ];

        // Record the payment
        $deposit = Deposit::create($depositData);

        $amount = $deposit->trans_amount;

        // Same rule as C2B: account number, player number or verified phone. National ID numbers are not matched.
        $match = $this->resolver->resolve($deposit->bill_ref_no, $deposit->msisdn);
        $customer = $match->customer;

        if (! $customer) {
            $deposit->update(['status' => Deposit::STATUS_UNMATCHED, 'unmatched_reason' => $match->unmatchedReason]);

            return response()->json([
                'message' => 'Customer not found. Deposit moved to suspense.',
                'deposit' => $deposit,
            ], 404);
        }

        // Money paid to the house account is owner funding, not a deposit: finance records it as house funding.
        if ((int) $customer->id === $this->houseFunding->houseCustomerId()) {
            $deposit->update(['status' => Deposit::STATUS_UNMATCHED]);

            return response()->json([
                'message' => 'Payment to the house account left unmatched. Record it as house funding.',
                'deposit' => $deposit,
            ], 202);
        }

        // As for a C2B confirmation: the money has arrived, so it is credited, and flagged when the player's own
        // rules would have refused it (D18).
        $canDeposit = $this->walletDeposits->assess($deposit, $customer);

        // Credit, excise duty and transaction row are written together or not at all.
        [$wallet, $transaction, $ledgerEntry] = DB::transaction(function () use ($customer, $deposit, $amount, $match, $canDeposit) {
            $wallet = Wallet::firstOrCreate(
                ['customer_id' => $customer->id],
                ['balance' => 0]
            );
            $wallet = Wallet::lockForUpdate()->find($wallet->id);

            // Record deposit via ledger (handles balance update + ledger entry)
            $ledgerEntry = $this->ledgerService->recordDeposit($deposit, $wallet, (float) $amount);

            $exciseDutyCharge = $this->exciseDutyService->chargeIfApplicable($deposit, $wallet, ExciseDutyService::KIND_WALLET_DEPOSIT);

            $transaction = $wallet->transactions()->create([
                'payment_id' => $deposit->id,
                'payment_ref' => $deposit->trans_id,
                'payment_type' => Deposit::class,
                'amount' => $exciseDutyCharge?->net_amount ?? $amount,
                'status' => 2,
                'balance_before' => $ledgerEntry->balance_before,
                'balance_after' => $wallet->balance,
            ]);

            $deposit->update(['status' => Deposit::STATUS_COMPLETED, 'matched_by' => $match->matchedBy]);

            $this->referralService->recordDeposit($deposit, $customer->id);

            if (! $canDeposit->allowed) {
                $this->walletDeposits->flag($deposit, $customer, $canDeposit);
            }

            return [$wallet, $transaction, $ledgerEntry];
        });

        return response()->json([
            'message' => 'Deposit successful',
            'wallet' => $wallet,
            'transaction' => $transaction,
            'ledger_entry_id' => $ledgerEntry->entry_id,
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show($encryptedIdentifier)
    {
        $deposit = Deposit::with('resolution')->where('id', $encryptedIdentifier)->first();
        if (! $deposit) {
            return response()->json(['message' => 'Payment not found'], 404);
        }

        return DepositResource::make($deposit);
    }
}
