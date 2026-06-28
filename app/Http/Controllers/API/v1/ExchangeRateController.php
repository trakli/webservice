<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\API\ApiController;
use App\Services\ExchangeRateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Currency', description: 'Exchange rates and asset prices')]
class ExchangeRateController extends ApiController
{
    public function __construct(protected ExchangeRateService $exchangeRates)
    {
    }

    #[OA\Get(
        path: '/exchange-rates',
        summary: 'Get current exchange rates from a base currency',
        description: 'Returns rates from the base currency to each requested target, '
            . 'honoring the authenticated user\'s manual exchange rates. Targets with no '
            . 'available rate are listed under "unavailable" rather than returned as a rate.',
        tags: ['Currency'],
        parameters: [
            new OA\Parameter(
                name: 'base',
                in: 'query',
                required: true,
                schema: new OA\Schema(type: 'string', example: 'USD')
            ),
            new OA\Parameter(
                name: 'targets',
                description: 'Comma-separated target currency codes. Use this or "target".',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', example: 'EUR,GBP,JPY')
            ),
            new OA\Parameter(
                name: 'target',
                description: 'A single target currency code. Use this or "targets".',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', example: 'EUR')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Rates retrieved',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'base', type: 'string', example: 'USD'),
                            new OA\Property(
                                property: 'rates',
                                type: 'object',
                                example: ['EUR' => 0.92, 'GBP' => 0.79]
                            ),
                            new OA\Property(
                                property: 'unavailable',
                                type: 'array',
                                items: new OA\Items(type: 'string'),
                                example: ['JPY']
                            ),
                        ], type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'base' => ['required', 'string', 'size:3'],
            'targets' => ['required_without:target', 'string'],
            'target' => ['required_without:targets', 'string', 'size:3'],
        ]);

        $base = strtoupper($validated['base']);

        $targets = collect(explode(',', $validated['targets'] ?? $validated['target']))
            ->map(fn ($code) => strtoupper(trim($code)))
            ->filter()
            ->unique()
            ->values();

        $rates = [];
        $unavailable = [];

        foreach ($targets as $target) {
            $rate = $this->exchangeRates->getRate($base, $target, $request->user());

            if ($rate === null) {
                $unavailable[] = $target;

                continue;
            }

            $rates[$target] = $rate;
        }

        return $this->success([
            'base' => $base,
            'rates' => $rates,
            'unavailable' => $unavailable,
        ]);
    }
}
