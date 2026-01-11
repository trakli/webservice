<?php

use Carbon\Carbon;

if (! function_exists('format_iso8601_to_sql')) {
    function format_iso8601_to_sql(?string $iso8601): ?string
    {
        if (! $iso8601) {
            return null;
        }
        try {
            return parse_datetime_with_user_timezone($iso8601)->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return null;
        }
    }
}

if (! function_exists('parse_datetime_with_user_timezone')) {
    function parse_datetime_with_user_timezone(string $input): Carbon
    {
        $hasTimezone = preg_match('/Z$|[+-]\d{2}:\d{2}$/', $input);

        if ($hasTimezone) {
            return Carbon::parse($input)->utc();
        }

        $user = auth()->user();
        $userTimezone = $user?->getConfigValue('timezone');

        if ($userTimezone) {
            return Carbon::parse($input, $userTimezone)->utc();
        }

        return Carbon::parse($input)->utc();
    }
}
