# AGENTS.md

Conventions for changing the Trakli backend. See `CLAUDE.md` for the wider
project map.

## Rule: API responses use one envelope

Every response an API controller returns goes through the shared envelope. Do
not return a bare `response()->json([...])`.

- Success: `{ success, message, data }` via `$this->success($data, $message, $status)`.
- Failure: `{ success, message, errors }` via `$this->failure($message, $status, $errors)`.
- Lists: the flat pagination shape (`data: { data: [...], current_page, last_page, per_page, total }`)
  built by `applyApiQuery()` in `app/Http/Traits/ApiQueryable.php`. Never expose Laravel's
  `links`/`meta` resource-collection wrapper.

Controllers extend `ApiController`, which provides `success()`/`failure()`. The envelope is
defined once in the response formatter (`config('user-authentication.response_formatter')`),
so shape it there, not inline per controller.

A reusable package must not hardcode its response shape. Expose a formatter the host app can
bind, the way `laravel-user-authentication` and `eloquent-holdings` do, then bind Trakli's own
formatter so the package's responses match this envelope.

Why it is a hard rule: the mobile and web clients parse `success`, `message`, and the flat
pagination keys as required fields. A response missing any of them does not degrade, it
crashes the client.

## Rule: new user-facing models ship with AI access

The assistant can only see and act on what we expose to it, so adding a model
without tools leaves a feature the AI is blind to. When you add an Eloquent
model / table that holds user data (or a meaningful new field on one), in the
same change also do the following.

### 1. Declare the resource on the model

Implement `Whilesmart\Agents\Contracts\HasAgentResource` and return an
`AgentResource` describing the model once: its name and aliases, the column that
reads as a row's label, how rows are tied to an owner, the fields an agent may
read, and the fields it may write. `app/Models/Transfer.php` and
`app/Models/Budget.php` are the worked examples.

Mark internal plumbing (`user_id`, `owner_type`) with `ResourceField::internal()`
so it never reaches an answer, and give foreign keys a `references` so a raw id
can be resolved to a name.

Ownership is what keeps one user out of another's data, and every form fails
closed:

| Declaration | Use for |
|---|---|
| `ownerKey: 'user_id'` | The ordinary case |
| `ownerConstants: ['owner_type' => User::class]` | Polymorphic owners, where the id alone is ambiguous (budgets, holdings) |
| `scopeThrough: new ThroughScope(...)` | No owner column; a parent record owns it (refunds, recurring rules) |
| `global: true` | Reference data belonging to nobody (exchange rates) |

Add the model to `resources.models` in `config/agents.php`. A model that is not
listed there is invisible to the assistant no matter what it declares.

That alone gives it a `list_<resource>` read tool, scoped to the acting user and
returning only the non-internal fields. Do **not** hand-write a read tool as
well. A model that already has one (transactions, wallets, categories, parties)
declares `readTool: false`: it is listed for the schema and for scoping its
children, not for a duplicate tool.

### 2. Write tool (when users create it conversationally)

If users would naturally say "add a ...", set `writeEnabled: true` and list the
fields under `writable` with their validation rules. `CreateResourceTool` builds
the tool from that declaration, so most models need no write-tool class at all
(groups and reminders work this way).

Write a bespoke tool extending `AbstractWriteTool` only when creating the record
means more than setting columns: linking two records, or syncing a pivot. See
`CreateBudgetTool` (targets), `RecordRefundTool` and `CreateRecurringRuleTool`,
and register those in the `tools` array of `config/agents.php`.

Either way, a write tool only *proposes* an action the user confirms. Execution
goes through `ProposedActionExecutor`, so add the new `*.create` action type
there, and add its editable fields to `AiController::allowedOverrideKeys()`. Keep
the field that proves ownership out of that list: an overridable
`transaction_id` would let a confirmed action point at someone else's record.

### 3. smartql.yml

Regenerate the semantic layer rather than editing it by hand:

```
php artisan agents:export-schema --output=storage/app/exported.yml
```

The entities, relationships, `allowed_tables` and `required_filters` come from
the resource declarations. Merge the result into `smartql.yml`, which also holds
the parts with no model behind them: connection and LLM settings, business
rules, prompt examples, and the `holdings` and `categorizables` entities.

`SmartqlSchemaTest` fails if the file drifts from the models, if a readable table
has no tenant filter, or if a polymorphic owner is missing its type pin.

### 4. Analytics (when relevant)

If the model feeds a headline number, expose it through a `GetStatsTool` section
(`app/Ai/Tools/Read/GetStatsTool.php` + `StatsService`) rather than expecting the
assistant to compute it.

### 5. Tell the assistant it exists

A tool the system prompt never mentions goes unused. Add a short section to
`TrakliHarness::systemPrompt()` saying what the model is for and which tool
reaches it, in the style of the existing Transfers and Budgets sections.

## Reference: holdings

`whilesmart/eloquent-holdings` is the one model that cannot declare a resource,
because it lives in a package: `ListHoldingsTool` (read), a hand-written
`holdings` entity in `smartql.yml`, and the `position` section in
`GetStatsTool`/`StatsService` for net worth. A record/write tool for holdings is
the outstanding piece.

## Checklist for a new model

- [ ] `agentResource()` on the model, with ownership and internal fields declared
- [ ] Listed in `resources.models` in `config/agents.php`
- [ ] `writeEnabled` + `writable` fields if user-created, or a bespoke write tool
      (+ `ProposedActionExecutor` action and `allowedOverrideKeys` entry)
- [ ] `smartql.yml` regenerated and merged
- [ ] Stats section if it drives a headline figure
- [ ] A section in the harness system prompt
- [ ] Tests covering the tool through the user boundary
