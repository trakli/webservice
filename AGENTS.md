# AGENTS.md

Conventions for keeping the AI assistant in sync with the data model. The
assistant can only see and act on what we expose to it, so adding a model
without tools leaves a feature the AI is blind to.

## Rule: new user-facing models ship with AI access

When you add an Eloquent model / table that holds user data (or a meaningful new
field on one), in the same change also do the following.

### 1. Read tool

Add a read tool under `app/Ai/Tools/Read/` so the assistant can see the data,
scoped to the authenticated user (`$context->user`). Mirror
`app/Ai/Tools/Read/ListWalletsTool.php` (or `ListHoldingsTool.php`): extend
`Whilesmart\Agents\Tools\AbstractTool`, `permission()` returns
`ToolPermission::READ`, return a plain array.

### 2. Write tool (when users create/change it conversationally)

If users would naturally say "add a ...", add a write tool under
`app/Ai/Tools/Write/` extending `AbstractWriteTool`. Write tools only *propose*
an action the user confirms; execution goes through `ProposedActionExecutor`
(add the new `*.create` action type there). Mirror
`app/Ai/Tools/Write/RecordTransactionTool.php`.

### 3. Register the tool

Add the class to the `tools` array in `config/agents.php`. A tool that isn't
listed there is never offered to the assistant.

### 4. smartql.yml (if the table should be queryable)

If the assistant should be able to query the table ad hoc (totals, filters,
joins), add it to `smartql.yml` under `semantic_layer.entities`: the real table
name, a description, `aliases` the user might say, and the columns with types and
descriptions. Add new *columns* on existing tables too (e.g. a new enum field),
and any `relationships`. The SmartQL tool can only reach declared tables/columns.

### 5. Analytics (when relevant)

If the model feeds a headline number, expose it through a `GetStatsTool` section
(`app/Ai/Tools/Read/GetStatsTool.php` + `StatsService`) rather than expecting the
assistant to compute it.

## Reference: holdings

`whilesmart/eloquent-holdings` is the worked example: `ListHoldingsTool`
(read), the `holdings` entity in `smartql.yml`, the `position` section in
`GetStatsTool`/`StatsService` for net worth. A record/write tool for holdings is
the outstanding piece and should follow the same pattern.

## Checklist for a new model

- [ ] Read tool in `app/Ai/Tools/Read/`, user-scoped
- [ ] Write tool in `app/Ai/Tools/Write/` (+ `ProposedActionExecutor` action) if user-created
- [ ] Registered in `config/agents.php`
- [ ] `smartql.yml` entity / new columns / relationships
- [ ] Stats section if it drives a headline figure
- [ ] Tests covering the tool through the user boundary
