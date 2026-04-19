<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\API\ApiController;
use App\Http\Traits\ApiQueryable;
use App\Contracts\OwnerResolver;
use App\Jobs\CloseBudgetPeriodJob;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Group;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Rules\Iso8601DateTime;
use App\Rules\ValidateClientId;
use App\Services\BudgetProgressService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Budget', description: 'Endpoints for managing spending budgets')]
class BudgetController extends ApiController
{
    use ApiQueryable;

    private const TARGET_MAP = [
        'category' => Category::class,
        'group' => Group::class,
        'wallet' => Wallet::class,
    ];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Budget::query()->visibleTo($user);

        if ($request->boolean('active')) {
            $query->active();
        }

        try {
            $data = $this->applyApiQuery($request, $query);

            return $this->success($data);
        } catch (\InvalidArgumentException $e) {
            return $this->failure($e->getMessage(), 422);
        }
    }

    public function show(Request $request, int $budgetId): JsonResponse
    {
        $budget = Budget::query()->visibleTo($request->user())->find($budgetId);

        if (! $budget) {
            return $this->failure(__('Budget not found'), 404);
        }

        return $this->success($budget);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = $this->baseValidator($request, false);

        if ($validator->fails()) {
            return $this->failure(__('Validation error'), 422, $validator->errors()->all());
        }

        $data = $validator->validated();
        $user = $request->user();

        $owner = $this->resolveOwnerForWrite($data['owner'] ?? null, $user);
        if (! $owner) {
            return $this->failure(__('You are not authorized to create budgets for this owner'), 403);
        }

        $this->normalizeDates($data);

        try {
            $budget = DB::transaction(function () use ($data, $request, $user, $owner) {
                /** @var Budget $budget */
                $budget = $owner->budgets()->create([
                    'name' => $data['name'],
                    'description' => $data['description'] ?? null,
                    'amount' => $data['amount'],
                    'currency' => strtoupper($data['currency']),
                    'period_type' => $data['period_type'],
                    'start_date' => $data['start_date'],
                    'end_date' => $data['end_date'] ?? null,
                    'rollover_enabled' => $data['rollover_enabled'] ?? false,
                    'threshold_percent' => $data['threshold_percent'] ?? 80,
                    'forecast_alerts_enabled' => $data['forecast_alerts_enabled'] ?? true,
                    'is_active' => $data['is_active'] ?? true,
                ]);

                if (! empty($data['targets'])) {
                    $this->syncTargets($budget, $data['targets']);
                }

                if (isset($request['client_id'])) {
                    $budget->setClientGeneratedId($request['client_id'], $user);
                }
                $budget->markAsSynced();

                return $budget;
            });

            $budget->refresh();

            return $this->success($budget, __('Budget created successfully'), 201);
        } catch (ValidationException $e) {
            return $this->failure(__('Validation error'), 422, $e->errors());
        } catch (\Exception $e) {
            return $this->failure(__('Failed to create budget'), 500, [$e->getMessage()]);
        }
    }

    public function update(Request $request, int $budgetId): JsonResponse
    {
        $budget = Budget::query()->visibleTo($request->user())->find($budgetId);

        if (! $budget) {
            return $this->failure(__('Budget not found'), 404);
        }

        $validator = $this->baseValidator($request, true);

        if ($validator->fails()) {
            return $this->failure(__('Validation error'), 422, $validator->errors()->all());
        }

        $data = $validator->validated();
        $this->normalizeDates($data);

        try {
            DB::transaction(function () use ($budget, $data, $request) {
                $updatable = array_intersect_key($data, array_flip([
                    'name', 'description', 'amount', 'currency', 'period_type',
                    'start_date', 'end_date', 'rollover_enabled', 'threshold_percent',
                    'forecast_alerts_enabled', 'is_active',
                ]));

                if (isset($updatable['currency'])) {
                    $updatable['currency'] = strtoupper($updatable['currency']);
                }

                $this->checkUpdatedAt($budget, $data);

                $budget->update($updatable);

                if (array_key_exists('targets', $data)) {
                    $budget->categories()->detach();
                    $budget->groups()->detach();
                    $budget->wallets()->detach();
                    $this->syncTargets($budget, $data['targets'] ?? []);
                }

                $this->updateClientId($budget, $request);
            });

            $budget->refresh();

            return $this->success($budget, __('Budget updated successfully'));
        } catch (ValidationException $e) {
            return $this->failure(__('Validation error'), 422, $e->errors());
        } catch (\Exception $e) {
            return $this->failure(__('Failed to update budget'), 500, [$e->getMessage()]);
        }
    }

    public function destroy(Request $request, int $budgetId): JsonResponse
    {
        $budget = Budget::query()->visibleTo($request->user())->find($budgetId);

        if (! $budget) {
            return $this->failure(__('Budget not found'), 404);
        }

        $budget->delete();

        return $this->success(null, __('Budget deleted successfully'), 204);
    }

    public function progress(Request $request, int $budgetId): JsonResponse
    {
        $budget = Budget::query()->visibleTo($request->user())->find($budgetId);

        if (! $budget) {
            return $this->failure(__('Budget not found'), 404);
        }

        /** @var BudgetProgressService $service */
        $service = app(BudgetProgressService::class);

        return $this->success($service->compute($budget));
    }

    /**
     * Transactions that match the budget's targets and fall inside the
     * current period window. Ordered latest-first. Accepts `limit` query
     * (default 50, max 200).
     */
    public function transactions(Request $request, int $budgetId): JsonResponse
    {
        $budget = Budget::query()->visibleTo($request->user())->find($budgetId);

        if (! $budget) {
            return $this->failure(__('Budget not found'), 404);
        }

        /** @var BudgetProgressService $service */
        $service = app(BudgetProgressService::class);
        /** @var OwnerResolver $resolver */
        $resolver = app(OwnerResolver::class);

        [$periodStart, $periodEnd] = $service->resolvePeriodWindow($budget);
        $userIds = $resolver->resolveUserIds($budget->owner);
        $categoryIds = $budget->categories()->pluck('categories.id')->all();
        $groupIds = $budget->groups()->pluck('groups.id')->all();
        $walletIds = $budget->wallets()->pluck('wallets.id')->all();

        if (empty($userIds)) {
            return $this->success([
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'data' => [],
            ]);
        }

        $limit = min(200, max(1, (int) $request->query('limit', 50)));

        $query = Transaction::query()
            ->whereIn('user_id', $userIds)
            ->whereNull('transfer_id')
            ->whereBetween('datetime', [$periodStart, $periodEnd]);

        // No targets = budget covers every transaction in the period.
        // With targets, narrow to those that match at least one.
        if (! empty($categoryIds) || ! empty($groupIds) || ! empty($walletIds)) {
            $query->where(function (Builder $outer) use ($categoryIds, $groupIds, $walletIds) {
                if (! empty($categoryIds)) {
                    $outer->orWhereHas('categories', function (Builder $q) use ($categoryIds) {
                        $q->whereIn('categories.id', $categoryIds);
                    });
                }
                if (! empty($groupIds)) {
                    $outer->orWhereHas('groups', function (Builder $q) use ($groupIds) {
                        $q->whereIn('groups.id', $groupIds);
                    });
                }
                if (! empty($walletIds)) {
                    $outer->orWhereIn('wallet_id', $walletIds);
                }
            });
        }

        $transactions = $query
            ->orderByDesc('datetime')
            ->limit($limit)
            ->get();

        return $this->success([
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'data' => $transactions,
        ]);
    }

    public function closePeriod(Request $request, int $budgetId): JsonResponse
    {
        $budget = Budget::query()->visibleTo($request->user())->find($budgetId);

        if (! $budget) {
            return $this->failure(__('Budget not found'), 404);
        }

        if (! $budget->rollover_enabled) {
            return $this->failure(__('Rollover is not enabled for this budget'), 422);
        }

        CloseBudgetPeriodJob::dispatchSync($budget->id);
        $budget->refresh();

        return $this->success($budget, __('Budget period closed'));
    }

    private function baseValidator(Request $request, bool $forUpdate): \Illuminate\Validation\Validator
    {
        $required = $forUpdate ? 'sometimes|required' : 'required';
        $requiredNullable = $forUpdate ? 'sometimes' : 'nullable';

        $rules = [
            'client_id' => ['nullable', 'string', new ValidateClientId()],
            'owner' => 'sometimes|array',
            'owner.type' => 'required_with:owner|string|in:user',
            'owner.id' => 'required_with:owner|integer',
            'name' => $required . '|string|max:255',
            'description' => $requiredNullable . '|string',
            'amount' => $required . '|numeric|min:0',
            'currency' => $required . '|string|size:3',
            'period_type' => $required . '|string|in:weekly,monthly,yearly,custom',
            'start_date' => $required . '|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'rollover_enabled' => 'sometimes|boolean',
            'threshold_percent' => 'sometimes|integer|between:0,100',
            'forecast_alerts_enabled' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
            'targets' => 'sometimes|array',
            'targets.*.type' => 'required_with:targets|string|in:category,group,wallet',
            'targets.*.id' => 'required_without:targets.*.client_generated_id|integer',
            'targets.*.client_generated_id' => 'nullable|string',
            'updated_at' => ['nullable', new Iso8601DateTime()],
            'created_at' => ['nullable', new Iso8601DateTime()],
        ];

        $validator = Validator::make($request->all(), $rules);

        $validator->after(function ($inner) use ($request) {
            if ($request->input('period_type') === 'custom' && ! $request->input('end_date')) {
                $inner->errors()->add('end_date', __('end_date is required for custom period budgets'));
            }
        });

        return $validator;
    }

    /**
     * Resolve + authorize the write owner. Today: only `user` is accepted,
     * and the caller must match the owner id. Workspace/Couple plug in later
     * by extending UserOwnerResolver and this switch.
     */
    private function resolveOwnerForWrite(?array $ownerPayload, \App\Models\User $caller)
    {
        if ($ownerPayload === null) {
            return $caller;
        }

        $type = $ownerPayload['type'] ?? null;
        $ownerId = $ownerPayload['id'] ?? null;

        if ($type !== 'user') {
            return null;
        }

        if ((int) $ownerId !== $caller->id) {
            return null;
        }

        return $caller;
    }

    private function normalizeDates(array &$data): void
    {
        foreach (['created_at', 'updated_at'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = format_iso8601_to_sql($data[$key]);
            }
        }
    }

    private function syncTargets(Budget $budget, array $targets): void
    {
        $byType = [
            Category::class => [],
            Group::class => [],
            Wallet::class => [],
        ];

        foreach ($targets as $target) {
            $typeKey = $target['type'] ?? null;
            $class = self::TARGET_MAP[$typeKey] ?? null;
            if (! $class) {
                continue;
            }

            $targetId = $target['id'] ?? null;
            if ($targetId) {
                $byType[$class][] = (int) $targetId;
            }
        }

        if (! empty($byType[Category::class])) {
            $budget->categories()->syncWithoutDetaching($byType[Category::class]);
        }
        if (! empty($byType[Group::class])) {
            $budget->groups()->syncWithoutDetaching($byType[Group::class]);
        }
        if (! empty($byType[Wallet::class])) {
            $budget->wallets()->syncWithoutDetaching($byType[Wallet::class]);
        }
    }
}
