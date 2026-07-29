<?php

namespace Tests\Feature;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * The semantic layer is what the query tool is allowed to read, so a mistake in
 * it is a data-isolation bug rather than a typo. These check the invariants that
 * keep it safe: nothing readable without a tenant filter, nothing filtered that
 * cannot be read, and no table scoped by an owner id without its type pinned.
 */
class SmartqlSchemaTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $schema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schema = Yaml::parseFile(base_path('smartql.yml'));
    }

    /**
     * @return array<string, mixed>
     */
    private function entities(): array
    {
        return $this->schema['semantic_layer']['entities'];
    }

    /**
     * @return array<int, string>
     */
    private function allowedTables(): array
    {
        return $this->schema['security']['allowed_tables'];
    }

    /**
     * @return array<string, mixed>
     */
    private function requiredFilters(): array
    {
        return $this->schema['security']['required_filters'];
    }

    public function test_the_file_parses(): void
    {
        $this->assertIsArray($this->schema);
        $this->assertNotEmpty($this->entities());
    }

    public function test_every_entity_table_is_allowed(): void
    {
        foreach ($this->entities() as $name => $entity) {
            $this->assertContains(
                $entity['table'],
                $this->allowedTables(),
                "Entity '{$name}' describes a table the query tool may not read."
            );
        }
    }

    public function test_every_allowed_table_has_an_entity(): void
    {
        $tables = array_column($this->entities(), 'table');

        foreach ($this->allowedTables() as $table) {
            $this->assertContains($table, $tables, "Table '{$table}' is readable but undescribed.");
        }
    }

    public function test_every_filtered_table_is_allowed(): void
    {
        foreach (array_keys($this->requiredFilters()) as $table) {
            $this->assertContains($table, $this->allowedTables(), "Table '{$table}' is filtered but unreadable.");
        }
    }

    /**
     * The one that matters: a readable table with no filter returns every user's
     * rows to whoever asks.
     */
    public function test_every_readable_table_is_scoped_to_its_owner(): void
    {
        $global = ['exchange_rates'];

        foreach ($this->allowedTables() as $table) {
            if (in_array($table, $global, true)) {
                continue;
            }

            $this->assertArrayHasKey(
                $table,
                $this->requiredFilters(),
                "Table '{$table}' is readable by anyone: it has no required filter."
            );
        }
    }

    public function test_every_filter_actually_constrains_something(): void
    {
        foreach ($this->requiredFilters() as $table => $filter) {
            $this->assertTrue(
                isset($filter['column']) || isset($filter['through']),
                "The filter on '{$table}' binds nothing."
            );
        }
    }

    public function test_through_filters_point_at_a_table_that_is_itself_scoped(): void
    {
        foreach ($this->requiredFilters() as $table => $filter) {
            if (! isset($filter['through'])) {
                continue;
            }

            [$parent] = explode('.', $filter['through']['references']);

            $this->assertArrayHasKey(
                $parent,
                $this->requiredFilters(),
                "Table '{$table}' borrows scoping from '{$parent}', which has none of its own."
            );
        }
    }

    public function test_polymorphic_owners_pin_their_type(): void
    {
        foreach ($this->requiredFilters() as $table => $filter) {
            if (($filter['column'] ?? null) !== 'owner_id') {
                continue;
            }

            $this->assertArrayHasKey(
                'constants',
                $filter,
                "Table '{$table}' filters on owner_id without pinning owner_type, so another owner type's rows leak."
            );
        }
    }

    /**
     * A transfer writes a paired income and expense leg for money that never
     * left the user. Counting either as real income or spending double-counts
     * every transfer.
     */
    public function test_income_and_expense_rules_exclude_transfer_legs(): void
    {
        $rules = collect($this->schema['semantic_layer']['business_rules'])->keyBy('name');

        $this->assertStringContainsString('transfer_id IS NULL', $rules['income']['definition']);
        $this->assertStringContainsString('transfer_id IS NULL', $rules['expense']['definition']);
    }

    public function test_the_transactions_entity_documents_transfer_id(): void
    {
        $this->assertArrayHasKey('transfer_id', $this->entities()['transactions']['columns']);
    }

    public function test_prompt_examples_are_scoped_to_the_caller(): void
    {
        foreach ($this->schema['prompts']['examples'] as $example) {
            $this->assertStringContainsString(
                ':user_id',
                $example['sql'],
                "The example \"{$example['question']}\" teaches an unscoped query."
            );
        }
    }
}
