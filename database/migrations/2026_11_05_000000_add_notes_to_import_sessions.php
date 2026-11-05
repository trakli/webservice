<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('import_sessions', 'notes')) {
            return;
        }
        Schema::table('import_sessions', function (Blueprint $table) {
            $table->text('notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('import_sessions', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
