<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    /**
     * Handle unauthenticated users - return JSON for all requests.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function unauthenticated($request, AuthenticationException $exception): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => __('Unauthenticated. Please log in to access this resource.'),
            'errors' => [],
        ], 401);
    }

    /**
     * Render an exception into an HTTP response - handle unhandled 500 errors.
     *
     * @SuppressWarnings(PHPMD.ShortVariable)
     */
    public function render($request, Throwable $e): Response|JsonResponse
    {
        $response = parent::render($request, $e);

        // Handle 500 errors - convert both HTML and Laravel's default JSON to our custom format
        if ($response->getStatusCode() === 500) {
            // Check if this is Laravel's default JSON error response that we should customize
            $isDefaultLaravelJson = $response instanceof JsonResponse &&
                $this->isDefaultLaravelErrorResponse($response);

            if (! $response instanceof JsonResponse || $isDefaultLaravelJson) {
                if (config('app.debug')) {
                    return response()->json([
                        'success' => false,
                        'message' => $e->getMessage(),
                        'errors' => [
                            'exception' => get_class($e),
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                        ],
                    ], 500);
                }

                return response()->json([
                    'success' => false,
                    'message' => __('An error occurred while processing your request.'),
                    'errors' => [],
                ], 500);
            }
        }

        return $response;
    }

    /**
     * Check if response is Laravel's default error JSON format that should be customized.
     */
    private function isDefaultLaravelErrorResponse(JsonResponse $response): bool
    {
        $content = $response->getData(true);

        // Laravel's default error response has "message" field but not our custom fields
        // In debug mode it may have additional fields like exception, file, line, trace
        return isset($content['message']) &&
               ! isset($content['success']) &&
               ! isset($content['errors']);
    }
}
