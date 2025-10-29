<?php

namespace App\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * @OA\Tag(name="Info", description="Operations related to server information")
 */
class VersionController extends ApiController
{
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
                response: 500,
                description: 'Server error'
            ),
        ]
    )]
    public function getServerInfo(): JsonResponse
    {
        return $this->success(['version' => '1.0.0']);
    }
}
