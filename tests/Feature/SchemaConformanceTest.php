<?php

namespace Tests\Feature;

use App\Services\SchemaConformance\SchemaConformanceService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\TestCase;

class SchemaConformanceTest extends TestCase
{
    /**
     * Blueprint methods that define a column. Anything else a migration calls
     * on the blueprint (index, foreign, drop*, modifiers) is not a column.
     */
    private const COLUMN_METHODS = [
        'bigInteger', 'boolean', 'char', 'date', 'dateTime', 'decimal', 'double',
        'enum', 'float', 'foreignId', 'integer', 'json', 'jsonb', 'longText',
        'mediumText', 'smallInteger', 'string', 'text', 'time', 'timestamp',
        'tinyInteger', 'unsignedBigInteger', 'unsignedInteger', 'unsignedSmallInteger',
        'unsignedTinyInteger', 'uuid', 'year',
    ];

    public function test_the_declared_spec_matches_the_live_database(): void
    {
        $problems = app(SchemaConformanceService::class)->verify();

        $this->assertSame(
            [],
            $problems,
            'config/schema.php declares structures the database does not have: '
                . implode(', ', array_column($problems, 'detail'))
        );
    }

    /**
     * A migration that alters an existing table can reach one environment and
     * not another, leaving a table that looks intact but rejects writes. The
     * conformance spec is what turns that into a clear 503 instead, so every
     * altered column has to be declared there.
     */
    public function test_every_altered_column_is_declared_in_the_spec(): void
    {
        $declared = collect(config('schema.tables', []))
            ->map(fn (array $spec) => array_keys($spec['columns'] ?? []));

        $undeclared = [];

        foreach ($this->alteredColumnsByTable() as $table => $columns) {
            foreach ($columns as $column) {
                if (! in_array($column, $declared->get($table, []), true)) {
                    $undeclared[] = "{$table}.{$column}";
                }
            }
        }

        $this->assertSame(
            [],
            $undeclared,
            'These columns are added by an ALTER migration but are not declared in '
                . 'config/schema.php, so schema:verify cannot detect them going missing: '
                . implode(', ', $undeclared)
        );
    }

    /**
     * Catches the reverse gap: an attribute the model writes on every insert
     * that no migration ever creates.
     */
    public function test_every_model_attribute_maps_to_a_real_column(): void
    {
        $missing = [];

        foreach ($this->eloquentModels() as $model) {
            $table = $model->getTable();

            if (! Schema::hasTable($table)) {
                $missing[] = $table . ' (table missing for ' . $model::class . ')';

                continue;
            }

            $attributes = array_unique(array_merge(
                $model->getFillable(),
                array_keys($model->getAttributes())
            ));

            foreach ($attributes as $attribute) {
                if (! Schema::hasColumn($table, $attribute)) {
                    $missing[] = "{$table}.{$attribute} (" . $model::class . ')';
                }
            }
        }

        $this->assertSame(
            [],
            $missing,
            'These model attributes have no matching column: ' . implode(', ', $missing)
        );
    }

    /**
     * Columns added by `Schema::table(...)` blocks across the app's own
     * migrations, keyed by table. Migrations written in a shape this does not
     * recognise are skipped rather than reported, so the check never fails on
     * an unusual but correct migration.
     *
     * @return array<string, array<int, string>>
     */
    private function alteredColumnsByTable(): array
    {
        $byTable = [];

        foreach (glob(database_path('migrations/*.php')) as $path) {
            $contents = (string) file_get_contents($path);

            // Everything after each `Schema::table('x'` up to the next one, so a
            // file altering two tables attributes its columns to the right table.
            $blocks = preg_split("/Schema::table\(\s*'/", $contents);
            array_shift($blocks);

            foreach ($blocks as $block) {
                if (! preg_match("/^(\w+)'/", $block, $tableMatch)) {
                    continue;
                }

                $methods = implode('|', self::COLUMN_METHODS);
                preg_match_all("/\\\$table->({$methods})\(\s*'(\w+)'/", $block, $columnMatches);

                foreach ($columnMatches[2] as $column) {
                    $byTable[$tableMatch[1]][] = $column;
                }
            }
        }

        return array_map('array_unique', $byTable);
    }

    /**
     * @return array<int, Model>
     */
    private function eloquentModels(): array
    {
        $models = [];

        foreach (glob(app_path('Models/*.php')) as $path) {
            $class = 'App\\Models\\' . Str::before(basename($path), '.php');

            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            if ((new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $models[] = new $class();
        }

        return $models;
    }
}
