<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

abstract class ApiFormRequest extends FormRequest
{
    /**
     * Handle a failed validation attempt.
     *
     * @param  \Illuminate\Contracts\Validation\Validator  $validator
     * @return void
     *
     * @throws \Illuminate\Http\Exceptions\HttpResponseException
     */
    protected function failedValidation(Validator $validator)
    {
        // This exactly mirrors the ApiController's failure() structure
        $response = response()->json([
            'success' => false,
            'message' => __('Server failed to validate request.'),
            'errors'  => $validator->errors()->toArray(),
        ], 422);

        throw new HttpResponseException($response);
    }
}
