<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Requests\PagedListRequest;
use App\Http\Requests\Predict\BulkPredictMarketsRequest;
use App\Http\Requests\Predict\ListPredictMarketsRequest;
use App\Http\Requests\Predict\PredictAdminActionRequest;
use App\Http\Requests\Predict\StorePredictMarketRequest;
use App\Http\Requests\Predict\UpdatePredictMarketRequest;
use App\Http\Resources\Predict\PredictMarketAdminResource;
use App\Models\PredictAuditLog;
use App\Models\PredictCorrection;
use App\Models\PredictMarket;
use App\Models\PredictMarketBlock;
use App\Models\PredictResolutionProposal;
use App\Models\PredictSettlement;
use App\Models\RiskEvent;
use App\Services\Predict\AuditLogger;
use App\Services\Predict\CorrectionService;
use App\Services\Predict\MarketQuery;
use App\Services\Predict\MarketService;
use App\Services\Predict\PredictResult;
use App\Services\Predict\ResolutionService;
use App\Services\Predict\RiskService;
use App\Services\Predict\SettlementService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * GMS (full key): create and run prediction markets. Every write names the admin and is audit-logged.
 */
class PredictAdminController extends PredictController
{
    public function __construct(
        private MarketService $markets,
        private MarketQuery $query,
        private SettlementService $settlements,
        private ResolutionService $resolutions,
        private CorrectionService $corrections,
        private AuditLogger $audit,
    ) {}

    public function index(ListPredictMarketsRequest $request): JsonResponse
    {
        return $this->pagedResponse($this->query->adminList($request->validated()), fn (PredictMarket $market) => PredictMarketAdminResource::make($market)->resolve());
    }

    public function show(string $id): JsonResponse
    {
        $market = PredictMarket::with(['pool', 'product', 'outcomes', 'event', 'settlements' => fn ($q) => $q->orderBy('run_no')])->find($this->routeId($id) ?? 0);

        if (! $market) {
            return $this->refusal('market_not_found', 'No market has that id', 404);
        }

        return response()->json(['success' => true, 'data' => PredictMarketAdminResource::make($market)->resolve() + [
            'settlements' => $market->settlements->map(fn (PredictSettlement $run) => $this->presentRun($run))->values(),
        ]]);
    }

    public function store(StorePredictMarketRequest $request): JsonResponse
    {
        $data = $request->validated();
        $market = $this->markets->createCustom(Arr::except($data, ['admin_user']), $data['admin_user']);

        return response()->json(['success' => true, 'data' => PredictMarketAdminResource::make($market->load(['pool', 'product']))->resolve()], 201);
    }

    public function update(UpdatePredictMarketRequest $request, string $id): JsonResponse
    {
        $market = $this->findMarket($id);

        if (! $market) {
            return $this->refusal('market_not_found', 'No market has that id', 404);
        }

        $data = $request->validated();

        return $this->market($this->markets->update($market, Arr::except($data, ['admin_user', 'reason']), $data['admin_user'], $data['reason'] ?? null));
    }

    public function publish(PredictAdminActionRequest $request, string $id): JsonResponse
    {
        return $this->act($request, $id, fn (PredictMarket $market, array $data) => $this->markets->publish($market, $data['admin_user'], $data['reason'] ?? null));
    }

    /**
     * Publish many drafts (imported ones are approved on fresh prices fetched in one batch).
     */
    public function bulkPublish(BulkPredictMarketsRequest $request): JsonResponse
    {
        $data = $request->validated();

        return $this->bulk($data, fn ($markets) => $this->markets->bulkPublish($markets, $data['admin_user'], $data['reason'] ?? null));
    }

    public function bulkReject(BulkPredictMarketsRequest $request): JsonResponse
    {
        $data = $request->validated();

        return $this->bulk($data, fn ($markets) => $this->markets->bulkReject($markets, $data['admin_user'], $data['reason']));
    }

    public function reject(PredictAdminActionRequest $request, string $id): JsonResponse
    {
        return $this->act($request, $id, fn (PredictMarket $market, array $data) => $this->markets->reject($market, $data['admin_user'], $data['reason']));
    }

    public function suspend(PredictAdminActionRequest $request, string $id): JsonResponse
    {
        return $this->act($request, $id, fn (PredictMarket $market, array $data) => $this->markets->suspend($market, $data['admin_user'], $data['reason'] ?? null));
    }

    public function resume(PredictAdminActionRequest $request, string $id): JsonResponse
    {
        return $this->act($request, $id, fn (PredictMarket $market, array $data) => $this->markets->resume($market, $data['admin_user'], $data['reason'] ?? null));
    }

    public function close(PredictAdminActionRequest $request, string $id): JsonResponse
    {
        return $this->act($request, $id, fn (PredictMarket $market, array $data) => $this->markets->close($market, $data['admin_user'], $data['reason'] ?? null));
    }

    public function resolve(PredictAdminActionRequest $request, string $id): JsonResponse
    {
        return $this->act($request, $id, fn (PredictMarket $market, array $data) => $this->markets->resolve($market, $data['result'], $data['admin_user'], $data['reason'] ?? null));
    }

    public function void(PredictAdminActionRequest $request, string $id): JsonResponse
    {
        return $this->act($request, $id, fn (PredictMarket $market, array $data) => $this->markets->void($market, $data['admin_user'], $data['reason']));
    }

    public function blocks(string $id): JsonResponse
    {
        $market = $this->findMarket($id);

        if (! $market) {
            return $this->refusal('market_not_found', 'No market has that id', 404);
        }

        $blocks = PredictMarketBlock::where('predict_market_id', $market->id)->orderBy('id')->get();

        return response()->json(['success' => true, 'data' => $blocks->map(fn (PredictMarketBlock $block) => [
            'customer_id' => $block->customer_id,
            'reason' => $block->reason,
            'admin_user' => $block->admin_user,
            'created_at' => $block->created_at?->toIso8601String(),
        ])->values()]);
    }

    public function block(PredictAdminActionRequest $request, string $id): JsonResponse
    {
        $market = $this->findMarket($id);

        if (! $market) {
            return $this->refusal('market_not_found', 'No market has that id', 404);
        }

        $data = $request->validated();

        return $this->respond($this->markets->block($market, (int) $data['customer_id'], $data['admin_user'], $data['reason']), fn (array $result) => [
            'customer_id' => $result['block']->customer_id,
            'reason' => $result['block']->reason,
        ]);
    }

    public function unblock(PredictAdminActionRequest $request, string $id, string $customerId): JsonResponse
    {
        $market = $this->findMarket($id);

        if (! $market) {
            return $this->refusal('market_not_found', 'No market has that id', 404);
        }

        $data = $request->validated();

        return $this->respond($this->markets->unblock($market, $this->routeId($customerId) ?? 0, $data['admin_user'], $data['reason'] ?? null), fn () => []);
    }

    /**
     * Queue a failed settlement or refund run again. Safe: only unsettled wagers are touched.
     */
    public function retrySettlement(PredictAdminActionRequest $request, string $settlementId): JsonResponse
    {
        $run = PredictSettlement::find($this->routeId($settlementId) ?? 0);

        if (! $run) {
            return $this->refusal('settlement_not_found', 'No settlement has that id', 404);
        }

        return $this->respond($this->settlements->retry($run), fn (array $result) => ['settlement' => $this->presentRun($result['settlement']->fresh())]);
    }

    /**
     * Polymarket results waiting for an admin (or all, with ?status=).
     */
    public function proposals(PagedListRequest $request): JsonResponse
    {
        $page = PredictResolutionProposal::with('market')
            ->where('status', $request->query('status', PredictResolutionProposal::STATUS_PENDING))
            ->orderBy('id')
            ->paginate((int) ($request->validated()['per_page'] ?? 50))
            ->withQueryString();

        return $this->pagedResponse($page, fn (PredictResolutionProposal $proposal) => $this->presentProposal($proposal));
    }

    public function confirmProposal(PredictAdminActionRequest $request, string $proposalId): JsonResponse
    {
        $proposal = PredictResolutionProposal::find($this->routeId($proposalId) ?? 0);

        if (! $proposal) {
            return $this->refusal('proposal_not_found', 'No proposal has that id', 404);
        }

        $data = $request->validated();

        return $this->respond($this->resolutions->confirm($proposal, $data['admin_user'], $data['reason'] ?? null), fn (array $result) => [
            'proposal' => $this->presentProposal($result['proposal']),
        ]);
    }

    public function rejectProposal(PredictAdminActionRequest $request, string $proposalId): JsonResponse
    {
        $proposal = PredictResolutionProposal::find($this->routeId($proposalId) ?? 0);

        if (! $proposal) {
            return $this->refusal('proposal_not_found', 'No proposal has that id', 404);
        }

        $data = $request->validated();

        return $this->respond($this->resolutions->reject($proposal, $data['admin_user'], $data['reason']), fn (array $result) => [
            'proposal' => $this->presentProposal($result['proposal']),
        ]);
    }

    /**
     * Ask for a settled market's result to be corrected; a different admin must approve it.
     */
    public function requestCorrection(PredictAdminActionRequest $request, string $id): JsonResponse
    {
        $market = $this->findMarket($id);

        if (! $market) {
            return $this->refusal('market_not_found', 'No market has that id', 404);
        }

        $data = $request->validated();

        return $this->respond($this->corrections->request($market, $data['result'], $data['admin_user'], $data['reason']), fn (array $result) => [
            'correction' => $this->presentCorrection($result['correction']),
        ]);
    }

    public function corrections(PagedListRequest $request): JsonResponse
    {
        $page = PredictCorrection::query()
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('id')
            ->paginate((int) ($request->validated()['per_page'] ?? 50))
            ->withQueryString();

        return $this->pagedResponse($page, fn (PredictCorrection $correction) => $this->presentCorrection($correction));
    }

    public function approveCorrection(PredictAdminActionRequest $request, string $correctionId): JsonResponse
    {
        return $this->correctionAction($correctionId, fn (PredictCorrection $correction) => $this->corrections->approve($correction, $request->validated()['admin_user'], $request->validated()['reason'] ?? null));
    }

    public function cancelCorrection(PredictAdminActionRequest $request, string $correctionId): JsonResponse
    {
        return $this->correctionAction($correctionId, fn (PredictCorrection $correction) => $this->corrections->cancel($correction, $request->validated()['admin_user'], $request->validated()['reason']));
    }

    public function retryCorrection(PredictAdminActionRequest $request, string $correctionId): JsonResponse
    {
        return $this->correctionAction($correctionId, fn (PredictCorrection $correction) => $this->corrections->retry($correction, $request->validated()['admin_user']));
    }

    /**
     * @param  callable(PredictCorrection): PredictResult  $action
     */
    private function correctionAction(string $correctionId, callable $action): JsonResponse
    {
        $correction = PredictCorrection::find($this->routeId($correctionId) ?? 0);

        if (! $correction) {
            return $this->refusal('correction_not_found', 'No correction has that id', 404);
        }

        return $this->respond($action($correction), fn (array $result) => ['correction' => $this->presentCorrection($result['correction']->fresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentProposal(PredictResolutionProposal $proposal): array
    {
        return [
            'id' => $proposal->id,
            'market_id' => $proposal->predict_market_id,
            'question' => $proposal->market?->question,
            'market_status' => $proposal->market?->status,
            'result' => $proposal->result,
            'evidence' => $proposal->evidence,
            'status' => $proposal->status,
            'reviewed_by' => $proposal->reviewed_by,
            'review_reason' => $proposal->review_reason,
            'reviewed_at' => $proposal->reviewed_at?->toIso8601String(),
            'created_at' => $proposal->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentCorrection(PredictCorrection $correction): array
    {
        return [
            'id' => $correction->id,
            'market_id' => $correction->predict_market_id,
            'from_result' => $correction->from_result,
            'to_result' => $correction->to_result,
            'reason' => $correction->reason,
            'status' => $correction->status,
            'requested_by' => $correction->requested_by,
            'approved_by' => $correction->approved_by,
            'approved_at' => $correction->approved_at?->toIso8601String(),
            'wagers_corrected' => $correction->wagers_corrected,
            'attempts' => $correction->attempts,
            'error' => $correction->error,
            'finished_at' => $correction->finished_at?->toIso8601String(),
            'created_at' => $correction->created_at?->toIso8601String(),
        ];
    }

    /**
     * Risk flags raised by prediction bets, newest first; unresolved only unless ?resolved=1.
     */
    public function riskEvents(PagedListRequest $request): JsonResponse
    {
        $page = RiskEvent::query()
            ->whereIn('event_type', RiskService::TYPES)
            ->when(! $request->boolean('resolved'), fn ($query) => $query->whereNull('resolved_at'))
            ->when(in_array($request->query('type'), RiskService::TYPES, true), fn ($query) => $query->where('event_type', $request->query('type')))
            ->when(ctype_digit((string) $request->query('customer_id')), fn ($query) => $query->where('customer_id', (int) $request->query('customer_id')))
            ->when(ctype_digit((string) $request->query('market_id')), fn ($query) => $query->where('details->market_id', (int) $request->query('market_id')))
            ->orderByDesc('id')
            ->paginate((int) ($request->validated()['per_page'] ?? 50))
            ->withQueryString();

        return $this->pagedResponse($page, fn (RiskEvent $event) => $this->presentRiskEvent($event));
    }

    /**
     * Close a risk flag once reviewed (the reason is kept on the flag and in the audit log).
     */
    public function resolveRiskEvent(PredictAdminActionRequest $request, string $riskEventId): JsonResponse
    {
        $event = RiskEvent::whereIn('event_type', RiskService::TYPES)->find($this->routeId($riskEventId) ?? 0);

        if (! $event) {
            return $this->refusal('risk_event_not_found', 'No prediction risk flag has that id', 404);
        }

        if ($event->resolved_at !== null) {
            return $this->refusal('risk_event_resolved', 'This flag is already resolved', 409);
        }

        $data = $request->validated();
        $event->update([
            'resolved_at' => now(),
            'action_taken' => 'reviewed',
            'details' => ($event->details ?? []) + ['resolved_by' => $data['admin_user'], 'resolution' => $data['reason']],
        ]);
        $market = isset($event->details['market_id']) ? PredictMarket::find($event->details['market_id']) : null;
        $this->audit->record('resolve_risk_event', $market, $data['admin_user'], $data['reason'], null, ['risk_event_id' => $event->id]);

        return response()->json(['success' => true, 'data' => ['risk_event' => $this->presentRiskEvent($event)]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentRiskEvent(RiskEvent $event): array
    {
        return [
            'id' => $event->id,
            'type' => $event->event_type,
            'severity' => $event->severity,
            'score' => $event->score,
            'customer_id' => $event->customer_id,
            'market_id' => $event->details['market_id'] ?? null,
            'details' => $event->details,
            'action_taken' => $event->action_taken,
            'resolved_at' => $event->resolved_at?->toIso8601String(),
            'created_at' => $event->created_at?->toIso8601String(),
        ];
    }

    public function audit(PagedListRequest $request): JsonResponse
    {
        $page = PredictAuditLog::query()
            ->when($request->query('market_id'), fn ($query, $marketId) => $query->where('predict_market_id', (int) $marketId))
            ->orderByDesc('id')
            ->paginate((int) ($request->validated()['per_page'] ?? 50))
            ->withQueryString();

        return $this->pagedResponse($page, fn (PredictAuditLog $log) => [
            'id' => $log->id,
            'market_id' => $log->predict_market_id,
            'action' => $log->action,
            'admin_user' => $log->admin_user,
            'reason' => $log->reason,
            'before' => $log->before,
            'after' => $log->after,
            'api_key_id' => $log->api_key_id,
            'ip' => $log->ip,
            'created_at' => $log->created_at?->toIso8601String(),
        ]);
    }

    public function report(ListPredictMarketsRequest $request): JsonResponse
    {
        return $this->pagedResponse($this->query->report($request->validated()), fn (array $line) => $line);
    }

    /**
     * @param  callable(PredictMarket, array<string, mixed>): PredictResult  $action
     */
    private function act(PredictAdminActionRequest $request, string $id, callable $action): JsonResponse
    {
        $market = $this->findMarket($id);

        if (! $market) {
            return $this->refusal('market_not_found', 'No market has that id', 404);
        }

        return $this->market($action($market, $request->validated()));
    }

    /**
     * Run a bulk action on the drafts the request selects, oldest first, at most `limit` of them. A filter call
     * starts after `after_id` and answers `next_after_id` (the last draft tried) while matching drafts lie beyond
     * it, so repeat calls walk the whole queue instead of retrying the drafts that were refused.
     *
     * @param  array<string, mixed>  $data
     * @param  callable(Collection<int, PredictMarket>): array{done: list<int>, refused: list<array{id: int, code: string}>}  $action
     */
    private function bulk(array $data, callable $action): JsonResponse
    {
        $limit = (int) ($data['limit'] ?? BulkPredictMarketsRequest::MAX);
        $selected = fn () => $this->bulkSelection($data);
        $markets = $selected()
            ->when(isset($data['after_id']), fn (Builder $q) => $q->where('id', '>', $data['after_id']))
            ->orderBy('id')
            ->limit($limit)
            ->get();
        $result = $action($markets);
        $lastId = isset($data['ids']) ? null : $markets->last()?->id;

        return response()->json(['success' => true, 'data' => $result + [
            'remaining' => isset($data['ids']) ? 0 : $selected()->count(),
            'next_after_id' => $lastId !== null && $selected()->where('id', '>', $lastId)->exists() ? $lastId : null,
        ]]);
    }

    /**
     * Drafts by id, or drafts matching the filter. Volume is Polymarket's trading volume, so a volume filter
     * selects imported markets only.
     *
     * @param  array<string, mixed>  $data
     */
    private function bulkSelection(array $data): Builder
    {
        $filter = $data['filter'] ?? [];

        return PredictMarket::query()
            ->where('status', PredictMarket::STATUS_DRAFT)
            ->when(isset($data['ids']), fn (Builder $q) => $q->whereIn('id', $data['ids']))
            ->when($filter['source'] ?? null, fn (Builder $q, string $source) => $q->where('source', $source))
            ->when($filter['category'] ?? null, fn (Builder $q, string $category) => $q->where('category', $category))
            ->when(isset($filter['min_volume']), fn (Builder $q) => $q->where('external_volume', '>=', $filter['min_volume']))
            ->when(isset($filter['max_volume']), fn (Builder $q) => $q->where('external_volume', '<', $filter['max_volume']));
    }

    private function findMarket(string $id): ?PredictMarket
    {
        $marketId = $this->routeId($id);

        return $marketId === null ? null : PredictMarket::find($marketId);
    }

    private function market(PredictResult $result): JsonResponse
    {
        return $this->respond($result, fn (array $data) => ['market' => PredictMarketAdminResource::make($data['market']->load(['pool', 'product', 'outcomes', 'event']))->resolve()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentRun(PredictSettlement $run): array
    {
        return [
            'id' => $run->id,
            'run_no' => $run->run_no,
            'kind' => $run->kind,
            'result' => $run->result,
            'status' => $run->status,
            'final_multiplier' => $run->final_multiplier_x100 !== null ? $run->final_multiplier_x100 / 100 : null,
            'winners_count' => $run->winners_count,
            'user_stakes' => $run->user_stake_minor / 100,
            'payouts' => $run->payout_minor / 100,
            'house_net' => $run->house_net_minor / 100,
            'attempts' => $run->attempts,
            'error' => $run->error,
            'admin_user' => $run->admin_user,
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }
}
