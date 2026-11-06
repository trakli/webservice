<?php

namespace App\Http\Requests;

use App\Services\ImportReviewService;
use App\Services\ImportRuleValidator;

// use Illuminate\Foundation\Http\FormRequest;

class ImportConfirmRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $rules = [
            'save_rules' => 'sometimes|array',
            'save_rules.*' => 'required|array',
            'session_id' => 'required|integer|exists:import_sessions,id',
            'accepted' => 'required_unless:accept_all_unskipped,true|array|min:1',
            'accept_all_unskipped' => 'sometimes|boolean',
            'accepted.*.index' => 'required|integer|min:0',
            'auto_create_wallets' => 'nullable|boolean',
            'auto_create_parties' => 'nullable|boolean',
            'auto_create_categories' => 'nullable|boolean',
            'link_fees' => 'nullable|boolean',
        ];
        foreach (ImportReviewService::rules() as $field => $rule) {
            if ($field !== 'skip') {
                $rules['accepted.*.' . $field] = $rule;
            }
        }
        foreach (ImportRuleValidator::rules() as $field => $rule) {
            $rules['save_rules.*.' . $field] = $rule;
        }

        return $rules;
    }
}
