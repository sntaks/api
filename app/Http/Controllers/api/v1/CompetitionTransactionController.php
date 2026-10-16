<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PagedListRequest;
use App\Http\Requests\StoreCompetitionTransactionRequest;
use App\Http\Requests\UpdateCompetitionTransactionRequest;
use App\Http\Resources\CompetitionTransactionResource;
use App\Services\CompetitionWalletService;
use Illuminate\Support\Facades\Log;

class CompetitionTransactionController extends Controller
{
    public function __construct(
        private CompetitionWalletService $walletService
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(PagedListRequest $request)
    {
        return CompetitionTransactionResource::collection($this->walletService->listCompetitionTransactions($request->perPage()));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreCompetitionTransactionRequest $request)
    {
        try {
            $this->walletService->createCompetitionTransaction($request->stakeData());

            return response()->json(['status' => 'Success'], 201);
        } catch (\InvalidArgumentException $e) {
            $message = $e->getMessage();
            $status = match (true) {
                str_contains($message, 'Not Found') => 404,
                default => 400,
            };

            return response()->json(['status' => $message], $status);
        } catch (\Exception $e) {
            Log::error('Create Competition Transaction Error: ', ['error' => $e->getMessage()]);

            return response()->json(['status' => 'Error creating Competition Transaction'], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show($encryptedIdentifier)
    {
        $competitionTransaction = $this->walletService->getCompetitionTransaction($encryptedIdentifier);
        if (! $competitionTransaction) {
            return response()->json(['status' => 'Competition Transaction not found'], 404);
        }

        return CompetitionTransactionResource::make($competitionTransaction);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateCompetitionTransactionRequest $request, $encryptedIdentifier)
    {
        $competitionTransaction = $this->walletService->updateCompetitionTransaction($encryptedIdentifier, $request->validated());
        if (! $competitionTransaction) {
            return response()->json(['status' => 'Competition Transaction not found'], 404);
        }

        return CompetitionTransactionResource::make($competitionTransaction);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($encryptedIdentifier)
    {
        $deleted = $this->walletService->deleteCompetitionTransaction($encryptedIdentifier);
        if (! $deleted) {
            return response()->json(['status' => 'Competition Transaction not found'], 404);
        }

        return response()->json(['status' => 'Success'], 200);
    }
}
