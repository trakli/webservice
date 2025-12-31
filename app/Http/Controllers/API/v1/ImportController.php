<?php

namespace App\Http\Controllers\API\v1;

use App\Enums\TransactionType;
use App\Http\Controllers\API\ApiController;
use App\Http\Requests\FileImportApiRequest;
use App\Http\Requests\FixFailedImportsRequest;
use App\Jobs\ImportFileJob;
use App\Services\FileImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

/**
 * @OA\Tag(name="Import", description="Operations related to imports")
 */
class ImportController extends ApiController
{
    private FileImportService $fileImportService;

    public function __construct(FileImportService $fileImportService)
    {
        $this->fileImportService = $fileImportService;
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

                $fileName = time().'_'.$file->getClientOriginalName();
                $filePath = $file->storeAs('imports', $fileName);

                Log::info('File stored at: '.$filePath);

                $fileImport = $user->fileImports()->create([
                    'file_path' => $filePath,
                    'name' => $file->getClientOriginalName(),
                    'file_type' => $file->getClientOriginalExtension(),
                ]);
                ImportFileJob::dispatchAfterResponse($fileImport, app(FileImportService::class));

                return $this->success($fileImport, __('File uploaded. Import scheduled. You will be notified when complete.'));
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
                content: new OA\JsonContent(type: 'array',
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
    public function getFailedImports(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $perPage = 50;
        if ($request->has('perPage')) {
            $perPage = intval($request->perPage);
        }
        $import = $user->fileImports()->find($id);
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
                content: new OA\JsonContent(type: 'array',
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
    public function fixFailedImports(FixFailedImportsRequest $request, string $id): JsonResponse
    {
        $user = $request->user();
        $import = $user->fileImports()->find($id);
        if (is_null($import)) {
            return $this->failure(__('File import instance not found'), 404);
        }
        $failedImportsToReturn = [];
        $data = $request->all();
        for ($i = 0; $i < count($data); $i++) {
            // verify if this failed import belong to this request
            $importToFix = $data[$i];
            $failedImport = $import->failedImports()->find($importToFix['id']);
            if (is_null($failedImport)) {
                $importToFix['reason'] = 'This failed record does not belong to this import instance';
                $failedImportsToReturn[] = $importToFix;
            } elseif (! $this->fileImportService->isValidDate($importToFix['date'])) {
                $importToFix['reason'] = 'Date must be in the format YYYY-MM-DD';
                $failedImportsToReturn[] = $importToFix;

            } else {
                $fixSucceeded = true;

                $transactionType = strtolower(trim($importToFix['type']));
                if (in_array($transactionType, [TransactionType::EXPENSE->value, TransactionType::INCOME->value])) {
                    try {
                        $this->fileImportService->importTransaction($this->convertImportObjectToArray($importToFix), $transactionType, $user);
                    } catch (\Exception $e) {
                        $importToFix['reason'] = 'An error occurred while importing this transaction';
                        $failedImportsToReturn[] = $importToFix;
                        $fixSucceeded = false;

                        Log::error($e);
                    }
                } elseif ($transactionType == '+Transfer') {
                    if (isset($data[$i + 1]) && $data[$i + 1]['type'] == '-Transfer') {
                        try {
                            $this->fileImportService->importTransfer($this->convertImportObjectToArray($importToFix), $data[$i + 1], $transactionType, $user);
                        } catch (\Exception $e) {
                            $importToFix['reason'] = 'An error occurred while importing this transaction';
                            $data[$i + 1]['reason'] = 'An error occurred while importing this transaction';
                            $failedImportsToReturn[] = $importToFix;
                            $failedImportsToReturn[] = $data[$i + 1];
                            $fixSucceeded = false;

                            Log::error($e);
                        } finally {
                            $i += 1;
                        }
                    } else {
                        $importToFix['reason'] = 'Corresponding -Transfer transaction not found';
                        $failedImportsToReturn[] = $importToFix;
                        $fixSucceeded = false;
                    }
                } else {
                    $importToFix['reason'] = 'Invalid transaction type';
                    $failedImportsToReturn[] = $importToFix;
                    $fixSucceeded = false;
                }

                if ($fixSucceeded) {
                    $failedImport->delete();
                }
            }
        }
        if (count($failedImportsToReturn) > 0) {
            return $this->success($failedImportsToReturn, __('Some imports could not be fixed'), 206);
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
}
