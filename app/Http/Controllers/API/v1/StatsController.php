<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\API\ApiController;
use App\Http\Traits\ResolvesStatsQuery;
use App\Services\StatsService;
use App\Services\MoneyFlowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Statistics', description: 'Financial statistics and analytics')]
class StatsController extends ApiController
{
    use ResolvesStatsQuery;

    public function __construct(
        protected StatsService $statsService
    ) {
    }

    #[OA\Get(path: '/stats/flows', tags: ['Statistics'], parameters: [new OA\Parameter(name: 'from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date'))], responses: [new OA\Response(response: 200, description: 'Money flow nodes and edges')])]
    public function flows(Request $request, MoneyFlowService $flows): JsonResponse
    {
        $request->validate(['from' => 'sometimes|date_format:Y-m-d', 'to' => 'sometimes|date_format:Y-m-d|after_or_equal:from']);
        $range = new Request([
            'start_date' => $request->input('from', now()->startOfMonth()->toDateString()),
            'end_date' => $request->input('to', now()->endOfMonth()->toDateString()),
        ]);
        [$from, $to] = $this->resolveDateRange($range);
        if ($from->gt($to)) {
            return $this->failure(__('The start date must not follow the end date.'), 422);
        }
        $walletIds = $this->resolveWalletIds($request, $request->user());
        if ($walletIds instanceof JsonResponse) {
            return $walletIds;
        }
        $currency = $request->user()->getConfigValue('default-currency') ?? 'USD';
        return $this->success($flows->compute($request->user(), $from, $to, $walletIds, $currency));
    }

    #[OA\Get(
        path: '/stats',
        summary: 'Get financial statistics',
        tags: ['Statistics'],
        parameters: [
            new OA\Parameter(
                name: 'start_date',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', format: 'date')
            ),
            new OA\Parameter(
                name: 'end_date',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', format: 'date')
            ),
            new OA\Parameter(
                name: 'wallet_ids',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', description: 'Comma-separated wallet IDs')
            ),
            new OA\Parameter(
                name: 'period',
                in: 'query',
                required: false,
                schema: new OA\Schema(
                    type: 'string',
                    enum: ['day', 'week', 'month', 'year'],
                    default: 'month'
                )
            ),
            new OA\Parameter(
                name: 'preset',
                in: 'query',
                required: false,
                schema: new OA\Schema(
                    type: 'string',
                    enum: ['all_time', 'current_week', 'current_month', 'last_3_months'],
                    description: 'Preset date range (overrides start_date/end_date)'
                )
            ),
            new OA\Parameter(
                name: 'section',
                in: 'query',
                required: false,
                schema: new OA\Schema(
                    type: 'string',
                    enum: ['overview', 'activity', 'comparisons', 'categories', 'parties', 'cashflow', 'position'],
                    description: 'Compute only one section of the response for progressive loading. Omit for the full payload.'
                )
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Statistics retrieved successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(
                                property: 'currency',
                                description: 'Currency used for all amounts',
                                type: 'string'
                            ),
                            new OA\Property(property: 'position', type: 'object', properties: [
                                new OA\Property(property: 'loan_given', type: 'number'),
                                new OA\Property(property: 'loan_collected', type: 'number'),
                                new OA\Property(property: 'receivables', type: 'number'),
                                new OA\Property(property: 'liabilities', type: 'number'),
                                new OA\Property(property: 'total_net_worth', type: 'number'),
                            ]),
                            new OA\Property(property: 'overview', properties: [
                                new OA\Property(property: 'total_balance', type: 'number', format: 'float'),
                                new OA\Property(property: 'net_worth', type: 'number', format: 'float'),
                                new OA\Property(property: 'total_income', type: 'number', format: 'float'),
                                new OA\Property(property: 'total_expenses', type: 'number', format: 'float'),
                                new OA\Property(property: 'net_cash_flow', type: 'number', format: 'float'),
                                new OA\Property(property: 'avg_monthly_income', type: 'number', format: 'float'),
                                new OA\Property(property: 'avg_monthly_expenses', type: 'number', format: 'float'),
                                new OA\Property(property: 'savings_rate', type: 'number', format: 'float'),
                            ], type: 'object'),
                        ], type: 'object'),
                    ]
                )
            ),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        [$startDate, $endDate] = $this->resolveDateRange($request);

        $walletIds = $this->resolveWalletIds($request, $user);
        if ($walletIds instanceof JsonResponse) {
            return $walletIds;
        }

        $defaultCurrency = $user->getConfigValue('default-currency') ?? 'USD';
        $period = $request->input('period', 'month');

        $section = $request->input('section');
        if ($section !== null && ! in_array($section, StatsService::SECTIONS, true)) {
            return $this->failure(__('Invalid stats section.'), 422, [
                'invalid_section' => $section,
                'valid_sections' => StatsService::SECTIONS,
            ]);
        }

        $cacheKey = StatsService::generateCacheKey(
            $user->id,
            $startDate,
            $endDate,
            $walletIds,
            $period,
            $section
        );

        $data = Cache::remember(
            $cacheKey,
            now()->addMinutes(5),
            fn () => $this->statsService->compute(
                $user,
                $startDate,
                $endDate,
                $walletIds,
                $period,
                $defaultCurrency,
                $section
            )
        );

        return $this->success($data);
    }
}
