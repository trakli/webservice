<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('files', 'metadata')) {
            Schema::table('files', function (Blueprint $table) {
                $table->json('metadata')->nullable()->after('type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('files', 'metadata')) {
            Schema::table('files', function (Blueprint $table) {
                $table->dropColumn('metadata');
            });
        }
    }
};
