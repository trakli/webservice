<?php

use App\Models\AgentProposedAction;

return [
    'register_routes' => env('AGENT_ACTIONS_REGISTER_ROUTES', false),
    'route_prefix' => env('AGENT_ACTIONS_ROUTE_PREFIX', 'api'),
    'route_middleware' => ['api', 'auth:sanctum'],
    'agent_actions_table' => env('AGENT_ACTIONS_TABLE', 'agent_actions'),

    'models' => [
        'action' => AgentProposedAction::class,
    ],

    // Action handlers the host registers, keyed by the action_type they answer
    // to. Each entry is an ActionHandler instance or class-string. The package
    // ships only the built-in 'noop' handler; mail/webhook/etc. belong here.
    'handlers' => [
        // \App\AgentActions\SendMailHandler::class,
    ],
];
