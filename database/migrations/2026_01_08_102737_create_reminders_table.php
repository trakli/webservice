<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            $table->string('title');
            $table->text('description')->nullable();
            $table->string('type')->default('custom');

            $table->timestamp('trigger_at')->nullable();
            $table->timestamp('due_at')->nullable();

            $table->string('repeat_rule')->nullable();
            $table->string('timezone')->default('UTC');

            $table->string('status')->default('active');
            $table->unsignedTinyInteger('priority')->default(0);

            $table->timestamp('snoozed_until')->nullable();
            $table->timestamp('last_triggered_at')->nullable();
            $table->timestamp('next_trigger_at')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index(['next_trigger_at', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminders');
    }
};
