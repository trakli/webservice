<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('streaks', function (Blueprint $table) {
            $table->id();
            $table->morphs('owner');
            $table->string('type');
            $table->string('period');
            $table->unsignedInteger('current_length')->default(0);
            $table->unsignedInteger('longest_length')->default(0);
            $table->unsignedInteger('last_notified_length')->default(0);
            $table->date('started_on')->nullable();
            $table->date('last_tracked_on')->nullable();
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id', 'type', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('streaks');
    }
};
