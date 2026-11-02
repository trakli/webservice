<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class FinanceOwnershipMigrationTest extends TestCase
{
    public static function connections(): array
    {
        return ['sqlite' => [false], 'application test database' => [true]];
    }

    #[DataProvider('connections')]
    public function test_existing_finances_survive_migration_and_rollback_without_changing_ids_or_values(bool $useTestDatabase): void
    {
        Event::fake([MigrationsEnded::class]);
        $connection = $useTestDatabase
            ? array_replace(config('database.connections.' . config('database.default')), ['prefix' => 'ownership_'])
            : ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true];
        config(['database.connections.finance_migration' => $connection]);
        DB::purge('finance_migration');
        try {
            $this->assertOwnershipRoundTrip();
        } finally {
            $schema = Schema::connection('finance_migration');
            foreach (['transactions', 'transfers', 'wallets', 'categories', 'groups', 'parties',
                'reminders', 'file_imports', 'import_sessions', 'users', 'migrations'] as $table) {
                $schema->dropIfExists($table);
            }
            DB::purge('finance_migration');
        }
    }

    private function assertOwnershipRoundTrip(): void
    {
        $legacy = [
            '2014_10_12_000000_create_users_table.php',
            '2024_07_20_125030_create_groups_table.php',
            '2024_07_20_125104_create_parties_table.php',
            '2024_07_20_125114_create_wallets_table.php',
            '2024_07_20_125115_create_transfers_table.php',
            '2024_07_20_125117_create_transactions_table.php',
            '2024_07_20_125455_create_categories_table.php',
            '2025_03_19_132012_create_file_imports_table.php',
            '2026_01_08_102737_create_reminders_table.php',
            '2026_04_03_000001_create_import_sessions_table.php',
        ];
        $options = ['--database' => 'finance_migration', '--force' => true];
        $this->artisan('migrate', $options + ['--path' => array_map(fn ($file) => 'database/migrations/' . $file, $legacy)])
            ->assertSuccessful();
        $database = DB::connection('finance_migration');
        $schema = Schema::connection('finance_migration');
        $database->table('users')->insert(['id' => 17, 'email' => 'migration@example.test', 'password' => 'unused']);
        $rows = [
            'wallets' => ['name' => 'Cash', 'slug' => 'cash', 'type' => 'cash', 'balance' => '123.4567', 'currency' => 'USD'],
            'categories' => ['name' => 'Food', 'slug' => 'food', 'type' => 'expense'],
            'groups' => ['name' => 'Holiday', 'slug' => 'holiday'],
            'parties' => ['name' => 'Shop', 'type' => 'business'],
            'transfers' => ['amount' => '1.2345', 'from_wallet_id' => 91, 'to_wallet_id' => 91],
            'transactions' => ['amount' => '0.0001', 'wallet_id' => 91, 'type' => 'expense', 'deleted_at' => '2026-10-01 00:00:00'],
            'reminders' => ['title' => 'Pay rent'],
            'file_imports' => ['name' => 'Statement', 'file_path' => 'imports/statement.csv', 'file_type' => 'csv'],
            'import_sessions' => ['file_name' => 'statement.csv', 'file_type' => 'csv', 'processor' => 'csv', 'suggestions' => '[]'],
        ];
        foreach ($rows as $table => $row) {
            $database->table($table)->insert($row + ['id' => 91, 'user_id' => 17]);
        }
        $before = [];
        foreach (array_keys($rows) as $table) {
            $before[$table] = (array) $database->table($table)->first();
        }
        $migration = ['--path' => 'database/migrations/2026_10_23_000001_make_finance_ownership_polymorphic.php'];
        $this->artisan('migrate', $options + $migration)->assertSuccessful();
        foreach ($before as $table => $row) {
            $actual = (array) $database->table($table)->first();
            $this->assertFalse($schema->hasColumn($table, 'user_id'));
            $this->assertSame(User::class, $actual['owner_type']);
            $this->assertSame(17, $actual['owner_id']);
            unset($row['user_id'], $actual['owner_type'], $actual['owner_id']);
            $this->assertSame($row, $actual);
        }
        $database->table('import_sessions')->where('id', 91)->update(['owner_type' => \App\Models\Workspace::class]);
        try {
            $this->artisan('migrate:rollback', $options + $migration + ['--step' => 1])->run();
            $this->fail('Rollback must refuse non-user finance owners.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Non-user finance owners exist', $exception->getMessage());
            foreach (array_keys($rows) as $table) {
                $this->assertFalse($schema->hasColumn($table, 'user_id'));
            }
        }
        $database->table('import_sessions')->where('id', 91)->update(['owner_type' => User::class]);
        $this->artisan('migrate:rollback', $options + $migration + ['--step' => 1])->assertSuccessful();
        foreach ($before as $table => $row) {
            $actual = (array) $database->table($table)->first();
            ksort($row);
            ksort($actual);
            $this->assertSame($row, $actual);
            $this->assertFalse($schema->hasColumn($table, 'owner_id'));
            $this->assertFalse(collect($schema->getColumns($table))->firstWhere('name', 'user_id')['nullable']);
        }
        $schema->table('wallets', fn (\Illuminate\Database\Schema\Blueprint $table) => $table->unsignedBigInteger('workspace_id')->nullable());
        try {
            $this->artisan('migrate', $options + $migration)->run();
            $this->fail('An existing shared-review schema requires an explicit conversion.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Expected the user-only finance schema', $exception->getMessage());
            foreach (array_keys($rows) as $table) {
                $this->assertFalse($schema->hasColumn($table, 'owner_id'));
            }
        }

    }
}
