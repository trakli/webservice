<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('import_sessions', function (Blueprint $table) {
            $table->unsignedInteger('revision')->default(0);
        });
        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->foreignId('import_session_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('import_session_id');
        });
        Schema::table('import_sessions', function (Blueprint $table) {
            $table->dropColumn('revision');
        });
    }
};
