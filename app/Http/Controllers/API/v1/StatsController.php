<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\API\ApiController;
use App\Models\Wallet;
use App\Services\StatsService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Statistics', description: 'Financial statistics and analytics')]
class StatsController extends ApiController
{
    public function __construct(
        protected StatsService $statsService
    ) {
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
                    enum: ['overview', 'activity', 'comparisons', 'categories', 'parties', 'cashflow'],
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
            return response()->json([
                'message' => __('Invalid stats section.'),
                'invalid_section' => $section,
                'valid_sections' => StatsService::SECTIONS,
            ], 422);
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

        return response()->json(['data' => $data]);
    }

    private function resolveDateRange(Request $request): array
    {
        $endDate = Carbon::now()->endOfDay();
        $startDate = Carbon::now()->subDays(30)->startOfDay();

        if ($request->has('preset')) {
            $endDate = Carbon::now()->endOfDay();

            $startDate = match ($request->input('preset')) {
                'all_time' => Carbon::parse('2000-01-01')->startOfDay(),
                'current_week' => Carbon::now()->startOfWeek()->startOfDay(),
                'current_month' => Carbon::now()->startOfMonth()->startOfDay(),
                'last_3_months' => Carbon::now()->subMonths(3)->startOfDay(),
                default => Carbon::now()->subDays(30)->startOfDay(),
            };
        } else {
            if ($request->has('start_date')) {
                $startDate = Carbon::parse($request->input('start_date'))->startOfDay();
            }
            if ($request->has('end_date')) {
                $endDate = Carbon::parse($request->input('end_date'))->endOfDay();
            }
        }

        return [$startDate, $endDate];
    }

    /**
     * @return array|JsonResponse
     */
    private function resolveWalletIds(Request $request, $user): array|JsonResponse
    {
        if (! $request->has('wallet_ids')) {
            return [];
        }

        $walletIds = array_filter(array_map('intval', explode(',', $request->input('wallet_ids'))));

        $validWalletIds = Wallet::where('user_id', $user->id)
            ->whereIn('id', $walletIds)
            ->pluck('id')
            ->toArray();

        $invalidWalletIds = array_diff($walletIds, $validWalletIds);
        if (! empty($invalidWalletIds)) {
            return response()->json([
                'message' => __('One or more wallet IDs are invalid or do not belong to the user.'),
                'invalid_wallet_ids' => array_values($invalidWalletIds),
            ], 422);
        }

        return $walletIds;
    }
}
