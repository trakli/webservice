<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportConfirmRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'session_id' => 'required|integer|exists:import_sessions,id',
            'accepted' => 'required|array|min:1',
            'accepted.*.index' => 'required|integer|min:0',
            'accepted.*.amount' => 'nullable|numeric',
            'accepted.*.currency' => 'nullable|string',
            'accepted.*.type' => 'nullable|string|in:income,expense',
            'accepted.*.party' => 'nullable|string',
            'accepted.*.wallet' => 'nullable|string',
            'accepted.*.category' => 'nullable|string',
            'accepted.*.description' => 'nullable|string',
            'accepted.*.date' => 'nullable|date_format:Y-m-d',
            'auto_create_wallets' => 'nullable|boolean',
            'auto_create_parties' => 'nullable|boolean',
            'auto_create_categories' => 'nullable|boolean',
        ];
    }
}
