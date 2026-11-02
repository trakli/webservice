<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Whilesmart\Roles\Models\Role;

return new class () extends Migration {
    public function up(): void
    {
        foreach (['wallets', 'transactions', 'categories', 'groups'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            });
        }
        foreach (['wallet', 'transaction'] as $resource) {
            Schema::create("workspace_{$resource}_shares", function (Blueprint $table) use ($resource) {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId("{$resource}_id")->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['workspace_id', "{$resource}_id"]);
            });
        }
        foreach (['category', 'group'] as $label) {
            Schema::create('workspace_transaction_' . Str::plural($label), function (Blueprint $table) use ($label) {
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
                $table->foreignId("{$label}_id")->constrained()->cascadeOnDelete();
                $table->unique(['workspace_id', 'transaction_id', "{$label}_id"], "workspace_tx_{$label}_unique");
            });
        }
        foreach (['owner' => 100, 'admin' => 50, 'member' => 10] as $slug => $level) {
            Role::firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug), 'level' => $level]);
        }
    }

    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::table('wallets')->where('owner_type', \App\Models\Workspace::class)->exists()
            || \Illuminate\Support\Facades\DB::table('categories')->where('owner_type', \App\Models\Workspace::class)->exists()
            || \Illuminate\Support\Facades\DB::table('groups')->where('owner_type', \App\Models\Workspace::class)->exists()
            || \Illuminate\Support\Facades\DB::table('workspace_wallet_shares')->exists()
            || \Illuminate\Support\Facades\DB::table('workspace_transaction_shares')->exists()) {
            throw new RuntimeException('Workspace finance data exists. Restore a backup rather than dropping its schema.');
        }

        foreach (['group', 'category'] as $label) {
            Schema::dropIfExists('workspace_transaction_' . Str::plural($label));
        }
        Schema::dropIfExists('workspace_transaction_shares');
        Schema::dropIfExists('workspace_wallet_shares');
        foreach (['wallets', 'transactions', 'categories', 'groups'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('created_by_user_id');
            });
        }
    }
};
