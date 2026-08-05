<?php

/**
 * Schema conformance specifications.
 *
 * Each feature declares the table state it expects after all its migrations
 * have run. The `schema:verify` command compares this against the live DB;
 * `schema:conform` applies missing additive changes in place. The app can
 * refuse to boot when non-conformant — see `App\Providers\AppServiceProvider`
 * and the SCHEMA_CONFORMANCE_ENFORCE env flag.
 *
 * Column specs use Laravel Blueprint method names. Modifiers are expressed
 * as a keyed array: ['type' => 'string', 'nullable' => true, 'length' => 255].
 *
 * Supported column `type` values:
 *   string, text, longText, tinyInteger, smallInteger, integer, bigInteger,
 *   unsignedBigInteger, unsignedInteger, decimal, boolean, date, dateTime,
 *   timestamp, json, enum (with `values`)
 *
 * Index specs: ['columns' => [...], 'name' => 'optional', 'unique' => bool]
 *
 * Any migration that ALTERS an existing table must also declare what it adds
 * here. A create migration either ran or the table is absent (which `verify`
 * reports on its own), but an ALTER that never reached an environment leaves a
 * table that looks fine and fails on write. SchemaConformanceTest enforces this.
 */

return [
    /*
     * When true, any HTTP request returns 503 if the live schema drifts
     * from the tables spec below. Disable in local dev if you want to
     * experiment with partial migrations.
     */
    'enforce' => env('SCHEMA_CONFORMANCE_ENFORCE', true),

    'tables' => [
        'transactions' => [
            'columns' => [
                'intent' => ['type' => 'string', 'default' => 'regular'],
                'metadata' => ['type' => 'json', 'nullable' => true],
            ],
            'indexes' => [
                ['columns' => ['intent']],
                ['columns' => ['datetime']],
            ],
        ],

        'files' => [
            'columns' => [
                'metadata' => ['type' => 'json', 'nullable' => true],
            ],
        ],

        'chat_messages' => [
            'columns' => [
                'progress' => ['type' => 'json', 'nullable' => true],
            ],
        ],

        'reminders' => [
            'columns' => [
                'source' => ['type' => 'string', 'nullable' => true],
                'remindable_type' => ['type' => 'string', 'nullable' => true],
                'remindable_id' => ['type' => 'unsignedBigInteger', 'nullable' => true],
            ],
            'indexes' => [
                ['columns' => ['remindable_type', 'remindable_id'], 'name' => 'reminders_remindable_index'],
            ],
        ],

        'budgets' => [
            'columns' => [
                'owner_type' => ['type' => 'string'],
                'owner_id' => ['type' => 'unsignedBigInteger'],
                'threshold_percent' => ['type' => 'tinyInteger', 'default' => 80],
                'forecast_alerts_enabled' => ['type' => 'boolean', 'default' => true],
                'rollover_enabled' => ['type' => 'boolean', 'default' => false],
            ],
            'indexes' => [
                ['columns' => ['owner_type', 'owner_id']],
            ],
        ],
    ],
];
