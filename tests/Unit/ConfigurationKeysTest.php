<?php

namespace Tests\Unit;

use App\Support\ConfigurationKeys;
use ReflectionClass;
use Tests\TestCase;

class ConfigurationKeysTest extends TestCase
{
    public function test_names_matches_string_constants()
    {
        $constants = (new ReflectionClass(ConfigurationKeys::class))->getConstants();
        $stringConstants = array_values(array_filter($constants, fn ($v) => is_string($v)));

        sort($stringConstants);
        $names = ConfigurationKeys::NAMES;
        sort($names);

        $this->assertSame($stringConstants, $names);
    }

    public function test_rules_keys_match_names()
    {
        $rulesKeys = array_keys(ConfigurationKeys::RULES);
        sort($rulesKeys);
        $names = ConfigurationKeys::NAMES;
        sort($names);

        $this->assertSame($names, $rulesKeys);
    }
}
