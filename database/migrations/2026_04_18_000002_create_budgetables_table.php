<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('budgetables', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('budget_id');
            $table->unsignedBigInteger('budgetable_id');
            $table->string('budgetable_type');
            $table->timestamps();

            $table->foreign('budget_id')->references('id')->on('budgets')->onDelete('cascade');
            $table->index(['budgetable_type', 'budgetable_id']);
            $table->unique(['budget_id', 'budgetable_id', 'budgetable_type'], 'budgetables_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budgetables');
    }
};
