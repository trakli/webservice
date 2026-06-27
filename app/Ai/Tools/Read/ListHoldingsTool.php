<?php

namespace App\Ai\Tools\Read;

use App\Models\User;
use Whilesmart\Agents\Enums\ToolPermission;
use Whilesmart\Agents\Tools\AbstractTool;
use Whilesmart\Agents\ValueObjects\ToolContext;
use Whilesmart\Holdings\Models\Holding;

class ListHoldingsTool extends AbstractTool
{
    public function name(): string
    {
        return 'list_holdings';
    }

    public function description(): string
    {
        return 'List the user\'s asset holdings (id, name, symbol, quantity, currency, unit price, '
            . 'value, price source). Use for questions about owned assets, crypto, stocks or net worth.';
    }

    public function permission(): ToolPermission
    {
        return ToolPermission::READ;
    }

    /** @SuppressWarnings(PHPMD.UnusedFormalParameter) */
    public function handle(array $arguments, ToolContext $context): string|array
    {
        $user = $context->user;
        if ($user === null) {
            return ['error' => 'No authenticated user in context.'];
        }

        $holdings = Holding::where('owner_type', User::class)
            ->where('owner_id', $user->id)
            ->get();

        return [
            'holdings' => $holdings->map(fn (Holding $h) => [
                'id' => $h->id,
                'name' => $h->name,
                'symbol' => $h->symbol,
                'quantity' => (float) $h->quantity,
                'currency' => $h->currency,
                'unit_price' => (float) $h->unit_price,
                'value' => $h->value,
                'price_source' => $h->price_source?->value,
            ])->all(),
        ];
    }
}
