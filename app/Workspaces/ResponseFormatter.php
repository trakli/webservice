<?php

namespace App\Workspaces;

use Illuminate\Http\JsonResponse;
use Whilesmart\Workspaces\Contracts\ResponseFormatter as Formatter;

class ResponseFormatter implements Formatter
{
    public function format(array $payload, int $status = 200): JsonResponse
    {
        $formatter = app(config('user-authentication.response_formatter'));
        if ($status >= 400) {
            return $formatter->failure($payload['message'] ?? $payload['error'] ?? 'Operation failed', $status, $payload['errors'] ?? []);
        }

        return $formatter->success($payload['data'] ?? null, $payload['message'] ?? 'Operation successful', $status);
    }
}
