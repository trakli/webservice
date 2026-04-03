<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\TransferService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class TransferSeeder extends Seeder
{
    private array $transferTemplates = [
        ['description' => 'Move to savings', 'from' => 'Main Checking', 'to' => 'Savings Account', 'amount_range' => [100, 500], 'weight' => 5],
        ['description' => 'Top up mobile money', 'from' => 'Main Checking', 'to' => 'Mobile Money', 'amount_range' => [20, 100], 'weight' => 4],
        ['description' => 'Cash withdrawal', 'from' => 'Main Checking', 'to' => 'Main Wallet', 'amount_range' => [50, 200], 'weight' => 3],
        ['description' => 'Pay credit card', 'from' => 'Main Checking', 'to' => 'Credit Card', 'amount_range' => [100, 400], 'weight' => 2],
        ['description' => 'Transfer from savings', 'from' => 'Savings Account', 'to' => 'Main Checking', 'amount_range' => [50, 300], 'weight' => 1],
        ['description' => 'Cash deposit', 'from' => 'Main Wallet', 'to' => 'Main Checking', 'amount_range' => [30, 150], 'weight' => 1],
    ];

    public function run(): void
    {
        $transferService = app(TransferService::class);
        $users = User::all();

        foreach ($users as $user) {
            $this->createTransfersForUser($user, $transferService);
        }
    }

    private function createTransfersForUser(User $user, TransferService $transferService): void
    {
        $wallets = $user->wallets->keyBy('name');

        if ($wallets->count() < 2) {
            return;
        }

        // Ensure wallets have enough balance for transfers
        $user->wallets()->where('balance', '<', 1000)->update(['balance' => 5000]);
        $wallets = $user->wallets()->get()->keyBy('name');

        for ($month = 0; $month < 3; $month++) {
            $monthStart = Carbon::now()->subMonths($month)->startOfMonth();
            $monthEnd = Carbon::now()->subMonths($month)->endOfMonth();

            if ($monthEnd->isFuture()) {
                $monthEnd = Carbon::now();
            }

            $transferCount = rand(2, 5);
            for ($i = 0; $i < $transferCount; $i++) {
                $template = $this->getWeightedTemplate();

                $fromWallet = $wallets->get($template['from']);
                $toWallet = $wallets->get($template['to']);

                if (! $fromWallet || ! $toWallet) {
                    continue;
                }

                $amount = rand($template['amount_range'][0] * 100, $template['amount_range'][1] * 100) / 100;

                // Refresh from DB to get current balance after prior transfers
                $fromWallet = $fromWallet->fresh();
                $toWallet = $toWallet->fresh();

                // Ensure sufficient balance
                if ($fromWallet->balance < $amount) {
                    $fromWallet->balance += $amount + 500;
                    $fromWallet->save();
                    $fromWallet = $fromWallet->fresh();
                }

                $datetime = $this->randomDateBetween($monthStart, $monthEnd);

                $transferService->transfer(
                    amountToSend: $amount,
                    fromWallet: $fromWallet,
                    amountToReceive: $amount,
                    toWallet: $toWallet,
                    user: $user,
                    exchangeRate: 1,
                    datetime: $datetime->toDateTimeString(),
                );
            }
        }
    }

    private function getWeightedTemplate(): array
    {
        $weighted = [];
        foreach ($this->transferTemplates as $template) {
            for ($i = 0; $i < $template['weight']; $i++) {
                $weighted[] = $template;
            }
        }

        return $weighted[array_rand($weighted)];
    }

    private function randomDateBetween(Carbon $start, Carbon $end): Carbon
    {
        $diffInSeconds = $end->timestamp - $start->timestamp;

        return $start->copy()->addSeconds(rand(0, max(0, $diffInSeconds)));
    }
}
