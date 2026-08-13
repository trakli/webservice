<?php

namespace App\Providers;

use App\Ai\Tools\Write\CreateResourceTool;
use Illuminate\Support\ServiceProvider;
use Whilesmart\Agents\Registries\ModelResourceRegistry;
use Whilesmart\Agents\Registries\ToolRegistry;
use Whilesmart\Agents\Resources\AgentResource;

/**
 * Gives every writable model resource a create tool without a class per model.
 *
 * A resource creation flow that needs more than setting columns (linking two
 * transactions, syncing a pivot) ships a purpose-built tool instead, and that
 * tool wins: registering by name would otherwise silently replace it with a
 * generic one that cannot express the same thing.
 */
class AiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Deferred until every provider has booted, so both the resource
        // registry and the hand-written tools are in place.
        $this->app->booted(function (): void {
            $resources = $this->app->make(ModelResourceRegistry::class);
            $tools = $this->app->make(ToolRegistry::class);

            foreach ($resources->all() as $resource) {
                $tool = new CreateResourceTool($resource);

                if ($this->needsGenericWriteTool($resource) && ! $tools->has($tool->name())) {
                    $tools->register($tool);
                }
            }
        });
    }

    private function needsGenericWriteTool(AgentResource $resource): bool
    {
        return $resource->writeEnabled && $resource->writable !== [];
    }
}
