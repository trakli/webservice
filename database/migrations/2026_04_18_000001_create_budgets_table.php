<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('owner_id');
            $table->string('owner_type');
            $table->string('name');
            $table->string('slug')->index();
            $table->text('description')->nullable();
            $table->decimal('amount', 18, 4);
            $table->string('currency', 3);
            $table->enum('period_type', ['weekly', 'monthly', 'yearly', 'custom']);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->boolean('rollover_enabled')->default(false);
            $table->unsignedTinyInteger('threshold_percent')->default(80);
            $table->boolean('forecast_alerts_enabled')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['owner_type', 'owner_id']);
            $table->unique(['owner_type', 'owner_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};
