<?php

namespace App\Holdings;

use App\Services\AssetPriceService;
use Whilesmart\Holdings\Contracts\HoldingPriceProvider;

/**
 * Resolves crypto prices through CoinGecko; non-crypto assets return no price.
 */
class CoingeckoPriceProvider implements HoldingPriceProvider
{
    public function __construct(private readonly AssetPriceService $assetPrices)
    {
    }

    public function prices(string $provider, array $externalRefs, string $currency): array
    {
        if ($provider !== 'coingecko') {
            return [];
        }

        return $this->assetPrices->fetchPrices($externalRefs, $currency);
    }
}
