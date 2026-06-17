<?php

namespace App\Ai\Tools\Render;

use App\Ai\BlockBuilder;
use App\Ai\BlockCollector;
use Whilesmart\Agents\Enums\ToolPermission;
use Whilesmart\Agents\Tools\AbstractTool;

/**
 * Base for tools that draw a widget into the chat. Rendering has no data side
 * effect, so render tools carry the READ permission and the read-only harness
 * may use them. A tool adds a block to the per-run collector and returns a short
 * confirmation to the model; the runner drains the collector after the loop.
 */
abstract class AbstractRenderTool extends AbstractTool
{
    public function permission(): ToolPermission
    {
        return ToolPermission::READ;
    }

    protected function collector(): BlockCollector
    {
        return app(BlockCollector::class);
    }

    protected function blocks(): BlockBuilder
    {
        return app(BlockBuilder::class);
    }
}
