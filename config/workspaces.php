<?php

return [
    'user_model' => App\Models\User::class,
    'workspace_model' => App\Models\Workspace::class,
    'controller' => App\Http\Controllers\API\v1\WorkspaceController::class,
    'response_formatter' => App\Workspaces\ResponseFormatter::class,
    'route_prefix' => 'api/v1',
    'route_middleware' => ['api', 'auth:sanctum', App\Http\Middleware\WorkspaceAccess::class],
    'create_personal_workspace_on_registration' => false,
    'invitations' => [
        'url' => rtrim(env('FRONTEND_URL', 'http://localhost:3001'), '/') . '/invitations/{token}',
        'hash_tokens' => true,
        'require_verified_email' => false,
        'mask_preview_email' => true,
        'mail' => ['enabled' => true, 'mailable' => Whilesmart\Workspaces\Mail\WorkspaceInvitationMail::class],
    ],
];
