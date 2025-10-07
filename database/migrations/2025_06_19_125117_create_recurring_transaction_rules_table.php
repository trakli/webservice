<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('recurring_transaction_rules', function (Blueprint $table) {
            $table->id();
            $table->enum('recurrence_period', ['daily', 'weekly', 'monthly', 'yearly']);
            $table->unsignedInteger('recurrence_interval')->default(1); // e.g., every 2 weeks
            $table->datetime('recurrence_ends_at')->nullable(); // when to stop recurring
            $table->unsignedBigInteger('transaction_id')->unique(); // original recurring transaction

            $table->timestamp('next_scheduled_at'); // next scheduled occurrence
            $table->index('next_scheduled_at'); // index for efficient querying

            $table->timestamps();

            $table->foreign('transaction_id')->references('id')->on('transactions')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recurring_transaction_rules');
    }
};
