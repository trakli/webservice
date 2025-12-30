<?php

namespace App\Services;

use App\Models\ExchangeRate;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ExchangeRateService
{
    protected string $apiUrl = 'https://open.er-api.com/v6/latest';

    protected int $cacheMinutes = 60;

    public function getRate(string $baseCurrency, string $targetCurrency, ?User $user = null): ?float
    {
        $baseCurrency = strtoupper($baseCurrency);
        $targetCurrency = strtoupper($targetCurrency);

        if ($baseCurrency === $targetCurrency) {
            return 1.0;
        }

        if ($user) {
            $manualRate = $this->getManualRate($user, $baseCurrency, $targetCurrency);
            if ($manualRate !== null) {
                return $manualRate;
            }
        }

        return $this->getCachedRate($baseCurrency, $targetCurrency);
    }

    public function convert(float $amount, string $fromCurrency, string $toCurrency, ?User $user = null): ?float
    {
        $rate = $this->getRate($fromCurrency, $toCurrency, $user);

        if ($rate === null) {
            return null;
        }

        return bcmul((string) $amount, (string) $rate, 8);
    }

    protected function getManualRate(User $user, string $baseCurrency, string $targetCurrency): ?float
    {
        $manualRates = $user->getConfigValue('manual-exchange-rates');

        if (empty($manualRates)) {
            return null;
        }

        if (is_string($manualRates)) {
            $manualRates = json_decode($manualRates, true);
        }

        if (! is_array($manualRates)) {
            return null;
        }

        $key = "{$baseCurrency}-{$targetCurrency}";
        if (isset($manualRates[$key])) {
            return (float) $manualRates[$key];
        }

        $reverseKey = "{$targetCurrency}-{$baseCurrency}";
        if (isset($manualRates[$reverseKey])) {
            return 1 / (float) $manualRates[$reverseKey];
        }

        return null;
    }

    protected function getCachedRate(string $baseCurrency, string $targetCurrency): ?float
    {
        $rate = ExchangeRate::where('base_currency', $baseCurrency)
            ->where('target_currency', $targetCurrency)
            ->first();

        if ($rate && $rate->fetched_at->diffInMinutes(now()) < $this->cacheMinutes) {
            return (float) $rate->rate;
        }

        return $this->fetchAndCacheRate($baseCurrency, $targetCurrency);
    }

    protected function fetchAndCacheRate(string $baseCurrency, string $targetCurrency): ?float
    {
        $cacheKey = "exchange_rate_fetch_{$baseCurrency}";

        $rates = Cache::remember($cacheKey, now()->addMinutes($this->cacheMinutes), function () use ($baseCurrency) {
            return $this->fetchRatesFromApi($baseCurrency);
        });

        if ($rates === null || ! isset($rates[$targetCurrency])) {
            return $this->tryReverseRate($baseCurrency, $targetCurrency);
        }

        $rate = (float) $rates[$targetCurrency];

        ExchangeRate::updateOrCreate(
            ['base_currency' => $baseCurrency, 'target_currency' => $targetCurrency],
            ['rate' => $rate, 'fetched_at' => now()]
        );

        return $rate;
    }

    protected function tryReverseRate(string $baseCurrency, string $targetCurrency): ?float
    {
        $reverseRate = ExchangeRate::where('base_currency', $targetCurrency)
            ->where('target_currency', $baseCurrency)
            ->first();

        if ($reverseRate) {
            return 1 / (float) $reverseRate->rate;
        }

        $cacheKey = "exchange_rate_fetch_{$targetCurrency}";
        $rates = Cache::remember($cacheKey, now()->addMinutes($this->cacheMinutes), function () use ($targetCurrency) {
            return $this->fetchRatesFromApi($targetCurrency);
        });

        if ($rates !== null && isset($rates[$baseCurrency])) {
            $reverseRateValue = (float) $rates[$baseCurrency];

            ExchangeRate::updateOrCreate(
                ['base_currency' => $targetCurrency, 'target_currency' => $baseCurrency],
                ['rate' => $reverseRateValue, 'fetched_at' => now()]
            );

            return 1 / $reverseRateValue;
        }

        return null;
    }

    protected function fetchRatesFromApi(string $baseCurrency): ?array
    {
        try {
            $response = Http::timeout(10)->get("{$this->apiUrl}/{$baseCurrency}");

            if ($response->successful()) {
                $data = $response->json();

                if (($data['result'] ?? '') === 'success') {
                    return $data['rates'] ?? null;
                }
            }

            Log::warning('Exchange rate API request failed', [
                'status' => $response->status(),
                'base_currency' => $baseCurrency,
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('Exchange rate API error', [
                'message' => $e->getMessage(),
                'base_currency' => $baseCurrency,
            ]);

            return null;
        }
    }

    public function refreshRates(string $baseCurrency = 'USD'): array
    {
        $rates = $this->fetchRatesFromApi($baseCurrency);

        if ($rates === null) {
            return [];
        }

        $now = now();
        foreach ($rates as $targetCurrency => $rate) {
            ExchangeRate::updateOrCreate(
                ['base_currency' => $baseCurrency, 'target_currency' => $targetCurrency],
                ['rate' => $rate, 'fetched_at' => $now]
            );
        }

        Cache::forget("exchange_rate_fetch_{$baseCurrency}");

        return $rates;
    }
}
