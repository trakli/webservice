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
        Schema::create('configurations', function (Blueprint $table) {
            $table->id();
            $table->string('key');
            $table->json('value');
            $table->string('configurable_type');
            $table->unsignedBigInteger('configurable_id');
            $table->string('type')->default('string');
            $table->timestamps();

            $table->unique(['configurable_id', 'configurable_type', 'key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('configurations');
    }
};
