<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fetches live crypto prices from CoinGecko (free, no key). On any API failure
 * it returns empty so callers keep the last known price.
 */
class AssetPriceService
{
    protected string $apiUrl = 'https://api.coingecko.com/api/v3';

    /**
     * Current price per coin id in the given fiat currency.
     *
     * @param  string[]  $coingeckoIds
     * @return array<string, float>  keyed by coingecko id
     */
    public function fetchPrices(array $coingeckoIds, string $vsCurrency): array
    {
        $coingeckoIds = array_values(array_unique(array_filter($coingeckoIds)));
        if ($coingeckoIds === []) {
            return [];
        }

        $vsCurrency = strtolower($vsCurrency);

        try {
            $response = Http::timeout(10)->get("{$this->apiUrl}/simple/price", [
                'ids' => implode(',', $coingeckoIds),
                'vs_currencies' => $vsCurrency,
            ]);

            if (! $response->successful()) {
                Log::warning('CoinGecko price fetch failed', ['status' => $response->status()]);

                return [];
            }

            $prices = [];
            foreach ($response->json() as $id => $quote) {
                if (isset($quote[$vsCurrency])) {
                    $prices[$id] = (float) $quote[$vsCurrency];
                }
            }

            return $prices;
        } catch (\Throwable $e) {
            Log::warning('CoinGecko price fetch error: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Search CoinGecko for coins matching a query so the client can resolve a
     * name to its coingecko id.
     *
     * @return array<int, array{id: string, name: string, symbol: string}>
     */
    public function searchCoins(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        try {
            $response = Http::timeout(10)->get("{$this->apiUrl}/search", ['query' => $query]);

            if (! $response->successful()) {
                return [];
            }

            return collect($response->json('coins') ?? [])
                ->take(20)
                ->map(fn ($coin) => [
                    'id' => $coin['id'] ?? '',
                    'name' => $coin['name'] ?? '',
                    'symbol' => strtoupper($coin['symbol'] ?? ''),
                ])
                ->filter(fn ($coin) => $coin['id'] !== '')
                ->values()
                ->all();
        } catch (\Throwable $e) {
            Log::warning('CoinGecko search error: ' . $e->getMessage());

            return [];
        }
    }
}
