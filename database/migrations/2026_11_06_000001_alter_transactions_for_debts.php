<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->decimal('owed_amount', 18, 4)->nullable();
            $table->boolean('debt_settlements_explicit')->default(false);
            $table->index(['owner_type', 'owner_id', 'party_id', 'datetime'], 'transactions_debt_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('transactions_debt_lookup');
            $table->dropColumn(['owed_amount', 'debt_settlements_explicit']);
        });
    }
};
