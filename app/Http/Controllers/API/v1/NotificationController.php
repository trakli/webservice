<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\API\ApiController;
use App\Http\Traits\ApiQueryable;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Notifications', description: 'Endpoints for managing notifications')]
class NotificationController extends ApiController
{
    use ApiQueryable;

    public function __construct(
        protected NotificationService $notificationService
    ) {
    }

    #[OA\Get(
        path: '/notifications',
        summary: 'List all notifications',
        tags: ['Notifications'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/limitParam'),
            new OA\Parameter(ref: '#/components/parameters/syncedSinceParam'),
            new OA\Parameter(ref: '#/components/parameters/noClientIdParam'),
            new OA\Parameter(name: 'unread_only', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful operation',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'last_sync', type: 'string', format: 'date-time'),
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/Notification')
                        ),
                        new OA\Property(property: 'unread_count', type: 'integer'),
                    ],
                    type: 'object'
                )
            ),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = $user->notifications()->orderBy('created_at', 'desc');

        if ($request->boolean('unread_only')) {
            $query->unread();
        }

        try {
            $data = $this->applyApiQuery($request, $query, false);
            $data['unread_count'] = $user->notifications()->unread()->count();

            return $this->success($data);
        } catch (\InvalidArgumentException $e) {
            return $this->failure($e->getMessage(), 422);
        }
    }

    #[OA\Get(
        path: '/notifications/{id}',
        summary: 'Get a specific notification',
        tags: ['Notifications'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful operation',
                content: new OA\JsonContent(ref: '#/components/schemas/Notification')
            ),
            new OA\Response(response: 404, description: 'Notification not found'),
        ]
    )]
    public function show(Request $request, int $notificationId): JsonResponse
    {
        $user = $request->user();
        $notification = $user->notifications()->find($notificationId);

        if (! $notification) {
            return $this->failure(__('Notification not found'), 404);
        }

        return $this->success($notification);
    }

    #[OA\Post(
        path: '/notifications/{id}/read',
        summary: 'Mark a notification as read',
        tags: ['Notifications'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Notification marked as read'),
            new OA\Response(response: 404, description: 'Notification not found'),
        ]
    )]
    public function markAsRead(Request $request, int $notificationId): JsonResponse
    {
        $user = $request->user();
        $notification = $user->notifications()->find($notificationId);

        if (! $notification) {
            return $this->failure(__('Notification not found'), 404);
        }

        $notification->markAsRead();

        return $this->success($notification, __('Notification marked as read'));
    }

    #[OA\Post(
        path: '/notifications/read-all',
        summary: 'Mark all notifications as read',
        tags: ['Notifications'],
        responses: [
            new OA\Response(response: 200, description: 'All notifications marked as read'),
        ]
    )]
    public function markAllAsRead(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->notifications()->unread()->update(['read_at' => now()]);

        return $this->success(null, __('All notifications marked as read'));
    }

    #[OA\Delete(
        path: '/notifications/{id}',
        summary: 'Delete a notification',
        tags: ['Notifications'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Notification deleted'),
            new OA\Response(response: 404, description: 'Notification not found'),
        ]
    )]
    public function destroy(Request $request, int $notificationId): JsonResponse
    {
        $user = $request->user();
        $notification = $user->notifications()->find($notificationId);

        if (! $notification) {
            return $this->failure(__('Notification not found'), 404);
        }

        $notification->delete();

        return $this->success(null, __('Notification deleted'), 204);
    }

    #[OA\Get(
        path: '/notifications/unread-count',
        summary: 'Get unread notification count',
        tags: ['Notifications'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful operation',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'count', type: 'integer'),
                    ],
                    type: 'object'
                )
            ),
        ]
    )]
    public function unreadCount(Request $request): JsonResponse
    {
        $user = $request->user();
        $count = $user->notifications()->unread()->count();

        return $this->success(['count' => $count]);
    }

    #[OA\Get(
        path: '/notifications/preferences',
        summary: 'Get notification preferences',
        tags: ['Notifications'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful operation',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'channels',
                            properties: [
                                new OA\Property(property: 'email', type: 'boolean'),
                                new OA\Property(property: 'push', type: 'boolean'),
                                new OA\Property(property: 'inapp', type: 'boolean'),
                            ],
                            type: 'object'
                        ),
                        new OA\Property(
                            property: 'types',
                            properties: [
                                new OA\Property(property: 'reminders', type: 'boolean'),
                                new OA\Property(property: 'insights', type: 'boolean'),
                                new OA\Property(property: 'inactivity', type: 'boolean'),
                            ],
                            type: 'object'
                        ),
                    ],
                    type: 'object'
                )
            ),
        ]
    )]
    public function getPreferences(Request $request): JsonResponse
    {
        $user = $request->user();
        $preferences = $this->notificationService->getPreferences($user);

        return $this->success($preferences);
    }

    #[OA\Put(
        path: '/notifications/preferences',
        summary: 'Update notification preferences',
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(
                        property: 'channels',
                        properties: [
                            new OA\Property(property: 'email', type: 'boolean'),
                            new OA\Property(property: 'push', type: 'boolean'),
                            new OA\Property(property: 'inapp', type: 'boolean'),
                        ],
                        type: 'object'
                    ),
                    new OA\Property(
                        property: 'types',
                        properties: [
                            new OA\Property(property: 'reminders', type: 'boolean'),
                            new OA\Property(property: 'insights', type: 'boolean'),
                            new OA\Property(property: 'inactivity', type: 'boolean'),
                        ],
                        type: 'object'
                    ),
                ],
                type: 'object'
            )
        ),
        tags: ['Notifications'],
        responses: [
            new OA\Response(response: 200, description: 'Preferences updated'),
        ]
    )]
    public function updatePreferences(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($request->has('channels')) {
            foreach ($request->input('channels', []) as $channel => $enabled) {
                try {
                    $this->notificationService->setChannelPreference($user, $channel, (bool) $enabled);
                } catch (\InvalidArgumentException $e) {
                    continue;
                }
            }
        }

        if ($request->has('types')) {
            foreach ($request->input('types', []) as $type => $enabled) {
                try {
                    $this->notificationService->setTypePreference($user, $type, (bool) $enabled);
                } catch (\InvalidArgumentException $e) {
                    continue;
                }
            }
        }

        $preferences = $this->notificationService->getPreferences($user);

        return $this->success($preferences, __('Notification preferences updated'));
    }
}
