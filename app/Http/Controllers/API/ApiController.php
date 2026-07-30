<?php

namespace App\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

#[OA\OpenApi(
    security: [
        ['bearerAuth' => []],
    ]
)]
#[OA\Info(version: '1.1.2', title: 'Trakli API')]
#[OA\Server(url: 'http://localhost:8000/api/v1', description: 'Local server')]
#[OA\Server(url: 'https://api.dev.trakli.app/api/v1', description: 'Development server')]
#[OA\Server(
    url: '{protocol}://{host}/api/v1',
    description: 'Dynamic server URL',
    variables: [
        new OA\ServerVariable(serverVariable: 'protocol', default: 'https', enum: ['http', 'https']),
        new OA\ServerVariable(
            serverVariable: 'host',
            default: 'api.dev.trakli.app',
            enum: ['api.dev.trakli.app', 'api.staging.trakli.app']
        ),
    ]
)]
#[OA\Components(
    securitySchemes: [
        new OA\SecurityScheme(
            securityScheme: 'bearerAuth',
            type: 'http',
            description: 'Bearer token authentication',
            scheme: 'bearer'
        ),
    ]
)]
class ApiController extends BaseController
{
    /**
     * Check if the authenticated user can access the given resource.
     *
     * @param  mixed  $resource
     * @param  string|null  $ownerKey
     * @return void
     *
     * @throws \Illuminate\Http\Exceptions\HttpResponseException
     */
    public function userCanAccessResource($resource, $ownerKey = 'user_id')
    {
        if (is_null($resource)) {
            abort(Response::HTTP_NOT_FOUND, 'Resource not found.');
        }

        if (auth()->id() !== $resource->{$ownerKey}) {
            abort(Response::HTTP_FORBIDDEN, 'You are not authorized to access this resource.');
        }
    }

    /**
     * Validate the request data.
     */
    protected function validateRequest(Request $request, array $rules): array
    {
        $validator = Validator::make($this->castBooleanInput($request->all(), $rules), $rules);

        if ($validator->fails()) {
            $errors = $validator->errors();

            if ($errors->hasAny(array_keys($rules))) {
                return [
                    'isValidated' => false,
                    'message' => __('Server failed to validate request.'),
                    'code' => 422,
                    'errors' => $errors->toArray(),
                ];
            }

            return [
                'isValidated' => false,
                'message' => __('Server unable to process request.'),
                'code' => 400,
                'errors' => $errors->toArray(),
            ];
        }

        return ['isValidated' => true, 'data' => $validator->validated()];
    }

    /**
     * Cast textual booleans for attributes the rules declare as boolean.
     *
     * Unrecognised values are left untouched so the boolean rule still rejects them
     * instead of silently reading as false.
     */
    private function castBooleanInput(array $data, array $rules): array
    {
        foreach ($rules as $attribute => $rule) {
            if (! array_key_exists($attribute, $data) || ! is_string($data[$attribute])) {
                continue;
            }

            if (! $this->expectsBoolean($rule)) {
                continue;
            }

            $casted = filter_var($data[$attribute], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if (! is_null($casted)) {
                $data[$attribute] = $casted;
            }
        }

        return $data;
    }

    /**
     * Determine whether a rule set holds the boolean rule.
     *
     * @param  mixed  $rule
     */
    private function expectsBoolean($rule): bool
    {
        $rules = is_array($rule) ? $rule : explode('|', (string) $rule);

        foreach ($rules as $singleRule) {
            if (is_string($singleRule) && strtolower(explode(':', $singleRule)[0]) === 'boolean') {
                return true;
            }
        }

        return false;
    }

    /**
     * Return a success response.
     *
     * @param  mixed  $data
     */
    protected function success(
        $data = null,
        string $message = 'Operation successful',
        int $statusCode = 200
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $statusCode);
    }

    /**
     * Return a failure response.
     */
    protected function failure(
        string $message = 'Operation failed',
        int $statusCode = 400,
        array $errors = []
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], $statusCode);
    }
}
