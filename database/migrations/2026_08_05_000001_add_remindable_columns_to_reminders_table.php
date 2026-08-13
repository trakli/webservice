<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // These columns previously existed only through schema:conform, so any
        // database that has already been conformed will skip the additions here.
        Schema::table('reminders', function (Blueprint $table) {
            if (! Schema::hasColumn('reminders', 'source')) {
                $table->string('source')->nullable()->after('type');
            }

            if (! Schema::hasColumn('reminders', 'remindable_type')) {
                $table->string('remindable_type')->nullable()->after('source');
            }

            if (! Schema::hasColumn('reminders', 'remindable_id')) {
                $table->unsignedBigInteger('remindable_id')->nullable()->after('remindable_type');
            }
        });

        if (! Schema::hasIndex('reminders', 'reminders_remindable_index')) {
            Schema::table('reminders', function (Blueprint $table) {
                $table->index(['remindable_type', 'remindable_id'], 'reminders_remindable_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('reminders', 'reminders_remindable_index')) {
            Schema::table('reminders', function (Blueprint $table) {
                $table->dropIndex('reminders_remindable_index');
            });
        }

        Schema::table('reminders', function (Blueprint $table) {
            $table->dropColumn(['source', 'remindable_type', 'remindable_id']);
        });
    }
};
