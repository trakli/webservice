<?php

namespace App\Services;

use App\Models\ImportRule;
use App\Models\User;
use App\Types\TransactionSuggestion;

class ImportRuleMatcher
{
    public static function normalize(?string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text ?? '')));
    }

    public function matches(array $rule, TransactionSuggestion $suggestion): bool
    {
        $pattern = self::normalize($rule['pattern'] ?? null);
        if ($pattern === '') {
            return false;
        }
        $values = match ($rule['match_field'] ?? null) {
            'description' => [$suggestion->description],
            'counterparty' => [$suggestion->party],
            'any' => [$suggestion->description, $suggestion->party],
            default => [],
        };
        foreach ($values as $value) {
            $value = self::normalize($value);
            if (match ($rule['match_mode'] ?? null) {
                'contains' => str_contains($value, $pattern),
                'exact' => $value === $pattern,
                'starts_with' => str_starts_with($value, $pattern),
                default => false,
            }) {
                return true;
            }
        }

        return false;
    }

    public function apply(array $suggestions, User $user): array
    {
        $rules = $user->importRules()->where('active', true)->where('kind', '!=', 'instruction')->orderBy('id')->get();
        $hits = [];
        $suggestions = array_map(function (TransactionSuggestion $suggestion) use ($rules, &$hits) {
            $data = $suggestion->toArray();
            $matched = [];
            foreach ($rules as $rule) {
                $kind = $rule->kind->value;
                if (isset($matched[$kind]) || ! $this->matches($rule->attributesToArray(), $suggestion)) {
                    continue;
                }
                $target = $rule->target();
                if ($rule->target_type && (! $target || ! $rule->hasSameOwner($target))) {
                    continue;
                }
                $matched[$kind] = true;
                if ($kind === 'skip') {
                    $data['skip'] = true;
                } else {
                    $data[$rule->target_type] = $target->name;
                    $data[$rule->target_type . '_id'] = $target->id;
                }
                $data['matched_rules'][] = ['rule_id' => $rule->id, 'kind' => $kind, 'pattern' => $rule->pattern, 'target_name' => $target?->name];
                $hits[$rule->id] = ($hits[$rule->id] ?? 0) + 1;
            }

            return TransactionSuggestion::fromArray($data);
        }, $suggestions);
        foreach ($hits as $ruleId => $count) {
            ImportRule::whereKey($ruleId)->increment('hits', $count, ['last_used_at' => now()]);
        }

        return $suggestions;
    }

    public function resolveProposals(array $proposals, array $suggestions, User $user): array
    {
        $resolved = [];
        foreach ($proposals as $proposal) {
            if (! is_array($proposal) || ! is_string($proposal['kind'] ?? null) || (isset($proposal['target_name']) && ! is_string($proposal['target_name']))) {
                continue;
            }
            $targetType = ImportRule::targetTypeFor($proposal['kind'] ?? '');
            if ($targetType) {
                $name = self::normalize($proposal['target_name'] ?? null);
                $target = ImportRule::targetClass($targetType)::ownedBy($user)->orderBy('id')->get()
                    ->first(fn ($target) => self::normalize($target->name) === $name);
                if (! $target) {
                    continue;
                }
                $proposal['target_type'] = $targetType;
                $proposal['target_id'] = $target->id;
                $proposal['target_name'] = $target->name;
            }
            try {
                $data = app(ImportRuleValidator::class)->validate($proposal, $user);
            } catch (\Illuminate\Validation\ValidationException) {
                continue;
            }
            $matches = count(array_filter($suggestions, fn ($suggestion) => $this->matches($data, $suggestion)));
            if ($matches === 0) {
                continue;
            }
            $resolved[] = [...$data, 'target_name' => $proposal['target_name'] ?? null, 'matches' => $matches];
        }

        return $resolved;
    }
}
