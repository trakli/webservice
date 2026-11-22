<?php

namespace App\Http\Requests;

use App\Enums\TransactionIntent;
use App\Rules\Iso8601DateTime;
use App\Rules\ValidateClientId;

class UpdateTransactionRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'client_id' => ['nullable', 'string', new ValidateClientId()],
            'amount' => 'nullable|numeric|min:0.01',
            'type' => 'nullable|string|in:income,expense',
            'intent' => 'nullable|string|in:' . implode(',', TransactionIntent::values()),
            'datetime' => ['nullable', new Iso8601DateTime()],
            'description' => 'nullable|string',
            'party_id' => 'nullable|integer|exists:parties,id',
            'wallet_id' => 'sometimes|integer|exists:wallets,id',
            'group_id' => 'nullable|integer|exists:groups,id',
            'categories' => 'nullable|array|max:1',
            'categories.*' => 'integer|exists:categories,id',
            'is_recurring' => 'nullable|boolean',
            'recurrence_period' => 'nullable|string|in:daily,weekly,monthly,yearly',
            'recurrence_interval' => 'nullable|integer|min:1',
            'recurrence_ends_at' => ['nullable', 'date', 'after:today', new Iso8601DateTime()],
            'updated_at' => ['nullable', new Iso8601DateTime()],
        ];
    }
}
