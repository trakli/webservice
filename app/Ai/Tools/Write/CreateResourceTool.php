<?php

namespace App\Ai\Tools\Write;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Whilesmart\Agents\Resources\AgentResource;
use Whilesmart\Agents\Resources\ResourceField;
use Whilesmart\Agents\ValueObjects\ParameterSpec;
use Whilesmart\Agents\ValueObjects\ToolContext;

/**
 * Proposes creating a record of any resource whose writable fields are ordinary
 * columns on the model, driven entirely by its AgentResource. Everything the
 * tool exposes (its parameters, its validation, its action type) comes from that
 * one declaration, so a resource gains a create tool without a class of its own.
 *
 * Resources whose creation involves more than setting columns (linking two
 * records, syncing a pivot) get a purpose-built tool instead.
 */
class CreateResourceTool extends AbstractWriteTool
{
    public function __construct(private AgentResource $resource)
    {
    }

    public function resource(): AgentResource
    {
        return $this->resource;
    }

    public function name(): string
    {
        return 'create_' . $this->singular();
    }

    public function actionType(): string
    {
        return $this->singular() . '.create';
    }

    public function description(): string
    {
        $required = array_map(
            fn (ResourceField $field): string => $field->name,
            array_filter($this->resource->writable, fn (ResourceField $field): bool => $field->required),
        );

        $needs = $required === [] ? '' : ' Needs ' . implode(', ', $required) . '.';

        return "Propose creating a {$this->singular()}. {$this->resource->description}{$needs} "
            . 'The user confirms before it is created.';
    }

    public function parameters(): array
    {
        return array_map(
            fn (ResourceField $field): ParameterSpec => $field->toParameterSpec(),
            $this->resource->writable,
        );
    }

    protected function buildPayload(array $arguments, ToolContext $context): array
    {
        $payload = [];

        foreach ($this->resource->writable as $field) {
            $value = $arguments[$field->name] ?? null;

            if (is_string($value)) {
                $value = trim($value);
            }

            if ($value === null || $value === '') {
                continue;
            }

            $payload[$field->name] = $value;
        }

        $this->validate($payload);

        return $payload;
    }

    /**
     * Run the resource's own validation rules. Doing it here rather than at
     * confirm time means the model is told what it got wrong while it can still
     * fix it, instead of the user confirming an action that then fails.
     *
     * @param  array<string, mixed>  $payload
     */
    private function validate(array $payload): void
    {
        $rules = [];

        foreach ($this->resource->writable as $field) {
            if ($field->rules !== [] && $field->rules !== '') {
                $rules[$field->name] = $field->rules;
            }
        }

        if ($rules === []) {
            return;
        }

        $validator = Validator::make($payload, $rules);

        if ($validator->fails()) {
            throw new InvalidArgumentException(implode(' ', $validator->errors()->all()));
        }
    }

    protected function summarize(array $payload, ToolContext $context): string
    {
        $label = $this->resource->labelColumn;
        $name = $label !== null ? ($payload[$label] ?? null) : null;

        return $name !== null
            ? "Create the {$this->singular()} \"{$name}\"."
            : "Create a {$this->singular()}.";
    }

    /**
     * Resource names are plural; a tool name and an action type read as singular.
     */
    private function singular(): string
    {
        return Str::singular($this->resource->name);
    }
}
