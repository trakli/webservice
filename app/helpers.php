<?php

use Carbon\Carbon;

if (! function_exists('format_iso8601_to_sql')) {
    function format_iso8601_to_sql(?string $iso8601): ?string
    {
        if (! $iso8601) {
            return null;
        }
        try {
            return Carbon::parse($iso8601)->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return null;
        }
    }
}
