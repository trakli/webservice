<?php

namespace App\Ai;

/**
 * Builds the typed widget blocks that make up an assistant message's rendered
 * output. Block shape is authored here, in code, never by the model: tools and
 * the runner hand raw values to these factories so the frontend always receives
 * a predictable contract. Components decide final layout and adapt to the data.
 */
class BlockBuilder
{
    /**
     * @return array{type: string, text: string}
     */
    public function markdown(string $text): array
    {
        return ['type' => 'markdown', 'text' => $text];
    }

    /**
     * @param  array<int, string>  $columns
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function table(array $columns, array $rows, ?string $title = null): array
    {
        return array_filter([
            'type' => 'table',
            'title' => $title,
            'columns' => array_values($columns),
            'rows' => array_values($rows),
        ], fn ($value) => $value !== null);
    }

    /**
     * @param  array<int, array{label: string, value: mixed, currency?: ?string, delta_percent?: ?float, trend?: ?string}>  $items
     * @return array<string, mixed>
     */
    public function kpi(array $items, ?string $title = null): array
    {
        return array_filter([
            'type' => 'kpi',
            'title' => $title,
            'items' => array_values($items),
        ], fn ($value) => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function chart(string $chartHint, string $datasetRef, array $data, ?string $title = null): array
    {
        return array_filter([
            'type' => 'chart',
            'title' => $title,
            'chart_hint' => $chartHint,
            'dataset_ref' => $datasetRef,
            'data' => $data,
        ], fn ($value) => $value !== null);
    }

    /**
     * @param  array<int, array<string, mixed>>  $series  Each entry is one side of the comparison.
     * @return array<string, mixed>
     */
    public function comparison(array $series, ?string $title = null): array
    {
        return array_filter([
            'type' => 'comparison',
            'title' => $title,
            'series' => array_values($series),
        ], fn ($value) => $value !== null);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public function list(array $items, ?string $title = null, string $variant = 'transaction'): array
    {
        return array_filter([
            'type' => 'list',
            'title' => $title,
            'variant' => $variant,
            'items' => array_values($items),
        ], fn ($value) => $value !== null);
    }

    /**
     * @param  array<int, array{label: string, tool: string, args: array<string, mixed>}>  $actions
     * @return array{type: string, actions: array<int, mixed>}
     */
    public function quickActions(array $actions): array
    {
        return ['type' => 'quick_actions', 'actions' => array_values($actions)];
    }

    /**
     * A confirm/reject card for a pending write.
     *
     * @param  array<string, mixed>  $proposal
     * @return array<string, mixed>
     */
    public function proposedAction(array $proposal): array
    {
        return array_merge(['type' => 'proposed_action'], $proposal);
    }

    /**
     * Several actions proposed together and confirmed as one unit, so a request
     * touching many records costs the user one decision instead of one per row.
     * Members keep their own confirm/reject urls for the odd one out.
     *
     * @param  array<string, mixed>  $batch
     * @return array<string, mixed>
     */
    public function proposedActionBatch(array $batch): array
    {
        return array_merge(['type' => 'proposed_action_batch'], $batch);
    }

    /**
     * A canvas artifact: a titled document whose body is an ordered list of
     * blocks (markdown sections interleaved with charts/kpis/tables) composed by
     * the agent. Rendered in the side canvas, not as a plain chat bubble. The
     * content is open-ended — whatever the agent assembled for the request.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<string, mixed>
     */
    public function canvas(string $title, array $blocks): array
    {
        return [
            'type' => 'canvas',
            'title' => $title,
            'blocks' => array_values($blocks),
        ];
    }

    /**
     * A question with selectable answers. Each option's label is the text sent
     * back as the user's next message when they pick it.
     *
     * @param  array<int, array{label: string, message?: string}|string>  $options
     * @return array<string, mixed>
     */
    public function question(string $prompt, array $options): array
    {
        $normalized = [];
        foreach ($options as $option) {
            if (is_string($option)) {
                $label = trim($option);
                if ($label === '') {
                    continue;
                }
                $normalized[] = ['label' => $label, 'message' => $label];

                continue;
            }
            $label = trim((string) ($option['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $normalized[] = ['label' => $label, 'message' => (string) ($option['message'] ?? $label)];
        }

        return ['type' => 'question', 'prompt' => $prompt, 'options' => $normalized];
    }

    /**
     * A highlighted insight/alert. Variant drives the accent colour.
     *
     * @return array<string, mixed>
     */
    public function callout(string $text, string $variant = 'info', ?string $title = null): array
    {
        $allowed = ['info', 'success', 'warning', 'danger'];

        return array_filter([
            'type' => 'callout',
            'variant' => in_array($variant, $allowed, true) ? $variant : 'info',
            'title' => $title,
            'text' => $text,
        ], fn ($value) => $value !== null);
    }

    /**
     * A chronological feed. Each item: {date, title, description?, amount?, currency?}.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public function timeline(array $items, ?string $title = null): array
    {
        return array_filter([
            'type' => 'timeline',
            'title' => $title,
            'items' => array_values($items),
        ], fn ($value) => $value !== null);
    }

    /**
     * Progress toward one or more targets. Each item: {label, current, target, currency?}.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public function progress(array $items, ?string $title = null): array
    {
        return array_filter([
            'type' => 'progress',
            'title' => $title,
            'items' => array_values($items),
        ], fn ($value) => $value !== null);
    }

    /**
     * The in-chat import widget for a document being analyzed.
     *
     * @return array<string, mixed>
     */
    public function importReview(int $importSessionId, string $status, ?string $fileName = null): array
    {
        return array_filter([
            'type' => 'import_review',
            'import_session_id' => $importSessionId,
            'status' => $status,
            'file_name' => $fileName,
        ], fn ($value) => $value !== null);
    }
}
