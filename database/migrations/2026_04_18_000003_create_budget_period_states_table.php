<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('budget_period_states', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('budget_id');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('net_spent', 18, 4)->default(0);
            $table->decimal('rollover_in', 18, 4)->default(0);
            $table->decimal('rollover_out', 18, 4)->default(0);
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->foreign('budget_id')->references('id')->on('budgets')->onDelete('cascade');
            $table->unique(['budget_id', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_period_states');
    }
};
