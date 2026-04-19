<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\API\ApiController;
use App\Http\Traits\ApiQueryable;
use App\Models\Budget;
use App\Models\BudgetPeriodState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Read-only sync endpoint for BudgetPeriodState. Kept separate from
 * BudgetController so the main CRUD class stays under the project's
 * coupling budget — and because this table is server-authored; there
 * is no corresponding write endpoint here.
 */
#[OA\Tag(name: 'Budget', description: 'Endpoints for managing spending budgets')]
class BudgetPeriodStateController extends ApiController
{
    use ApiQueryable;

    #[OA\Get(
        path: '/budget-period-states',
        summary: 'List closed-period snapshots for rollover-enabled budgets',
        tags: ['Budget'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/limitParam'),
            new OA\Parameter(ref: '#/components/parameters/syncedSinceParam'),
            new OA\Parameter(ref: '#/components/parameters/noClientIdParam'),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Period states visible to the user',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/BudgetPeriodState')
                        ),
                    ],
                    type: 'object'
                )
            ),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $visibleBudgetIds = Budget::query()
            ->visibleTo($request->user())
            ->pluck('id');

        $query = BudgetPeriodState::query()->whereIn('budget_id', $visibleBudgetIds);

        try {
            $data = $this->applyApiQuery($request, $query);

            return $this->success($data);
        } catch (\InvalidArgumentException $e) {
            return $this->failure($e->getMessage(), 422);
        }
    }
}
