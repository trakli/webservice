<?php

namespace App\Services\DocumentProcessors;

use Carbon\Carbon;

trait ParsesLlmData
{
    private function parseLlmJson(string $text): ?array
    {
        $text = trim($text);

        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*/', '', $text);
            $text = preg_replace('/\s*```$/', '', $text);
        }

        $decoded = json_decode($text, true);

        return (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : null;
    }

    private function normalizeDate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }

        // Remove commas that confuse Carbon (e.g. "05 Apr, 2024" → "05 Apr 2024")
        $value = str_replace(',', '', $value);

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Exception) {
            return null;
        }
    }
}
