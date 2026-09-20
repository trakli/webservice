<?php

namespace App\Http\Controllers\API\v1\Admin;

use App\Http\Controllers\API\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Whilesmart\Engagement\EngagementManager;
use Whilesmart\Engagement\Support\ClientRegistry;
use Whilesmart\Engagement\Support\Period;

/**
 * @OA\Tag(name="Admin", description="Admin operations")
 */
class MetricsController extends ApiController
{
    #[OA\Get(
        path: '/admin/metrics',
        summary: 'App-wide engagement and usage metrics',
        tags: ['Admin'],
        parameters: [
            new OA\Parameter(name: 'days', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(
                name: 'granularity',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', enum: ['day', 'week', 'month'])
            ),
            new OA\Parameter(name: 'client', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Metrics report'),
            new OA\Response(response: 403, description: 'Not an admin'),
        ]
    )]
    public function show(Request $request, EngagementManager $engagement, ClientRegistry $clients): JsonResponse
    {
        $granularity = in_array($request->query('granularity'), ['day', 'week', 'month'], true)
            ? $request->query('granularity')
            : 'day';
        $days = min(366, max(1, (int) $request->query('days', 30)));
        $client = $request->string('client')->toString();
        abort_if($client !== '' && ! $clients->has($client), 422, 'Unknown client.');
        $client = $client !== '' ? $client : null;

        $report = $engagement->report(Period::lastDays($days, $granularity, $client));

        return $this->success($report, 'Metrics retrieved');
    }
}
