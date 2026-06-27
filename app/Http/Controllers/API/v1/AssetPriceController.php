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
}
