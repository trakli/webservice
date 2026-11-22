<?php

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\TestCase;
use Whilesmart\SchemaConformance\Testing\SchemaConformanceAssertions;

class SchemaConformanceTest extends TestCase
{
    use SchemaConformanceAssertions;

    public function test_the_declared_spec_matches_the_live_database(): void
    {
        $this->assertSchemaConformant();
    }

    public function test_every_altered_column_is_declared_in_the_spec(): void
    {
        $this->assertAlteredColumnsDeclared(database_path('migrations'));
    }

    public function test_every_model_attribute_maps_to_a_real_column(): void
    {
        $this->assertModelAttributesHaveColumns($this->eloquentModels());
    }

    /**
     * @return array<int, Model>
     */
    private function eloquentModels(): array
    {
        $models = [];

        foreach (glob(app_path('Models/*.php')) as $path) {
            $class = 'App\\Models\\'.Str::before(basename($path), '.php');

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
