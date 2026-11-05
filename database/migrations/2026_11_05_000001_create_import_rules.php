<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('import_rules', function (Blueprint $table) {
            $table->id();
            $table->morphs('owner');
            $table->string('kind');
            $table->string('match_field')->nullable();
            $table->string('match_mode')->nullable();
            $table->string('pattern')->nullable();
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->text('instruction')->nullable();
            $table->foreignId('source_session_id')->nullable()->constrained('import_sessions')->nullOnDelete();
            $table->unsignedInteger('hits')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rules');
    }
};
