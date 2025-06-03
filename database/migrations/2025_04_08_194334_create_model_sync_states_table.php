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
        Schema::create('model_sync_states', function (Blueprint $table) {
            $table->id();
            $table->morphs('syncable');
            $table->string('source')->nullable();
            $table->uuid('client_generated_id')->index()->nullable();
            $table->timestamp('last_synced_at');
            $table->unsignedBigInteger('user_id');
            $table->unique(['syncable_type', 'client_generated_id', 'user_id'], 'syncable_user_unique');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('model_sync_states');
    }
};
