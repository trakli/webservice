<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class Iso8601Date implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  \Closure(string): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $pattern = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{3})?Z$/';

        if (! is_string($value) || ! preg_match($pattern, $value)) {
            $fail("The $attribute must be a valid ISO 8601 datetime (e.g., 2025-04-30T15:17:54.120Z).");
        }
    }
}
