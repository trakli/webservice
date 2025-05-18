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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->decimal('amount', 18, 4);
            $table->datetime('datetime')->nullable();
            $table->enum('type', ['income', 'expense']);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('wallet_id')->nullable();
            $table->unsignedBigInteger('party_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('transfer_id')->nullable();
            $table->timestamps();
            $table->foreign('transfer_id')->references('id')->on('transfers')->onDelete('cascade');
            $table->foreign('wallet_id')->references('id')->on('wallets')->onDelete('cascade');
            $table->foreign('party_id')->references('id')->on('parties')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
