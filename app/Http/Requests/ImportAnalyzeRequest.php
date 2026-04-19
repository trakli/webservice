<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportAnalyzeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'file' => 'required|file|mimes:csv,pdf,png,jpg,jpeg,tiff,bmp|max:10240',
            'document_type' => 'nullable|string|in:bank_statement,receipt,invoice,pay_stub,utility_bill',
        ];
    }
}
