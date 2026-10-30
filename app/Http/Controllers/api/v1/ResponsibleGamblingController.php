<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerExclusion;
use App\Models\DepositLimit;
use App\Models\RiskEvent;
use App\Services\DepositAllowance;
use App\Services\DepositLimitService;
use App\Services\ExclusionService;
use App\Services\PlayerGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Responsible gambling for one player: kadi (site key) shows the player's state and starts a break; GMS
 * (full key) reads the history of breaks.
 */
class ResponsibleGamblingController extends Controller
{
    /** The risk_events types WalletDepositService writes for a deposit it credited but the player's rules would refuse. */
    public const FLAG_TYPES = ['rg_deposit_inactive', 'rg_deposit_while_excluded', 'rg_deposit_over_limit'];

    public function __construct(
        private ExclusionService $exclusions,
        private PlayerGate $gate,
        private DepositLimitService $limits,
        private DepositAllowance $allowance,
    ) {}

    public function show(string $encryptedIdentifier): JsonResponse
    {
        $customer = Customer::find($encryptedIdentifier);

        if (! $customer) {
            return response()->json(['success' => false, 'message' => 'Customer not found'], 404);
        }

        $exclusion = $customer->activeExclusion();

        return response()->json(['success' => true, 'data' => [
            'status' => (int) $customer->status,
            'can_play' => $this->gate->canPlay($customer)->allowed,
            'exclusion' => $exclusion ? ['type' => $exclusion->type, 'ends_at' => $exclusion->ends_at->toIso8601String()] : null,
            'deposit_limits' => $this->allowance->limits($customer)
                ->map(fn (DepositLimit $limit) => $this->limits->present($limit) + ['remaining' => $this->allowance->remainingUnder($customer, $limit)])
                ->values()
                ->all(),
            'deposit_remaining' => $this->allowance->remaining($customer),
        ]]);
    }

    /**
     * The player sets, cuts, raises or removes (amount null) one limit. A cut applies now; a raise or a
     * removal waits 24 hours.
     */
    public function setDepositLimit(Request $request, string $encryptedIdentifier, string $period): JsonResponse
    {
        if (! array_key_exists($period, config('responsible_gambling.deposit_limit_periods'))) {
            return response()->json(['success' => false, 'message' => 'Unknown period: use day, week or month'], 404);
        }

        $validated = $request->validate([
            'amount' => ['present', 'nullable', 'integer', 'min:'.config('responsible_gambling.limit_min'), 'max:'.config('responsible_gambling.limit_max')],
        ]);

        $customer = Customer::find($encryptedIdentifier);

        if (! $customer) {
            return response()->json(['success' => false, 'message' => 'Customer not found'], 404);
        }

        $limit = $this->limits->set($customer, $period, $validated['amount'], $this->actorFor($request));

        return response()->json(['success' => true, 'message' => 'Deposit limit saved', 'data' => $this->limits->present($limit)]);
    }

    public function storeExclusion(Request $request, string $encryptedIdentifier): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(array_keys(config('responsible_gambling.exclusions')))],
            'duration' => ['required', 'string', Rule::in(array_keys(config('responsible_gambling.exclusions.'.$request->input('type'), [])))],
        ]);

        $customer = Customer::find($encryptedIdentifier);

        if (! $customer) {
            return response()->json(['success' => false, 'message' => 'Customer not found'], 404);
        }

        $result = $this->exclusions->start($customer, $validated['type'], $validated['duration'], $this->actorFor($request));

        if ($result['refusal']) {
            return $result['refusal']->toResponse();
        }

        return response()->json([
            'success' => true,
            'message' => 'Break started',
            'data' => $this->present($result['exclusion']),
        ], 201);
    }

    public function exclusions(Request $request, string $encryptedIdentifier): JsonResponse
    {
        $perPage = (int) ($request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:200']])['per_page'] ?? 50);

        if (! Customer::withTrashed()->whereKey($encryptedIdentifier)->exists()) {
            return response()->json(['success' => false, 'message' => 'Customer not found'], 404);
        }

        $page = CustomerExclusion::where('customer_id', $encryptedIdentifier)->orderByDesc('id')->paginate($perPage);

        return $this->pagedResponse($page, fn (CustomerExclusion $exclusion) => $this->present($exclusion) + ['actor' => $exclusion->actor]);
    }

    /**
     * GMS: deposits credited although the player's own rules would have refused them (paybill money that had
     * already arrived), newest first.
     */
    public function flags(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'customer' => ['nullable', 'integer'],
            'type' => ['nullable', 'string', Rule::in(self::FLAG_TYPES)],
            'resolved' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $page = RiskEvent::query()
            ->whereIn('event_type', self::FLAG_TYPES)
            ->when(isset($filters['customer']), fn ($query) => $query->where('customer_id', $filters['customer']))
            ->when(isset($filters['type']), fn ($query) => $query->where('event_type', $filters['type']))
            ->when(isset($filters['resolved']), fn ($query) => $filters['resolved'] ? $query->whereNotNull('resolved_at') : $query->whereNull('resolved_at'))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 50));

        return $this->pagedResponse($page, fn (RiskEvent $flag) => $this->presentFlag($flag));
    }

    public function resolveFlag(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'admin_user' => ['required', 'string', 'max:100'],
            'note' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $flag = ctype_digit($id) ? RiskEvent::whereIn('event_type', self::FLAG_TYPES)->find((int) $id) : null;

        if (! $flag) {
            return response()->json(['success' => false, 'message' => 'Flag not found'], 404);
        }

        if ($flag->resolved_at === null) {
            $flag->forceFill([
                'resolved_at' => now(),
                'details' => ($flag->details ?? []) + ['resolution' => [
                    'admin_user' => $validated['admin_user'],
                    'note' => $validated['note'],
                    'actor' => $this->actorFor($request),
                ]],
            ])->save();
        }

        return response()->json(['success' => true, 'message' => 'Flag resolved', 'data' => $this->presentFlag($flag->fresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentFlag(RiskEvent $flag): array
    {
        return [
            'id' => $flag->id,
            'type' => $flag->event_type,
            'customer_id' => $flag->customer_id,
            'details' => $flag->details,
            'created_at' => $flag->created_at?->toIso8601String(),
            'resolved_at' => $flag->resolved_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{type: string, duration: string, starts_at: string, ends_at: string}
     */
    private function present(CustomerExclusion $exclusion): array
    {
        return [
            'type' => $exclusion->type,
            'duration' => $exclusion->duration,
            'starts_at' => $exclusion->starts_at->toIso8601String(),
            'ends_at' => $exclusion->ends_at->toIso8601String(),
        ];
    }
}
