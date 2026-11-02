<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    private const TABLES = ['wallets', 'transactions', 'categories', 'groups', 'parties',
        'transfers', 'reminders', 'file_imports', 'import_sessions'];

    private const SLUG_TABLES = ['wallets', 'categories', 'groups'];

    private const STATUS_TABLES = ['reminders', 'import_sessions'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->nullableMorphs('owner');
            });
            DB::table($name)->update(['owner_type' => (new User())->getMorphClass(), 'owner_id' => DB::raw('user_id')]);
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropForeign(['user_id']);
                if (in_array($name, self::SLUG_TABLES, true)) {
                    $table->dropUnique(['user_id', 'slug']);
                    $table->unique(['owner_type', 'owner_id', 'slug']);
                }
                if (in_array($name, self::STATUS_TABLES, true)) {
                    $table->dropIndex(['user_id', 'status']);
                    $table->index(['owner_type', 'owner_id', 'status']);
                }
                $table->dropColumn('user_id');
                $table->string('owner_type')->nullable(false)->change();
                $table->unsignedBigInteger('owner_id')->nullable(false)->change();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            if (DB::table($name)->where('owner_type', '!=', (new User())->getMorphClass())->exists()) {
                throw new RuntimeException('Non-user finance owners exist. Restore a backup rather than discarding ownership.');
            }
        }
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable();
            });
            DB::table($name)->update(['user_id' => DB::raw('owner_id')]);
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->unsignedBigInteger('user_id')->nullable(false)->change();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                if (in_array($name, self::SLUG_TABLES, true)) {
                    $table->dropUnique(['owner_type', 'owner_id', 'slug']);
                    $table->unique(['user_id', 'slug']);
                }
                if (in_array($name, self::STATUS_TABLES, true)) {
                    $table->dropIndex(['owner_type', 'owner_id', 'status']);
                    $table->index(['user_id', 'status']);
                }
                $table->dropMorphs('owner');
            });
        }
    }
};
