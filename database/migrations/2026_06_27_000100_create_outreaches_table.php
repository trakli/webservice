<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('outreaches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject');
            $table->text('body')->nullable();
            $table->string('cta_label')->nullable();
            $table->string('cta_url', 2048)->nullable();
            $table->string('image_url', 2048)->nullable();
            $table->string('audience');
            $table->unsignedInteger('recipients')->default(0);
            $table->unsignedInteger('sent')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outreaches');
    }
};
