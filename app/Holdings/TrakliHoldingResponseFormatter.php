<?php

namespace App\Holdings;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Whilesmart\Holdings\ResponseFormatters\DefaultResponseFormatter;

class TrakliHoldingResponseFormatter extends DefaultResponseFormatter
{
    public function success(mixed $data = null, string $message = 'Operation successful', int $statusCode = 200): JsonResponse
    {
        return parent::success($data, __($message), $statusCode);
    }

    public function failure(string $message = 'Operation failed', int $statusCode = 400, array $errors = []): JsonResponse
    {
        return parent::failure(__($message), $statusCode, $errors);
    }

    public function paginated(LengthAwarePaginator $paginator, string $resourceClass, string $message = 'Operation successful'): JsonResponse
    {
        return parent::paginated($paginator, $resourceClass, __($message));
    }
}
