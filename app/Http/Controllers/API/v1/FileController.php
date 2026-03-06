<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\API\ApiController;
use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Files', description: 'Endpoints for accessing files')]
class FileController extends ApiController
{
    #[OA\Get(
        path: '/files/{id}',
        summary: 'Get a file by ID',
        tags: ['Files'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'ID of the file',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'File content',
                content: new OA\MediaType(
                    mediaType: 'application/octet-stream',
                    schema: new OA\Schema(type: 'string', format: 'binary')
                )
            ),
            new OA\Response(
                response: 404,
                description: 'File not found'
            ),
            new OA\Response(
                response: 403,
                description: 'Unauthorized access'
            ),
        ]
    )]
    public function show(Request $request, int $fileId)
    {
        $file = File::find($fileId);

        if (! $file) {
            return $this->failure(__('File not found'), 404);
        }

        $fileable = $file->fileable;

        if (! $fileable || ! method_exists($fileable, 'user')) {
            return $this->failure(__('Unable to verify file ownership'), 403);
        }

        if (optional($fileable->user)->id !== $request->user()->id) {
            return $this->failure(__('You are not authorized to access this file'), 403);
        }

        if (! Storage::exists($file->path)) {
            return $this->failure(__('File not found on storage'), 404);
        }

        return Storage::response($file->path);
    }
}
