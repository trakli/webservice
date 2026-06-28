<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\API\ApiController;
use App\Services\AssetPriceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class AssetPriceController extends ApiController
{
    public function __construct(protected AssetPriceService $assetPrices)
    {
    }

    #[OA\Get(path: '/asset-prices/search', summary: 'Search CoinGecko for a coin id', tags: ['Holdings'], responses: [
        new OA\Response(response: 200, description: 'Matching coins'),
    ])]
    public function search(Request $request): JsonResponse
    {
        $request->validate(['q' => 'required|string|min:1']);

        return $this->success($this->assetPrices->searchCoins((string) $request->query('q')));
    }

    #[OA\Get(
        path: '/asset-prices',
        summary: 'Get current prices for assets',
        description: 'Returns current CoinGecko prices for the given coin ids in the requested '
            . 'fiat currency, so clients value holdings against the same feed the server uses.',
        tags: ['Holdings'],
        parameters: [
            new OA\Parameter(
                name: 'ids',
                description: 'Comma-separated CoinGecko coin ids.',
                in: 'query',
                required: true,
                schema: new OA\Schema(type: 'string', example: 'bitcoin,ethereum')
            ),
            new OA\Parameter(
                name: 'vs_currency',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', default: 'usd', example: 'usd')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Prices retrieved',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'vs_currency', type: 'string', example: 'usd'),
                            new OA\Property(
                                property: 'prices',
                                type: 'object',
                                example: ['bitcoin' => 65000.0, 'ethereum' => 3200.0]
                            ),
                        ], type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function prices(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => 'required|string|min:1',
            'vs_currency' => 'sometimes|string|size:3',
        ]);

        $ids = array_filter(array_map('trim', explode(',', (string) $request->query('ids'))));
        $vsCurrency = (string) ($request->query('vs_currency') ?? 'usd');

        return $this->success([
            'vs_currency' => strtolower($vsCurrency),
            'prices' => $this->assetPrices->fetchPrices($ids, $vsCurrency),
        ]);
    }
}
