<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

abstract class ApiFormRequest extends FormRequest
{
    /**
     * Cast textual booleans for attributes the rules declare as boolean.
     *
     * Unrecognised values are left untouched so the boolean rule still rejects them
     * instead of silently reading as false.
     */
    public function validationData(): array
    {
        $data = parent::validationData();

        foreach ($this->rules() as $attribute => $rule) {
            if (! array_key_exists($attribute, $data) || ! is_string($data[$attribute])) {
                continue;
            }

            if (! $this->expectsBoolean($rule)) {
                continue;
            }

            $casted = filter_var($data[$attribute], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if (! is_null($casted)) {
                $data[$attribute] = $casted;
            }
        }

        return $data;
    }

    protected function failedValidation(Validator $validator): void
    {
        $errors = $validator->errors();
        $named = $errors->hasAny(array_keys($this->rules()));

        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => $named
                ? __('Server failed to validate request.')
                : __('Server unable to process request.'),
            'errors' => $errors->toArray(),
        ], $named ? 422 : 400));
    }

    private function expectsBoolean($rule): bool
    {
        $rules = is_array($rule) ? $rule : explode('|', (string) $rule);

        foreach ($rules as $singleRule) {
            if (is_string($singleRule) && strtolower(explode(':', $singleRule)[0]) === 'boolean') {
                return true;
            }
        }

        return false;
    }
}
