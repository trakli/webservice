<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('debt_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('debt_transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->foreignId('payment_transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->decimal('amount_applied', 18, 4);
            $table->decimal('payment_amount', 18, 4);
            $table->decimal('rate', 24, 12);
            $table->enum('rate_source', ['market', 'paid']);
            $table->boolean('is_explicit')->default(false);
            $table->timestamps();
            $table->unique(['debt_transaction_id', 'payment_transaction_id'], 'debt_payment_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_settlements');
    }
};
