<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Ramsey\Uuid\Uuid;

class ValidateClientId implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // check if the value is in the format xxx:xxx
        $exploded_value = explode(':', $value);
        if (count($exploded_value) !== 2) {
            // @phpcs:ignore
            $fail("The {$attribute} must be in the format xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx:xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx.");

            return;
        }

        $device_id = $exploded_value[0];
        $random_id = $exploded_value[1];

        if (! Uuid::isValid($device_id)) {
            $fail("The device id in {$attribute} is not a valid UUID.");
        }

        if (! Uuid::isValid($random_id)) {
            $fail("The random id in {$attribute} is not a valid UUID.");
        }
    }
}
