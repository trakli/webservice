<?php

namespace App\Http\Controllers\API\v1;

use App\Enums\TransactionType;
use App\Http\Controllers\API\ApiController;
use App\Http\Requests\FileImportApiRequest;
use App\Http\Requests\FixFailedImportsRequest;
use App\Http\Requests\ImportAnalyzeRequest;
use App\Http\Requests\ImportConfirmRequest;
use App\Jobs\AnalyzeImportJob;
use App\Jobs\ImportFileJob;
use App\Services\DocumentProcessorManager;
use App\Services\DuplicateDetectionService;
use App\Services\FileImportService;
use App\Services\SuggestionEnricher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

/**
 * @OA\Tag(name="Import", description="Operations related to imports")
 */
class ImportController extends ApiController
{
    public function __construct(
        private FileImportService $fileImportService,
        private DocumentProcessorManager $processorManager,
        private SuggestionEnricher $enricher,
        private DuplicateDetectionService $duplicateService,
    ) {
    }

    #[OA\Post(
        path: '/import',
        summary: 'Import financial records into Trakli',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(
                        property: 'file',
                        description: 'File to upload',
                        type: 'string',
                        format: 'binary'
                    ),
                ]
            )
        ),
        tags: ['Import'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful response',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/FileImport'
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthorized'
            ),
            new OA\Response(
                response: 500,
                description: 'Server error'
            ),
        ]
    )]
    public function import(FileImportApiRequest $request): JsonResponse
    {
        $user = $request->user();
        if ($request->hasFile('file')) {
            try {
                // upload the file
                $file = $request->file('file');

                $fileName = time() . '_' . $file->getClientOriginalName();
                $filePath = $file->storeAs('imports', $fileName);

                Log::info('File stored at: ' . $filePath);

                $fileImport = $user->fileImports()->create([
                    'file_path' => $filePath,
                    'name' => $file->getClientOriginalName(),
                    'file_type' => $file->getClientOriginalExtension(),
                ]);
                ImportFileJob::dispatchAfterResponse($fileImport, app(FileImportService::class));

                return $this->success(
                    $fileImport,
                    __('File uploaded. Import scheduled. You will be notified when complete.')
                );
            } catch (\Exception $e) {
                logger()->error($e);

                return $this->failure(__('We could not upload your file.'));
            }
        }

        return $this->failure(__('No file uploaded.'));
    }

    #[OA\Get(
        path: '/imports/{id}/failed',
        summary: 'Get records that could not be imported for an import instance',
        tags: ['Import'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'Id of the import instance',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful response',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items('#/components/schemas/FailedImport')
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthorized'
            ),
            new OA\Response(
                response: 500,
                description: 'Server error'
            ),
        ]
    )]
    public function getFailedImports(Request $request, string $importId): JsonResponse
    {
        $user = $request->user();
        $perPage = 50;
        if ($request->has('perPage')) {
            $perPage = intval($request->perPage);
        }
        $import = $user->fileImports()->find($importId);
        if (is_null($import)) {
            return $this->failure(__('We could not find this import'), 404);
        }

        return $this->success($import->failedImports()->paginate($perPage));
    }

    #[OA\Get(
        path: '/imports',
        summary: 'Get all scheduled imports',
        tags: ['Import'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful response',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(
                        ref: '#/components/schemas/FileImport'
                    ),
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthorized'
            ),
            new OA\Response(
                response: 500,
                description: 'Server error'
            ),
        ]
    )]
    public function getImports(Request $request): JsonResponse
    {
        $user = $request->user();
        $imports = $user->fileImports()->orderBy('id', 'desc')->get();

        return $this->success($imports);
    }

    #[OA\Put(
        path: '/imports/{id}/fix',
        summary: 'Fix records that failed to import',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['id', 'amount', 'type', 'date'],
                type: 'array',
                items: new OA\Items(
                    '#/components/schemas/FailedImport'
                )
            )
        ),
        tags: ['Import'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'Id of the import instance',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful response',
            ),
            new OA\Response(
                response: 206,
                description: 'Some imports could not be fixed',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items('#/components/schemas/FailedImport')
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthorized'
            ),
            new OA\Response(
                response: 500,
                description: 'Server error'
            ),
        ]
    )]
    public function fixFailedImports(FixFailedImportsRequest $request, string $importId): JsonResponse
    {
        $user = $request->user();
        $import = $user->fileImports()->find($importId);
        if (is_null($import)) {
            return $this->failure(__('File import instance not found'), 404);
        }
        $failedImports = [];
        $data = $request->all();
        $count = count($data);
        for ($i = 0; $i < $count; $i++) {
            // verify if this failed import belong to this request
            $importToFix = $data[$i];
            $failedImport = $import->failedImports()->find($importToFix['id']);
            if (is_null($failedImport)) {
                $importToFix['reason'] = 'This failed record does not belong to this import instance';
                $failedImports[] = $importToFix;
            } elseif (! $this->fileImportService->isValidDate($importToFix['date'])) {
                $importToFix['reason'] = 'Date must be in the format YYYY-MM-DD';
                $failedImports[] = $importToFix;
            } else {
                $fixSucceeded = true;

                $transactionType = strtolower(trim($importToFix['type']));
                if (in_array($transactionType, [TransactionType::EXPENSE->value, TransactionType::INCOME->value])) {
                    try {
                        $this->fileImportService->importTransaction(
                            $this->convertImportObjectToArray($importToFix),
                            $transactionType,
                            $user,
                            autoCreateWallets: true,
                            autoCreateParties: true,
                            autoCreateCategories: true,
                        );
                    } catch (\Exception $e) {
                        $importToFix['reason'] = 'An error occurred while importing this transaction';
                        $failedImports[] = $importToFix;
                        $fixSucceeded = false;

                        Log::error($e);
                    }
                } elseif ($transactionType == '+Transfer') {
                    if (isset($data[$i + 1]) && $data[$i + 1]['type'] == '-Transfer') {
                        try {
                            $this->fileImportService->importTransfer(
                                $this->convertImportObjectToArray($importToFix),
                                $data[$i + 1],
                                $user
                            );
                        } catch (\Exception $e) {
                            $importToFix['reason'] = 'An error occurred while importing this transaction';
                            $data[$i + 1]['reason'] = 'An error occurred while importing this transaction';
                            $failedImports[] = $importToFix;
                            $failedImports[] = $data[$i + 1];
                            $fixSucceeded = false;

                            Log::error($e);
                        } finally {
                            $i += 1;
                        }
                    } else {
                        $importToFix['reason'] = 'Corresponding -Transfer transaction not found';
                        $failedImports[] = $importToFix;
                        $fixSucceeded = false;
                    }
                } else {
                    $importToFix['reason'] = 'Invalid transaction type';
                    $failedImports[] = $importToFix;
                    $fixSucceeded = false;
                }

                if ($fixSucceeded) {
                    $failedImport->delete();
                }
            }
        }
        if (count($failedImports) > 0) {
            return $this->success($failedImports, __('Some imports could not be fixed'), 206);
        }

        return $this->success();
    }

    private function convertImportObjectToArray($import): array
    {
        return [
            $import['amount'] ?? '',
            $import['currency'] ?? '',
            $import['type'] ?? '',
            $import['party'] ?? '',
            $import['wallet'] ?? '',
            $import['category'] ?? '',
            $import['description'] ?? '',
            $import['date'] ?? '',
        ];
    }

    #[OA\Post(
        path: '/import/analyze',
        summary: 'Analyze a document and return transaction suggestions',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'file', type: 'string', format: 'binary'),
                    new OA\Property(property: 'document_type', type: 'string', enum: ['bank_statement', 'receipt', 'invoice', 'pay_stub', 'utility_bill']),
                ]
            )
        ),
        tags: ['Import'],
        responses: [
            new OA\Response(response: 200, description: 'Suggestions returned'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function analyze(ImportAnalyzeRequest $request): JsonResponse
    {
        $user = $request->user();
        $file = $request->file('file');

        try {
            $mimeType = $file->getMimeType() ?? '';
            $extension = strtolower($file->getClientOriginalExtension());

            if (! $this->processorManager->canHandle($mimeType, $extension)) {
                return $this->failure(__('No processor available for this file type.'), 422);
            }

            // Store file for background processing
            $storedPath = $file->store('import-analyze');

            $processor = $this->processorManager->getProcessor($mimeType, $extension);
            $processorName = class_basename($processor);

            $session = $user->importSessions()->create([
                'file_name' => $file->getClientOriginalName(),
                'file_type' => $extension,
                'document_type' => $request->input('document_type'),
                'processor' => $processorName,
                'status' => 'analyzing',
                'suggestions' => [],
            ]);

            AnalyzeImportJob::dispatch(
                $session->id,
                $storedPath,
                $file->getClientOriginalName(),
                $mimeType,
                $extension,
            );

            return $this->success($session, __('Document uploaded. Analysis in progress.'));
        } catch (\RuntimeException $e) {
            return $this->failure($e->getMessage(), 422);
        } catch (\Throwable $e) {
            Log::error('Import analyze failed', ['error' => $e->getMessage()]);

            return $this->failure(__('Failed to start document analysis.'));
        }
    }

    #[OA\Post(
        path: '/import/confirm',
        summary: 'Confirm and create transactions from reviewed suggestions',
        description: 'Each accepted item should reference existing wallet/party/category rows by ID. '
            . 'When an ID is omitted and the matching auto_create_* flag is true, the analyzer\'s '
            . 'suggested name is used to create the resource (wallets additionally need a currency).',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['session_id', 'accepted'],
                properties: [
                    new OA\Property(property: 'session_id', type: 'integer'),
                    new OA\Property(
                        property: 'accepted',
                        type: 'array',
                        items: new OA\Items(
                            required: ['index'],
                            properties: [
                                new OA\Property(
                                    property: 'index',
                                    type: 'integer',
                                    description: 'Zero-based position of the suggestion in the session.'
                                ),
                                new OA\Property(
                                    property: 'wallet_id',
                                    type: 'integer',
                                    nullable: true,
                                    description: 'Existing wallet owned by the caller. Required unless '
                                        . 'auto_create_wallets is true and the suggestion has a wallet name and currency.'
                                ),
                                new OA\Property(
                                    property: 'party_id',
                                    type: 'integer',
                                    nullable: true,
                                    description: 'Existing party owned by the caller.'
                                ),
                                new OA\Property(
                                    property: 'category_id',
                                    type: 'integer',
                                    nullable: true,
                                    description: 'Existing category owned by the caller.'
                                ),
                                new OA\Property(
                                    property: 'amount',
                                    type: 'number',
                                    format: 'float',
                                    nullable: true,
                                    description: 'Override the suggested amount.'
                                ),
                                new OA\Property(
                                    property: 'type',
                                    type: 'string',
                                    enum: ['income', 'expense'],
                                    nullable: true
                                ),
                                new OA\Property(property: 'description', type: 'string', nullable: true),
                                new OA\Property(property: 'date', type: 'string', format: 'date', nullable: true),
                            ],
                            type: 'object',
                        )
                    ),
                    new OA\Property(
                        property: 'auto_create_wallets',
                        type: 'boolean',
                        default: false,
                        description: 'Create a wallet from the suggestion when wallet_id is omitted.'
                    ),
                    new OA\Property(
                        property: 'auto_create_parties',
                        type: 'boolean',
                        default: false,
                        description: 'Create a party from the suggestion when party_id is omitted.'
                    ),
                    new OA\Property(
                        property: 'auto_create_categories',
                        type: 'boolean',
                        default: false,
                        description: 'Create a category from the suggestion when category_id is omitted.'
                    ),
                ]
            )
        ),
        tags: ['Import'],
        responses: [
            new OA\Response(response: 200, description: 'Transactions created'),
            new OA\Response(response: 206, description: 'Some suggestions failed (see errors)'),
            new OA\Response(response: 404, description: 'Session not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function confirm(ImportConfirmRequest $request): JsonResponse
    {
        $user = $request->user();
        $session = $user->importSessions()->find($request->input('session_id'));

        if (is_null($session)) {
            return $this->failure(__('Import session not found.'), 404);
        }

        if ($session->status !== 'ready') {
            return $this->failure(__('This import session has already been processed.'), 422);
        }

        $suggestions = $session->suggestions;
        $accepted = $request->input('accepted');
        $autoCreate = [
            'wallets' => $request->boolean('auto_create_wallets', false),
            'parties' => $request->boolean('auto_create_parties', false),
            'categories' => $request->boolean('auto_create_categories', false),
        ];
        $createdCount = 0;
        $errors = [];

        foreach ($accepted as $item) {
            $result = $this->processAcceptedItem($item, $suggestions, $user, $autoCreate);

            if ($result === true) {
                $createdCount++;
            } elseif (is_string($result)) {
                $errors[] = $result;
            }
        }

        $data = [
            'created_count' => $createdCount,
            'errors' => $errors,
        ];

        if ($createdCount === 0 && ! empty($errors)) {
            return $this->success($data, __('Import failed. No transactions were created.'), 206);
        }

        $session->update(['status' => 'confirmed']);

        if (! empty($errors)) {
            return $this->success($data, __('Import completed with some errors.'), 206);
        }

        return $this->success($data, __('All transactions imported successfully.'));
    }

    /**
     * Process a single accepted suggestion item.
     *
     * @return true|string True on success, error message string on failure
     */
    private function processAcceptedItem(array $item, array $suggestions, $user, array $autoCreate = []): true|string
    {
        $index = $item['index'];

        if (! isset($suggestions[$index])) {
            return "Invalid suggestion index: {$index}";
        }

        $merged = $this->mergeUserEdits($suggestions[$index], $item);
        $transactionType = $merged['type'] ?? 'expense';

        if (! in_array($transactionType, [TransactionType::EXPENSE->value, TransactionType::INCOME->value])) {
            return "Invalid transaction type at index {$index}";
        }

        try {
            $this->fileImportService->importTransactionFromConfirm(
                merged: $merged,
                transactionType: $transactionType,
                user: $user,
                autoCreateWallets: $autoCreate['wallets'] ?? false,
                autoCreateParties: $autoCreate['parties'] ?? false,
                autoCreateCategories: $autoCreate['categories'] ?? false,
            );

            return true;
        } catch (\Exception $e) {
            Log::error('Import confirm failed for suggestion', [
                'index' => $index,
                'error' => $e->getMessage(),
            ]);

            return "Failed to import suggestion at index {$index}: {$e->getMessage()}";
        }
    }

    /**
     * Merge user edits into a suggestion array.
     */
    private function mergeUserEdits(array $suggestion, array $item): array
    {
        $fields = [
            'amount', 'type', 'description', 'date',
            'wallet_id', 'party_id', 'category_id',
        ];
        foreach ($fields as $field) {
            if (isset($item[$field])) {
                $suggestion[$field] = $item[$field];
            }
        }

        return $suggestion;
    }

    #[OA\Get(
        path: '/import/sessions',
        summary: 'Get all import sessions',
        tags: ['Import'],
        responses: [
            new OA\Response(response: 200, description: 'Sessions list'),
        ]
    )]
    public function getSessions(Request $request): JsonResponse
    {
        $sessions = $request->user()
            ->importSessions()
            ->orderBy('id', 'desc')
            ->get();

        return $this->success($sessions);
    }

    #[OA\Get(
        path: '/import/sessions/{id}',
        summary: 'Get a specific import session',
        tags: ['Import'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Session details'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function getSession(Request $request, string $sessionId): JsonResponse
    {
        $session = $request->user()->importSessions()->find($sessionId);

        if (is_null($session)) {
            return $this->failure(__('Import session not found.'), 404);
        }

        return $this->success($session);
    }

    #[OA\Delete(
        path: '/import/sessions/{id}',
        summary: 'Delete an import session',
        tags: ['Import'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Session deleted'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function destroySession(Request $request, string $sessionId): JsonResponse
    {
        $session = $request->user()->importSessions()->find($sessionId);

        if (is_null($session)) {
            return $this->failure(__('Import session not found.'), 404);
        }

        $session->delete();

        return $this->success(null, __('Import session deleted.'));
    }
}
