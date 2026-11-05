<?php

use App\Models\Transaction;
use App\Services\DebtService;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        \App\Models\Party::query()->chunkById(200, function ($parties) {
            foreach ($parties as $party) {
                $payment = Transaction::where('owner_type', $party->owner_type)->where('owner_id', $party->owner_id)
                    ->where('party_id', $party->id)->whereIn('intent', ['loan_collected', 'loan_repayment', 'debt_settled'])
                    ->orderBy('id')->first();
                if ($payment) {
                    app(DebtService::class)->recompute($payment);
                }
            }
        });
    }

    public function down(): void
    {
    }
};
