<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use OpenApi\Attributes as OA;

#[OA\Parameter(
    parameter: 'limitParam',
    name: 'limit',
    description: 'Number of items to return per page',
    in: 'query',
    required: false,
    schema: new OA\Schema(type: 'integer', default: 20)
)]
#[OA\Parameter(
    parameter: 'syncedSinceParam',
    name: 'synced_since',
    description: 'Get recent changes after this date',
    in: 'query',
    required: false,
    schema: new OA\Schema(type: 'string', format: 'date-time')
)]
#[OA\Parameter(
    parameter: 'noClientIdParam',
    name: 'no_client_id',
    description: 'Get results with no client id',
    in: 'query',
    required: false,
    schema: new OA\Schema(type: 'boolean')
)]
class Controller extends BaseController
{
    use AuthorizesRequests;
    use ValidatesRequests;
}
