<?php

namespace Tests\Unit;

use App\Models\User;
use Tests\TestCase;
use Whilesmart\ModelConfiguration\Enums\ConfigValueType;

class ParseDatetimeWithUserTimezoneTest extends TestCase
{
    public function test_parses_utc_timestamp_with_z_suffix()
    {
        $input = '2025-01-10T15:00:00Z';
        $result = parse_datetime_with_user_timezone($input);

        $this->assertEquals('2025-01-10 15:00:00', $result->format('Y-m-d H:i:s'));
        $this->assertEquals('UTC', $result->timezone->getName());
    }

    public function test_parses_timestamp_with_positive_offset_to_utc()
    {
        $input = '2025-01-10T15:00:00+01:00';
        $result = parse_datetime_with_user_timezone($input);

        $this->assertEquals('2025-01-10 14:00:00', $result->format('Y-m-d H:i:s'));
        $this->assertEquals('UTC', $result->timezone->getName());
    }

    public function test_parses_timestamp_with_negative_offset_to_utc()
    {
        $input = '2025-01-10T15:00:00-05:00';
        $result = parse_datetime_with_user_timezone($input);

        $this->assertEquals('2025-01-10 20:00:00', $result->format('Y-m-d H:i:s'));
        $this->assertEquals('UTC', $result->timezone->getName());
    }

    public function test_uses_user_timezone_when_no_tz_in_input()
    {
        $user = User::factory()->create();
        $user->setConfigValue('timezone', 'Africa/Lagos', ConfigValueType::String);

        $this->actingAs($user);

        $input = '2025-01-10T15:00:00';
        $result = parse_datetime_with_user_timezone($input);

        $this->assertEquals('2025-01-10 14:00:00', $result->format('Y-m-d H:i:s'));
        $this->assertEquals('UTC', $result->timezone->getName());
    }

    public function test_uses_user_timezone_europe_paris()
    {
        $user = User::factory()->create();
        $user->setConfigValue('timezone', 'Europe/Paris', ConfigValueType::String);

        $this->actingAs($user);

        $input = '2025-01-10T15:00:00';
        $result = parse_datetime_with_user_timezone($input);

        $this->assertEquals('2025-01-10 14:00:00', $result->format('Y-m-d H:i:s'));
        $this->assertEquals('UTC', $result->timezone->getName());
    }

    public function test_falls_back_to_default_parsing_without_user_timezone()
    {
        $input = '2025-01-10T15:00:00';
        $result = parse_datetime_with_user_timezone($input);

        $this->assertEquals('2025-01-10 15:00:00', $result->format('Y-m-d H:i:s'));
        $this->assertEquals('UTC', $result->timezone->getName());
    }

    public function test_explicit_tz_overrides_user_timezone()
    {
        $user = User::factory()->create();
        $user->setConfigValue('timezone', 'America/New_York', ConfigValueType::String);

        $this->actingAs($user);

        $input = '2025-01-10T15:00:00+01:00';
        $result = parse_datetime_with_user_timezone($input);

        $this->assertEquals('2025-01-10 14:00:00', $result->format('Y-m-d H:i:s'));
        $this->assertEquals('UTC', $result->timezone->getName());
    }
}
