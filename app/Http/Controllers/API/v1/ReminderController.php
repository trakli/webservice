<?php

namespace App\Http\Controllers\API\v1;

use App\Enums\ReminderStatus;
use App\Enums\ReminderType;
use App\Http\Controllers\API\ApiController;
use App\Http\Traits\ApiQueryable;
use App\Models\Reminder;
use App\Rules\Iso8601DateTime;
use App\Rules\ValidateClientId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Reminders', description: 'Endpoints for managing reminders')]
class ReminderController extends ApiController
{
    use ApiQueryable;

    #[OA\Get(
        path: '/reminders',
        summary: 'List all reminders',
        tags: ['Reminders'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/limitParam'),
            new OA\Parameter(ref: '#/components/parameters/syncedSinceParam'),
            new OA\Parameter(ref: '#/components/parameters/noClientIdParam'),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful operation',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'last_sync', type: 'string', format: 'date-time'),
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Reminder')),
                    ],
                    type: 'object'
                )
            ),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = $user->reminders();

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        try {
            $data = $this->applyApiQuery($request, $query);

            return $this->success($data);
        } catch (\InvalidArgumentException $e) {
            return $this->failure($e->getMessage(), 422);
        }
    }

    #[OA\Post(
        path: '/reminders',
        summary: 'Create a new reminder',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['title'],
                properties: [
                    new OA\Property(property: 'client_id', type: 'string'),
                    new OA\Property(property: 'title', type: 'string'),
                    new OA\Property(property: 'description', type: 'string'),
                    new OA\Property(property: 'type', type: 'string', enum: ['daily_tracking', 'weekly_review', 'monthly_summary', 'bill_due', 'budget_alert', 'custom']),
                    new OA\Property(property: 'trigger_at', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'due_at', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'repeat_rule', type: 'string', example: 'FREQ=DAILY;BYHOUR=20;BYMINUTE=0'),
                    new OA\Property(property: 'timezone', type: 'string', example: 'UTC'),
                    new OA\Property(property: 'priority', type: 'integer'),
                    new OA\Property(property: 'metadata', type: 'object'),
                ]
            )
        ),
        tags: ['Reminders'],
        responses: [
            new OA\Response(response: 201, description: 'Reminder created successfully', content: new OA\JsonContent(ref: '#/components/schemas/Reminder')),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'client_id' => ['nullable', 'string', new ValidateClientId],
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => ['nullable', new Enum(ReminderType::class)],
            'trigger_at' => ['nullable', new Iso8601DateTime],
            'due_at' => ['nullable', new Iso8601DateTime],
            'repeat_rule' => 'nullable|string|max:500',
            'timezone' => 'nullable|string|timezone',
            'priority' => 'nullable|integer|min:0|max:255',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return $this->failure(__('Validation error'), 422, $validator->errors()->all());
        }

        $data = $validator->validated();
        $user = $request->user();

        try {
            $reminder = DB::transaction(function () use ($data, $request, $user) {
                $data['status'] = ReminderStatus::ACTIVE;

                /** @var Reminder $reminder */
                $reminder = $user->reminders()->create($data);

                if (isset($request['client_id'])) {
                    $reminder->setClientGeneratedId($request['client_id'], $user);
                }
                $reminder->markAsSynced();

                $reminder->calculateNextTrigger();
                $reminder->save();

                return $reminder;
            });

            $reminder->refresh();

            return $this->success($reminder, __('Reminder created successfully'), 201);
        } catch (ValidationException $e) {
            return $this->failure(__('Validation error'), 422, $e->errors());
        } catch (\Exception $e) {
            return $this->failure(__('Failed to create reminder'), 500, [$e->getMessage()]);
        }
    }

    #[OA\Get(
        path: '/reminders/{id}',
        summary: 'Get a specific reminder',
        tags: ['Reminders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation', content: new OA\JsonContent(ref: '#/components/schemas/Reminder')),
            new OA\Response(response: 404, description: 'Reminder not found'),
        ]
    )]
    public function show(int $id): JsonResponse
    {
        $user = request()->user();
        $reminder = $user->reminders()->find($id);

        if (! $reminder) {
            return $this->failure(__('Reminder not found'), 404);
        }

        return $this->success($reminder);
    }

    #[OA\Put(
        path: '/reminders/{id}',
        summary: 'Update a specific reminder',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'client_id', type: 'string'),
                    new OA\Property(property: 'title', type: 'string'),
                    new OA\Property(property: 'description', type: 'string'),
                    new OA\Property(property: 'type', type: 'string'),
                    new OA\Property(property: 'trigger_at', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'due_at', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'repeat_rule', type: 'string'),
                    new OA\Property(property: 'timezone', type: 'string'),
                    new OA\Property(property: 'status', type: 'string'),
                    new OA\Property(property: 'priority', type: 'integer'),
                    new OA\Property(property: 'metadata', type: 'object'),
                ]
            )
        ),
        tags: ['Reminders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Reminder updated successfully', content: new OA\JsonContent(ref: '#/components/schemas/Reminder')),
            new OA\Response(response: 404, description: 'Reminder not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'client_id' => ['nullable', 'string', new ValidateClientId],
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'type' => ['nullable', new Enum(ReminderType::class)],
            'trigger_at' => ['nullable', new Iso8601DateTime],
            'due_at' => ['nullable', new Iso8601DateTime],
            'repeat_rule' => 'nullable|string|max:500',
            'timezone' => 'nullable|string|timezone',
            'status' => ['nullable', new Enum(ReminderStatus::class)],
            'priority' => 'nullable|integer|min:0|max:255',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return $this->failure(__('Validation error'), 422, $validator->errors()->all());
        }

        $user = $request->user();
        $reminder = $user->reminders()->find($id);
        $data = $validator->validated();

        if (! $reminder) {
            return $this->failure(__('Reminder not found'), 404);
        }

        try {
            DB::transaction(function () use ($data, $request, &$reminder) {
                $this->updateModel($reminder, $data, $request);

                if (isset($data['trigger_at']) || isset($data['repeat_rule'])) {
                    $reminder->calculateNextTrigger();
                    $reminder->save();
                }
            });

            $reminder->refresh();

            return $this->success($reminder, __('Reminder updated successfully'));
        } catch (ValidationException $e) {
            return $this->failure(__('Validation error'), 422, $e->errors());
        } catch (\Exception $e) {
            return $this->failure(__('Failed to update reminder'), 500, [$e->getMessage()]);
        }
    }

    #[OA\Delete(
        path: '/reminders/{id}',
        summary: 'Delete a specific reminder',
        tags: ['Reminders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Reminder deleted successfully'),
            new OA\Response(response: 404, description: 'Reminder not found'),
        ]
    )]
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $reminder = $user->reminders()->find($id);

        if (! $reminder) {
            return $this->failure(__('Reminder not found'), 404);
        }

        $reminder->delete();

        return $this->success(null, __('Reminder deleted successfully'), 204);
    }

    #[OA\Post(
        path: '/reminders/{id}/snooze',
        summary: 'Snooze a reminder',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['until'],
                properties: [
                    new OA\Property(property: 'until', type: 'string', format: 'date-time'),
                ]
            )
        ),
        tags: ['Reminders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Reminder snoozed'),
            new OA\Response(response: 404, description: 'Reminder not found'),
        ]
    )]
    public function snooze(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'until' => ['required', new Iso8601DateTime],
        ]);

        if ($validator->fails()) {
            return $this->failure(__('Validation error'), 422, $validator->errors()->all());
        }

        $user = $request->user();
        $reminder = $user->reminders()->find($id);

        if (! $reminder) {
            return $this->failure(__('Reminder not found'), 404);
        }

        $reminder->snooze(new \DateTime($request->input('until')));

        return $this->success($reminder, __('Reminder snoozed'));
    }

    #[OA\Post(
        path: '/reminders/{id}/pause',
        summary: 'Pause a reminder',
        tags: ['Reminders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Reminder paused'),
            new OA\Response(response: 404, description: 'Reminder not found'),
        ]
    )]
    public function pause(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $reminder = $user->reminders()->find($id);

        if (! $reminder) {
            return $this->failure(__('Reminder not found'), 404);
        }

        $reminder->pause();

        return $this->success($reminder, __('Reminder paused'));
    }

    #[OA\Post(
        path: '/reminders/{id}/resume',
        summary: 'Resume a paused or snoozed reminder',
        tags: ['Reminders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Reminder resumed'),
            new OA\Response(response: 404, description: 'Reminder not found'),
        ]
    )]
    public function resume(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $reminder = $user->reminders()->find($id);

        if (! $reminder) {
            return $this->failure(__('Reminder not found'), 404);
        }

        $reminder->resume();

        return $this->success($reminder, __('Reminder resumed'));
    }
}
