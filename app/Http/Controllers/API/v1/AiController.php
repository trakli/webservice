<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\API\ApiController;
use App\Services\AiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'AI', description: 'AI-powered financial insights')]
class AiController extends ApiController
{
    private const EXCLUDED_NUMERIC_FORMAT_KEYS = ['id', 'user_id'];

    public function __construct(
        protected AiService $aiService
    ) {}

    #[OA\Post(
        path: '/ai/chat',
        summary: 'Ask a question about your finances',
        description: 'Uses AI to answer natural language questions about your financial data',
        tags: ['AI'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['message'],
                properties: [
                    new OA\Property(property: 'message', type: 'string', example: 'How much did I spend last month?'),
                    new OA\Property(
                        property: 'format_hint',
                        type: 'string',
                        enum: ['scalar', 'pair', 'record', 'list', 'pair_list', 'table', 'raw'],
                        example: 'table'
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'AI response',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean'),
                        new OA\Property(property: 'data', type: 'object', properties: [
                            new OA\Property(property: 'answer', type: 'string'),
                            new OA\Property(
                                property: 'format_type',
                                type: 'string',
                                enum: ['scalar', 'pair', 'record', 'list', 'pair_list', 'table', 'raw']
                            ),
                            new OA\Property(property: 'results', type: 'array', items: new OA\Items(type: 'object')),
                        ]),
                    ]
                )
            ),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 503, description: 'AI service unavailable'),
        ]
    )]
    public function chat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'required|string|max:1000',
            'format_hint' => 'nullable|string|in:scalar,pair,record,list,pair_list,table,raw',
        ]);

        $user = $request->user();
        $result = $this->aiService->ask(
            $validated['message'],
            $user->id,
            execute: true,
            formatHint: $validated['format_hint'] ?? null,
            generateResponse: true
        );

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['error'],
            ], 503);
        }

        $data = $result['data'];

        return response()->json([
            'success' => true,
            'data' => [
                'answer' => $data['human_response'] ?? $this->formatAnswer($data),
                'format_type' => $data['format_type'] ?? null,
                'results' => $data['rows'] ?? [],
            ],
        ]);
    }

    #[OA\Get(
        path: '/ai/health',
        summary: 'Check AI service health',
        tags: ['AI'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Service status',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'available', type: 'boolean'),
                    ]
                )
            ),
        ]
    )]
    public function health(): JsonResponse
    {
        return response()->json([
            'available' => $this->aiService->healthCheck(),
        ]);
    }

    private function formatAnswer(array $data): string
    {
        if (! empty($data['explanation'])) {
            return $data['explanation'];
        }

        if (empty($data['rows'])) {
            return 'I processed your question but found no matching data.';
        }

        $rows = $data['rows'];
        $rowCount = count($rows);

        if ($rowCount === 1 && count($rows[0]) === 1) {
            $key = array_keys($rows[0])[0];
            $value = $rows[0][$key];

            return $this->formatSingleValue($key, $value);
        }

        if ($rowCount === 1) {
            return $this->formatSingleRow($rows[0]);
        }

        return $this->formatMultipleRows($rows);
    }

    private function formatSingleValue(string $key, mixed $value): string
    {
        $formattedKey = str_replace('_', ' ', $key);

        if (is_numeric($value)) {
            $value = number_format((float) $value, 2);
        }

        return ucfirst($formattedKey).": {$value}";
    }

    private function formatSingleRow(array $row): string
    {
        $parts = [];
        foreach ($row as $key => $value) {
            if ($value === null) {
                continue;
            }
            $formattedKey = str_replace('_', ' ', $key);
            if (is_numeric($value) && ! in_array($key, self::EXCLUDED_NUMERIC_FORMAT_KEYS)) {
                $value = number_format((float) $value, 2);
            }
            $parts[] = ucfirst($formattedKey).": {$value}";
        }

        return implode(', ', $parts);
    }

    private function formatMultipleRows(array $rows): string
    {
        $count = count($rows);
        $columns = array_keys($rows[0]);

        if (count($columns) <= 3 && in_array('name', $columns)) {
            $items = [];
            foreach ($rows as $row) {
                $parts = [$row['name']];
                foreach ($row as $key => $value) {
                    if ($key === 'name' || $value === null) {
                        continue;
                    }
                    if (is_numeric($value)) {
                        $parts[] = number_format((float) $value, 2);
                    } else {
                        $parts[] = $value;
                    }
                }
                $items[] = implode(' - ', $parts);
            }

            return "Here's what I found:\n• ".implode("\n• ", $items);
        }

        if (in_array('name', $columns)) {
            $names = array_column($rows, 'name');

            return "Found {$count} items: ".implode(', ', $names);
        }

        return "Found {$count} results.";
    }
}
