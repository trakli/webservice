<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\API\ApiController;
use App\Http\Traits\ApiQueryable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * @OA\Tag(name="Info", description="Operations related to server information")
 */
class VersionController extends ApiController
{
    use ApiQueryable;

    #[OA\Get(
        path: '/info',
        summary: 'Get server info',
        tags: ['Info'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful response',
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
    public function getServerInfo(Request $request): JsonResponse
    {
        return $this->success(['version' => '1.0.0']);
    }
}
