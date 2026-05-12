<?php

namespace App\Http\Requests;

use App\Rules\Iso8601DateTime;
use App\Rules\ValidateClientId;
use App\Services\FileService;
use Illuminate\Foundation\Http\FormRequest;

class StoreTransactionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'convert_myself_to_transfer' => 'sometimes|boolean',
            'client_id'                  => ['nullable', 'string', new ValidateClientId()],
            'amount'                     => 'required|numeric|min:0.01',
            'type'                       => 'required|string|in:income,expense',
            'description'                => 'nullable|string',
            'datetime'                   => ['nullable', new Iso8601DateTime()],
            'created_at'                 => ['nullable', new Iso8601DateTime()],
            'group_id'                   => 'nullable|integer|exists:groups,id',
            'party_id'                   => 'nullable|integer|exists:parties,id',
            'wallet_id'                  => 'required|integer|exists:wallets,id',
            'categories'                 => 'nullable|array',
            'categories.*'               => 'integer|exists:categories,id',
            'is_recurring'               => 'nullable|boolean',
            'recurrence_period'          => 'nullable|string|in:daily,weekly,monthly,yearly',
            'recurrence_interval'        => 'nullable|integer|min:1',
            'recurrence_ends_at'         => ['nullable', 'date', 'after:today', new Iso8601DateTime()],
            'files'                      => 'nullable|array',
            'files.*'                    => 'file|mimes:' . FileService::ALLOWED_EXTENSIONS . '|max:' . FileService::MAX_KILOBYTES,
            'from_wallet_id'             => 'required_if:convert_myself_to_transfer,true|integer|exists:wallets,id',
        ];
    }
}
