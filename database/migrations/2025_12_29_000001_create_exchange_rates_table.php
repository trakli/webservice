<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->string('base_currency', 3);
            $table->string('target_currency', 3);
            $table->decimal('rate', 18, 8);
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['base_currency', 'target_currency']);
            $table->index('base_currency');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
