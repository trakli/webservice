<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'smartql' => [
        'url' => env('SMARTQL_URL', 'http://smartql:8000'),
    ],

    'llm' => [
        'provider' => env('LLM_PROVIDER', 'groq'),
        'model' => env('LLM_MODEL', 'llama-3.1-8b-instant'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Remote Document Processor
    |--------------------------------------------------------------------------
    |
    | Configure a remote service to extract transactions from documents.
    | Any service that accepts a file upload and returns JSON with a
    | "transactions" array works. Use response_mapping to adapt
    | services that use different field names.
    |
    | Expected response: { "transactions": [{ "date", "description", "amount", "currency" }] }
    |
    */
    'document_processor' => [
        'url' => env('DOCUMENT_PROCESSOR_URL'),
        'timeout' => env('DOCUMENT_PROCESSOR_TIMEOUT', 120),
        'auth_type' => env('DOCUMENT_PROCESSOR_AUTH_TYPE', 'none'),
        'auth_credentials' => env('DOCUMENT_PROCESSOR_AUTH_CREDENTIALS'),
        'auth_header' => env('DOCUMENT_PROCESSOR_AUTH_HEADER', 'X-API-Key'),
        'file_field' => env('DOCUMENT_PROCESSOR_FILE_FIELD', 'file'),
        'extra_params' => [],
        /*
        | Response mapping — tells the processor how to find transactions in the response.
        |
        | Two modes:
        |   "fields" (default) — each item in the array has named keys:
        |       { "date": "...", "amount": -50, "description": "..." }
        |
        |   "text_block" — each item has a text blob split by newlines:
        |       { "content": "31 Mar, 2024\nCard charge\n-36.12\nEUR" }
        |       Use line_mapping to say which line index maps to which field.
        |
        | Example for a standard API:
        |   'transactions_path' => 'transactions',
        |   'mode' => 'fields',
        |
        | Example for PageMind (elements as text blocks):
        |   'transactions_path' => 'elements',
        |   'mode' => 'text_block',
        |   'content_field' => 'content',
        |   'filter' => ['key' => 'subtype', 'value' => 'paragraph'],
        |   'line_mapping' => ['date' => 0, 'description' => 1, 'amount' => 2, 'currency' => 3, 'status' => 4],
        */
        'response_mapping' => [
            'transactions_path' => env('DOCUMENT_PROCESSOR_TRANSACTIONS_PATH', 'transactions'),
            'mode' => env('DOCUMENT_PROCESSOR_MODE', 'fields'),
            'content_field' => 'content',
            'filter' => env('DOCUMENT_PROCESSOR_FILTER_KEY')
                ? ['key' => env('DOCUMENT_PROCESSOR_FILTER_KEY'), 'value' => env('DOCUMENT_PROCESSOR_FILTER_VALUE')]
                : null,
            'line_mapping' => [
                'date' => (int) env('DOCUMENT_PROCESSOR_LINE_DATE', 0),
                'description' => (int) env('DOCUMENT_PROCESSOR_LINE_DESCRIPTION', 1),
                'amount' => (int) env('DOCUMENT_PROCESSOR_LINE_AMOUNT', 2),
                'currency' => (int) env('DOCUMENT_PROCESSOR_LINE_CURRENCY', 3),
                'status' => env('DOCUMENT_PROCESSOR_LINE_STATUS') !== null
                    ? (int) env('DOCUMENT_PROCESSOR_LINE_STATUS')
                    : null,
            ],
        ],
    ],

];
