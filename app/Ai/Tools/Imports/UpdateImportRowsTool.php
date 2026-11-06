<?php

namespace App\Ai\Tools\Imports;

use App\Models\ImportSession;
use App\Services\ImportReviewService;
use Illuminate\Support\Facades\Validator;
use Whilesmart\Agents\Enums\ToolPermission;
use Whilesmart\Agents\ValueObjects\ParameterSpec;
use Whilesmart\Agents\ValueObjects\ToolContext;

class UpdateImportRowsTool extends ImportReviewTool
{
    public function name(): string
    {
        return 'update_import_rows';
    }

    public function description(): string
    {
        return 'Edit draft import rows immediately. This never creates transactions.';
    }

    public function permission(): ToolPermission
    {
        return ToolPermission::WRITE;
    }

    public function parameters(): array
    {
        return [...parent::parameters(),
            ParameterSpec::arrayOf('ids', 'Suggestion ids, or use match', required: false),
            ParameterSpec::object('match', 'Select rows by statement text', [
                ParameterSpec::enum('field', 'Text field', ['description', 'counterparty', 'any']),
                ParameterSpec::enum('mode', 'Matching mode', ['contains', 'exact', 'starts_with']),
                ParameterSpec::string('pattern', 'Text to match'),
            ], false),
            ParameterSpec::object('set', 'Fields to change', [
                ParameterSpec::number('party_id', 'Existing party id', false),
                ParameterSpec::number('category_id', 'Existing category id', false),
                ParameterSpec::number('wallet_id', 'Existing wallet id', false),
                ParameterSpec::string('party', 'Exact existing party name', false),
                ParameterSpec::string('category', 'Exact existing category name', false),
                ParameterSpec::string('wallet', 'Exact existing wallet name', false),
                ParameterSpec::number('amount', 'Amount', false),
                ParameterSpec::number('fee', 'Fee', false),
                ParameterSpec::enum('type', 'Transaction type', ['income', 'expense'], false),
                ParameterSpec::string('date', 'Date as YYYY-MM-DD', false),
                ParameterSpec::string('description', 'Description', false),
                ParameterSpec::boolean('skip', 'Exclude from import', false),
            ]),
        ];
    }

    protected function run(array $arguments, ToolContext $context, ImportSession $session): array
    {
        Validator::make($arguments, [
            'ids' => 'required_without:match|prohibits:match|array|min:1', 'ids.*' => 'required|string',
            'match' => 'required_without:ids|array', 'set' => 'required|array|min:1',
        ])->validate();
        $ids = $arguments['ids'] ?? $this->matchingIds($session->suggestions, $arguments['match']);
        $set = $this->resolveNames($arguments['set'], $context);
        $session = app(ImportReviewService::class)->update($session, $context->user, [['ids' => $ids, 'set' => $set]], 'assistant');

        return ['changed' => count(array_unique($ids)), 'revision' => $session->revision];
    }
}
