<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FixFailedImportsRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            '*.id' => 'required|integer|exists:failed_imports,id',
            '*.amount' => 'required|numeric',
            '*.type' => 'required|string|in:income,expense,transfer',
            '*.date' => 'required',
            '*.description' => 'required',
        ];
    }
}
