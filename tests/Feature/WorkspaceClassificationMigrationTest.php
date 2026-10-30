<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WorkspaceClassificationMigrationTest extends TestCase
{
    public function test_category_links_and_foreign_keys_survive_the_rename_and_rollback(): void
    {
        config(['database.connections.workspace_migration' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        $schema = Schema::connection('workspace_migration');
        $database = DB::connection('workspace_migration');
        foreach (['workspaces', 'transactions', 'categories'] as $table) {
            $schema->create($table, fn (Blueprint $table) => $table->id());
            $database->table($table)->insert(['id' => 1]);
        }
        $schema->create('workspace_transaction_categorys', function (Blueprint $table) {
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->unique(['workspace_id', 'transaction_id', 'category_id'], 'workspace_tx_category_unique');
        });
        $link = ['workspace_id' => 1, 'transaction_id' => 1, 'category_id' => 1];
        $database->table('workspace_transaction_categorys')->insert($link);
        $options = ['--database' => 'workspace_migration', '--force' => true,
            '--path' => 'database/migrations/2026_10_30_000001_normalize_workspace_category_links.php'];

        $this->artisan('migrate', $options)->assertSuccessful();
        $this->assertFalse($schema->hasTable('workspace_transaction_categorys'));
        $this->assertSame($link, (array) $database->table('workspace_transaction_categories')->first());
        $this->artisan('migrate:rollback', $options + ['--step' => 1])->assertSuccessful();
        $this->assertSame($link, (array) $database->table('workspace_transaction_categorys')->first());
        $this->artisan('migrate', $options)->assertSuccessful();
        $database->table('categories')->where('id', 1)->delete();
        $this->assertSame(0, $database->table('workspace_transaction_categories')->count());
    }
}
