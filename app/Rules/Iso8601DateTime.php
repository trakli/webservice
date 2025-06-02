<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class Iso8601DateTime implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  \Closure(string): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail("The {$attribute} must be a string in ISO 8601 datetime format.");

            return;
        }

        // Try parsing with milliseconds first (e.g., 2025-04-30T15:17:54.120Z)
        $millisFormat = 'Y-m-d\TH:i:s.v\Z';
        $date = \DateTime::createFromFormat($millisFormat, $value);

        // If that fails, try without milliseconds (e.g., 2025-04-30T15:17:54Z)
        if (! $date) {
            $noMillisFormat = 'Y-m-d\TH:i:s\Z';
            $date = \DateTime::createFromFormat($noMillisFormat, $value);

            // If that fails, try with timezone offset format (e.g., 2025-06-02T15:17:54+00:00)
            if (! $date) {
                $date = \DateTime::createFromFormat(\DateTime::ATOM, $value);
            }
        }

        if (! $date) {
            $fail("The {$attribute} must be a valid ISO 8601 datetime (e.g., 2025-04-30T15:17:54.120Z or 2025-04-30T15:17:54Z or 2025-06-02T15:17:54+00:00).");
        }
    }
}
