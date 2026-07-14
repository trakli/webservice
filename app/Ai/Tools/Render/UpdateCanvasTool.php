<?php

namespace App\Ai\Tools\Render;

use App\Models\ChatMessage;
use Whilesmart\Agents\ValueObjects\ParameterSpec;
use Whilesmart\Agents\ValueObjects\ToolContext;

/**
 * Edits a report the agent made earlier in this chat. It locates the most recent
 * canvas in the session, opens a fresh canvas (so the turn still produces a new
 * document), and returns the previous report's sections so the agent can
 * reproduce them with the requested change applied — instead of starting blank.
 */
class UpdateCanvasTool extends AbstractRenderTool
{
    public function name(): string
    {
        return 'update_canvas';
    }

    public function description(): string
    {
        return 'Update a report/canvas you produced earlier in this chat. Use this INSTEAD of '
            . 'open_canvas when the user asks to change, add to, or fix a previous report. It returns '
            . 'the previous report\'s sections; then re-render every section you want to keep (with '
            . 'render_markdown / render_table / render_chart / render_kpi), applying the requested '
            . 'change, so the updated report builds on the old one rather than starting from scratch.';
    }

    public function parameters(): array
    {
        return [
            ParameterSpec::string('title', 'The updated document title. Defaults to the previous title.', required: false),
        ];
    }

    public function handle(array $arguments, ToolContext $context): string|array
    {
        $sessionId = $context->get('chat_session_id');
        if ($sessionId === null) {
            return ['error' => 'No chat session in context.'];
        }

        $canvas = $this->latestCanvas((int) $sessionId);
        if ($canvas === null) {
            return ['error' => 'No previous report to update — use open_canvas to create one.'];
        }

        $title = trim((string) ($arguments['title'] ?? ''));
        if ($title === '') {
            $title = (string) ($canvas['title'] ?? 'Report');
        }

        $this->collector()->openCanvas($title);

        return "Updating the report \"{$title}\". Its previous sections are below. Re-render every "
            . "section you want to keep using the render tools, applying the user's change; omit a "
            . "section only if they asked to remove it.\n\n" . $this->describeSections($canvas);
    }

    /**
     * Find the most recent canvas block stored on an assistant message in this session.
     *
     * @return array<string, mixed>|null
     */
    private function latestCanvas(int $sessionId): ?array
    {
        $messages = ChatMessage::query()
            ->where('chat_session_id', $sessionId)
            ->where('role', ChatMessage::ROLE_ASSISTANT)
            ->orderBy('id', 'desc')
            ->get(['id', 'result']);

        foreach ($messages as $message) {
            $blocks = $message->result['blocks'] ?? [];
            if (! is_array($blocks)) {
                continue;
            }
            foreach ($blocks as $block) {
                if (is_array($block) && ($block['type'] ?? null) === 'canvas') {
                    return $block;
                }
            }
        }

        return null;
    }

    /**
     * Render the prior canvas's sections as readable text the agent can rebuild from.
     *
     * @param  array<string, mixed>  $canvas
     */
    private function describeSections(array $canvas): string
    {
        $blocks = is_array($canvas['blocks'] ?? null) ? $canvas['blocks'] : [];
        $lines = [];

        foreach ($blocks as $i => $block) {
            if (! is_array($block)) {
                continue;
            }
            $index = $i + 1;
            $lines[] = match ($block['type'] ?? null) {
                'markdown' => "{$index}. Markdown section:\n" . (string) ($block['text'] ?? ''),
                'chart' => "{$index}. Chart (chart_hint={$block['chart_hint']}, dataset={$block['dataset_ref']})"
                    . (isset($block['title']) ? " titled \"{$block['title']}\"" : ''),
                'table' => "{$index}. Table" . (isset($block['title']) ? " \"{$block['title']}\"" : '')
                    . ' with columns: ' . implode(', ', (array) ($block['columns'] ?? [])),
                'kpi' => "{$index}. KPI row: " . implode(', ', array_map(
                    fn ($item) => is_array($item) ? (string) ($item['label'] ?? '') : '',
                    (array) ($block['items'] ?? [])
                )),
                default => "{$index}. Section of type " . (string) ($block['type'] ?? 'unknown'),
            };
        }

        return $lines === [] ? '(The previous report had no sections.)' : implode("\n\n", $lines);
    }
}
