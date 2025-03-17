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
        Schema::create('failed_imports', function (Blueprint $table) {
            $table->id();
            $table->string('amount')->nullable();
            $table->string('currency')->nullable();
            $table->string('type')->nullable();
            $table->string('party')->nullable();
            $table->string('wallet')->nullable();
            $table->string('category')->nullable();
            $table->string('description')->nullable();
            $table->string('date')->nullable();
            $table->string('reason')->nullable();
            $table->unsignedBigInteger('file_import_id');
            $table->timestamps();

            $table->foreign('file_import_id')->references('id')->on('file_imports')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('failed_imports');
    }
};
