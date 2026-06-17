<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('agent_proposed_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chat_message_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('tool_name');
            $table->string('action_type');
            $table->json('payload');
            $table->text('summary');
            $table->string('risk')->default('medium');
            $table->boolean('auto_confirm')->default(false);
            $table->string('status')->default('proposed')->index();
            $table->string('idempotency_key')->unique();
            $table->nullableMorphs('executed_resource', 'apa_executed_resource_index');
            $table->text('error')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->index(['chat_session_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_proposed_actions');
    }
};
