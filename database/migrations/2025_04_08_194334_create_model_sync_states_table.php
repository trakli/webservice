<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('model_sync_states', function (Blueprint $table) {
            $table->id();
            $table->morphs('syncable');
            $table->string('source')->nullable();
            $table->uuid('client_generated_id')->nullable()->index();
            $table->unsignedBigInteger('device_id')->nullable()->index();
            $table->timestamp('last_synced_at');
            $table->timestamps();

            $table->unique(['syncable_type', 'syncable_id', 'device_id', 'client_generated_id'], 'unique_syncable_device_client_id');
            $table->foreign('device_id')->references('id')->on('devices')->onDelete('cascade');
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
