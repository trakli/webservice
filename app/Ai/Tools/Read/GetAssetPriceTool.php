<?php

namespace App\Ai\Tools\Read;

use App\Services\AssetPriceService;
use Whilesmart\Agents\Enums\ParameterType;
use Whilesmart\Agents\Enums\ToolPermission;
use Whilesmart\Agents\Tools\AbstractTool;
use Whilesmart\Agents\ValueObjects\ParameterSpec;
use Whilesmart\Agents\ValueObjects\ToolContext;

/**
 * Looks up live asset prices from the same CoinGecko feed the server uses. Pass
 * `query` to resolve a coin name to its id, then `ids` to fetch current prices.
 * Use it to value holdings; never invent a price.
 */
class GetAssetPriceTool extends AbstractTool
{
    public function __construct(private AssetPriceService $assetPrices)
    {
    }

    public function name(): string
    {
        return 'get_asset_price';
    }

    public function description(): string
    {
        return 'Look up live crypto/asset prices. Pass "query" (a coin name or symbol) to '
            . 'find its CoinGecko id, and/or "ids" to fetch current prices in a fiat '
            . 'currency. Returns authoritative prices; never invent figures.';
    }

    public function parameters(): array
    {
        return [
            ParameterSpec::string('query', 'Coin name or symbol to resolve to a CoinGecko id, e.g. "bitcoin".', required: false),
            ParameterSpec::arrayOf('ids', 'CoinGecko coin ids to price, e.g. ["bitcoin", "ethereum"].', ParameterType::STRING, required: false),
            ParameterSpec::string('vs_currency', 'Fiat currency for prices. Defaults to "usd".', required: false),
        ];
    }

    public function permission(): ToolPermission
    {
        return ToolPermission::READ;
    }

    /** @SuppressWarnings(PHPMD.UnusedFormalParameter) */
    public function handle(array $arguments, ToolContext $context): string|array
    {
        $query = trim((string) ($arguments['query'] ?? ''));
        $ids = collect($arguments['ids'] ?? [])
            ->map(fn ($id) => trim((string) $id))
            ->filter()
            ->values()
            ->all();

        if ($query === '' && $ids === []) {
            return ['error' => 'Provide a "query" to look up a coin, or "ids" to price coins.'];
        }

        $result = [];

        if ($query !== '') {
            $result['matches'] = $this->assetPrices->searchCoins($query);
        }

        if ($ids !== []) {
            $vsCurrency = (string) ($arguments['vs_currency'] ?? 'usd');
            $result['vs_currency'] = strtolower($vsCurrency);
            $result['prices'] = $this->assetPrices->fetchPrices($ids, $vsCurrency);
        }

        return $result;
    }
}
