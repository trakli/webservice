<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\API\ApiController;
use App\Http\Traits\ApiQueryable;
use App\Models\Streak;
use App\Services\StreakService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class StreakController extends ApiController
{
    use ApiQueryable;

    #[OA\Get(
        path: '/streaks',
        summary: 'List your daily and weekly transaction and check-in streaks',
        description: 'Current length is zero after a missed period. The previous day or week remains continuable. Reading this endpoint records a check-in.',
        security: [['bearerAuth' => []]],
        tags: ['Streaks'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/limitParam'),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Owner-scoped streaks with flat pagination', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'success', type: 'boolean'),
                    new OA\Property(property: 'message', type: 'string'),
                    new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Streak')),
                        new OA\Property(property: 'current_page', type: 'integer'),
                        new OA\Property(property: 'last_page', type: 'integer'),
                        new OA\Property(property: 'per_page', type: 'integer'),
                        new OA\Property(property: 'total', type: 'integer'),
                    ], type: 'object'),
                ],
                type: 'object'
            )),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(Request $request, StreakService $streaks): JsonResponse
    {
        $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'no_client_id' => ['prohibited'],
        ]);

        $owner = $request->user();
        $query = Streak::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->orderBy('id');

        try {
            $data = $this->applyApiQuery($request, $query, false);
        } catch (\InvalidArgumentException $exception) {
            return $this->failure($exception->getMessage(), 422);
        }

        $data['data'] = array_map(function (Streak $streak) use ($streaks, $owner): array {
            $length = $streaks->effectiveLength($streak, $owner);

            return [
                'id' => $streak->id,
                'type' => $streak->type->value,
                'period' => $streak->period->value,
                'current_length' => $length,
                'longest_length' => $streak->longest_length,
                'started_on' => $streak->started_on?->format('Y-m-d'),
                'last_tracked_on' => $streak->last_tracked_on?->format('Y-m-d'),
                'is_running' => $length >= (int) config('streaks.threshold', 3),
            ];
        }, $data['data']);

        return $this->success($data);
    }
}
