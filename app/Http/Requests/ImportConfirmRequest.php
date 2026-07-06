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
            'accepted.*.wallet_id' => 'nullable|integer|min:1',
            'accepted.*.party_id' => 'nullable|integer|min:1',
            'accepted.*.category_id' => 'nullable|integer|min:1',
            'accepted.*.amount' => 'nullable|numeric',
            'accepted.*.type' => 'nullable|string|in:income,expense',
            'accepted.*.description' => 'nullable|string',
            'accepted.*.date' => 'nullable|date_format:Y-m-d',
            'accepted.*.fee' => 'nullable|numeric|min:0',
            'auto_create_wallets' => 'nullable|boolean',
            'auto_create_parties' => 'nullable|boolean',
            'auto_create_categories' => 'nullable|boolean',
            'link_fees' => 'nullable|boolean',
        ];
    }
}
