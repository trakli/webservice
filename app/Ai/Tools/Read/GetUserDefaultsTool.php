<?php

namespace App\Ai\Tools\Read;

use App\Models\Wallet;
use App\Support\ConfigurationKeys;
use Illuminate\Support\Collection;
use Whilesmart\Agents\Enums\ToolPermission;
use Whilesmart\Agents\Tools\AbstractTool;
use Whilesmart\Agents\ValueObjects\ToolContext;

/**
 * The user's own settings: the currency their money should be reported in, the
 * wallet to record against when they don't name one, and their locale. Without
 * these the agent has to guess whose money it is talking about.
 */
class GetUserDefaultsTool extends AbstractTool
{
    public function name(): string
    {
        return 'get_user_defaults';
    }

    public function description(): string
    {
        return "Get the user's own defaults: their currency, default wallet, default group, "
            . 'language and timezone. Call this before stating an amount when you are unsure '
            . 'which currency the user thinks in, or when they say "use my defaults". '
            . 'Never assume a currency.';
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

        $wallets = $user->wallets()->get(['id', 'name', 'type', 'currency']);
        $currency = $user->getConfigValue(ConfigurationKeys::DEFAULT_CURRENCY);
        $walletCurrencies = $wallets->pluck('currency')->filter()->unique()->values();

        // An unset currency is not a reason to fall back on dollars: one wallet
        // currency answers it outright, and several means the answer is "ask".
        $inferred = null;
        if (! $currency && $walletCurrencies->count() === 1) {
            $inferred = $walletCurrencies->first();
        }

        $defaultWallet = $this->resolveDefaultWallet($user, $wallets);

        return array_filter([
            'currency' => $currency ?: $inferred,
            'currency_is_set' => (bool) $currency,
            'currency_source' => $currency ? 'user setting' : ($inferred ? 'their only wallet' : null),
            'currency_note' => $currency || $inferred
                ? null
                : 'The user has not set a currency and holds wallets in ' . $walletCurrencies->implode(', ')
                    . '. Ask which they want rather than assuming.',
            'wallet_currencies' => $walletCurrencies->all(),
            'default_wallet' => $defaultWallet ? [
                'id' => $defaultWallet->id,
                'name' => $defaultWallet->name,
                'currency' => $defaultWallet->currency,
            ] : null,
            'default_group' => $user->getConfigValue(ConfigurationKeys::DEFAULT_GROUP),
            'language' => $user->getConfigValue(ConfigurationKeys::DEFAULT_LANG),
            'timezone' => $user->getConfigValue(ConfigurationKeys::TIMEZONE),
        ], fn ($value): bool => $value !== null);
    }

    /**
     * The stored value is either a primary key or the client-generated id the
     * device minted, because the clients disagree: the web app writes the id
     * while mobile and registration write the client id. Both are matched here
     * rather than assuming one, so a default wallet resolves whichever wrote it.
     *
     * @param  Collection<int, Wallet>  $wallets
     */
    private function resolveDefaultWallet($user, $wallets): ?Wallet
    {
        $configured = $user->getConfigValue(ConfigurationKeys::DEFAULT_WALLET);
        if (! $configured) {
            return null;
        }

        $configured = (string) $configured;

        $byId = $wallets->first(fn (Wallet $wallet): bool => (string) $wallet->id === $configured);
        if ($byId !== null) {
            return $byId;
        }

        return $user->wallets()
            ->with('syncState.device')
            ->get()
            ->first(fn (Wallet $wallet): bool => $wallet->client_generated_id === $configured);
    }
}
