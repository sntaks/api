<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PagedListRequest;
use App\Http\Requests\StoreCompetitionWalletRequest;
use App\Http\Requests\UpdateCompetitionWalletRequest;
use App\Http\Resources\CompetitionWalletResource;
use App\Services\CompetitionWalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CompetitionWalletController extends Controller
{
    public function __construct(
        private CompetitionWalletService $walletService
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(PagedListRequest $request)
    {
        return CompetitionWalletResource::collection($this->walletService->listCompetitionWallets($request->perPage()));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreCompetitionWalletRequest $request)
    {
        try {
            $competitionWallet = $this->walletService->createCompetitionWallet($request->validated());

            return response()->json(['status' => 'Success', 'competition_wallet_id' => $competitionWallet->id], 201);
        } catch (\Exception $e) {
            Log::error('Create Competition Wallet Error: ', ['error' => $e->getMessage()]);

            return response()->json(['status' => 'Error creating Competition Wallet'], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show($encryptedIdentifier)
    {
        $competitionWallet = $this->walletService->getCompetitionWallet($encryptedIdentifier);
        if (! $competitionWallet) {
            return response()->json(['status' => 'Competition Wallet not found'], 404);
        }

        return CompetitionWalletResource::make($competitionWallet);
    }

    /**
     * Display the specified resource.
     */
    public function show_competitions($encryptedIdentifier)
    {
        $competitionWallets = $this->walletService->getCompetitionsByCmpUid($encryptedIdentifier);

        return CompetitionWalletResource::collection($competitionWallets);
    }

    public function competition_income(Request $request, $encryptedIdentifier)
    {
        $incomes = $this->walletService->getCompetitionIncome(
            $encryptedIdentifier,
            $request->input('start_date'),
            $request->input('end_date')
        );

        return response()->json(['status' => 'Success', 'data' => $incomes]);
    }

    public function competition_results($encryptedIdentifier)
    {
        $results = $this->walletService->getCompetitionResults($encryptedIdentifier);

        return response()->json(['status' => 'Success', 'data' => $results]);
    }

    public function competition_awards($encryptedIdentifier): JsonResponse
    {
        $results = $this->walletService->getCompetitionAwards($encryptedIdentifier);

        return response()->json(['status' => 'Success', 'data' => $results]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateCompetitionWalletRequest $request, $encryptedIdentifier)
    {
        $competitionWallet = $this->walletService->updateCompetitionWallet($encryptedIdentifier, $request->validated(), $this->actorFor($request));
        if (! $competitionWallet) {
            return response()->json(['status' => 'Competition Wallet not found'], 404);
        }

        return CompetitionWalletResource::make($competitionWallet);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($encryptedIdentifier)
    {
        $deleted = $this->walletService->deleteCompetitionWallet($encryptedIdentifier);
        if (! $deleted) {
            return response()->json(['status' => 'Competition Wallet not found'], 404);
        }

        return response()->json(['status' => 'Success'], 200);
    }
}
