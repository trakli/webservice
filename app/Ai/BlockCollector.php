<?php

namespace App\Ai;

/**
 * Accumulates widget blocks emitted by render tools during a single agent run.
 * The AgentRunner binds a fresh instance per run, render tools push to it, and
 * the runner drains it once the loop finishes. Keeps render output out of the
 * model's text channel while preserving call order.
 */
class BlockCollector
{
    /** @var array<int, array<string, mixed>> */
    private array $blocks = [];

    private ?string $canvasTitle = null;

    public function add(array $block): void
    {
        $this->blocks[] = $block;
    }

    /**
     * Mark this run as composing a canvas document. The runner then wraps the
     * collected blocks into a single report artifact under this title.
     */
    public function openCanvas(string $title): void
    {
        $this->canvasTitle = $title !== '' ? $title : 'Report';
    }

    public function canvasTitle(): ?string
    {
        return $this->canvasTitle;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->blocks;
    }

    public function isEmpty(): bool
    {
        return $this->blocks === [];
    }
}
