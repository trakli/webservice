<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->string('provenance')->default('custom');
        });
        DB::table('categories')->update(['provenance' => 'grandfathered']);
    }

    public function down(): void
    {
        Schema::table('categories', fn (Blueprint $table) => $table->dropColumn('provenance'));
    }
};
