<?php

namespace App\Services;

use App\Models\ImportRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ImportRuleValidator
{
    public static function rules(): array
    {
        return [
            'kind' => 'required|string|in:party_alias,category_hint,wallet_hint,skip,instruction',
            'match_field' => 'nullable|string|in:description,counterparty,any',
            'match_mode' => 'nullable|string|in:contains,exact,starts_with',
            'pattern' => 'nullable|string|max:255',
            'target_type' => 'nullable|string|in:party,category,wallet',
            'target_id' => 'nullable|integer|min:1',
            'instruction' => 'nullable|string|max:500',
            'active' => 'sometimes|boolean',
        ];
    }

    public function validate(array $data, Model $owner): array
    {
        $data = Validator::make($data, self::rules())->validate();
        $errors = [];
        $instruction = $data['kind'] === 'instruction';
        foreach (['match_field', 'match_mode', 'pattern'] as $field) {
            if ($instruction ? ($data[$field] ?? null) !== null : trim((string) ($data[$field] ?? '')) === '') {
                $errors[$field] = $instruction ? 'Instruction rules cannot match rows.' : 'This field is required for matching rules.';
            }
        }
        if ($instruction && trim((string) ($data['instruction'] ?? '')) === '') {
            $errors['instruction'] = 'An instruction is required.';
        }
        if (! $instruction && ($data['instruction'] ?? null) !== null) {
            $errors['instruction'] = 'Only instruction rules may contain an instruction.';
        }
        $targetType = ImportRule::targetTypeFor($data['kind']);
        if ($targetType === null) {
            if (! empty($data['target_type']) || ! empty($data['target_id'])) {
                $errors['target_id'] = 'This kind cannot have a target.';
            }
        } elseif (($data['target_type'] ?? null) !== $targetType || ! isset($data['target_id']) || ! ImportRule::targetClass($targetType)::ownedBy($owner)->whereKey($data['target_id'])->exists()) {
            $errors['target_id'] = 'Select a target of the required type belonging to the same owner.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $data;
    }
}
