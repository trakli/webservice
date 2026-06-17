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
