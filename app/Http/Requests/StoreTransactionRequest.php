<?php

namespace App\Http\Requests;

use App\Enums\TransactionIntent;
use App\Rules\Iso8601DateTime;
use App\Rules\ValidateClientId;
use App\Services\FileService;

class StoreTransactionRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'client_id' => ['nullable', 'string', new ValidateClientId()],
            'amount' => 'required|numeric|min:0.01',
            'type' => 'required|string|in:income,expense',
            'intent' => 'nullable|string|in:' . implode(',', TransactionIntent::values()),
            'description' => 'nullable|string',
            'datetime' => ['nullable', new Iso8601DateTime()],
            'created_at' => ['nullable', new Iso8601DateTime()],
            'group_id' => 'nullable|integer|exists:groups,id',
            'party_id' => 'nullable|integer|exists:parties,id',
            'wallet_id' => 'required|integer|exists:wallets,id',
            'categories' => 'nullable|array|max:1',
            'is_recurring' => 'nullable|boolean',
            'recurrence_period' => 'nullable|string|in:daily,weekly,monthly,yearly',
            'recurrence_interval' => 'nullable|integer|min:1',
            'recurrence_ends_at' => ['nullable', 'date', 'after:today', new Iso8601DateTime()],
            'categories.*' => 'integer|exists:categories,id',
            'files' => 'nullable|array',
            'files.*' => 'file|mimes:' . FileService::ALLOWED_EXTENSIONS . '|max:' . FileService::MAX_KILOBYTES,
        ];
    }
}
