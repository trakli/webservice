<?php

namespace App\Services;

use App\Events\ImportSessionUpdated;
use App\Models\ImportRule;
use App\Models\ImportSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ImportReviewService
{
    public static function rules(): array
    {
        return [
            'amount' => 'nullable|numeric',
            'type' => 'nullable|string|in:income,expense',
            'description' => 'nullable|string',
            'date' => 'nullable|date_format:Y-m-d',
            'fee' => 'nullable|numeric|min:0',
            'wallet_id' => 'nullable|integer|min:1',
            'party_id' => 'nullable|integer|min:1',
            'category_id' => 'nullable|integer|min:1',
            'skip' => 'boolean',
        ];
    }

    public function validateSet(array $set, User $user): array
    {
        if (array_diff(array_keys($set), array_keys(self::rules()))) {
            throw ValidationException::withMessages(['set' => 'Unknown review field.']);
        }
        $set = Validator::make($set, self::rules())->validate();
        foreach (['wallet', 'party', 'category'] as $type) {
            $key = $type.'_id';
            if (! array_key_exists($key, $set)) {
                continue;
            }
            $target = $set[$key] === null ? null : ImportRule::targetClass($type)::ownedBy($user)->find($set[$key]);
            if ($set[$key] !== null && ! $target) {
                throw ValidationException::withMessages([$key => 'Select a resource belonging to the same owner.']);
            }
            $set[$type] = $target?->name;
        }

        return $set;
    }

    public function update(ImportSession $session, User $user, array $changes, string $by, ?array $expected = null): ImportSession
    {
        $changedIds = [];
        $updated = DB::transaction(function () use ($session, $user, $changes, $by, $expected, &$changedIds) {
            $session = ImportSession::ownedBy($user)->lockForUpdate()->findOrFail($session->id);
            if ($session->status !== 'ready') {
                throw ValidationException::withMessages(['session' => 'Only ready import sessions can be edited.']);
            }
            $rows = $session->suggestions ?? [];
            $known = array_column($rows, 'id');
            foreach ($changes as $change) {
                if (array_diff($change['ids'], $known)) {
                    throw ValidationException::withMessages(['ids' => 'Unknown suggestion id.']);
                }
                $set = $this->validateSet($change['set'], $user);
                foreach ($rows as &$row) {
                    if (in_array($row['id'], $change['ids'], true) && ($expected === null || (($expected[$row['id']] ?? null) === $row && ($row['edited_by'] ?? null) !== 'user'))) {
                        if (isset($change['matched_rule'])) {
                            $row['matched_rules'][] = $change['matched_rule'];
                        }
                        $changedIds[] = $row['id'];
                        $row = [...$row, ...$set, 'edited_by' => $by, 'edited_at' => now()->toIso8601String()];
                    }
                }
                unset($row);
            }
            $session->update(['suggestions' => $rows]);

            return $session;
        });
        ImportSessionUpdated::safely($updated->id, $updated->revision, array_values(array_unique($changedIds)), $by);

        return $updated;
    }
}
