<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('refund_transaction_id');
            $table->unsignedBigInteger('original_transaction_id')->nullable();
            $table->timestamps();

            $table->foreign('refund_transaction_id')
                ->references('id')->on('transactions')->onDelete('cascade');
            $table->foreign('original_transaction_id')
                ->references('id')->on('transactions')->nullOnDelete();

            // One refund record per income transaction.
            $table->unique('refund_transaction_id');
            $table->index('original_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
