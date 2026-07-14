<?php

namespace App\Ai;

/**
 * Describes the rendering abilities available to the agent, injected into the
 * harness system prompt. The model decides which component fits; the component
 * adapts its final shape to the data. Keep this in sync with the registered
 * render_* tools and the frontend block dispatcher.
 */
class UiToolCatalog
{
    public function systemPromptSection(): string
    {
        return <<<'PROMPT'
Output: your short text answer renders as Markdown. For anything richer, COMPOSE
the answer from render tools — you can call several in one turn, and they appear
in the order you call them. Each widget adapts to its data, so just supply data:

- `render_markdown` — a prose/heading/list section. Use it to narrate and
  structure between data widgets.
- `render_kpi` — at-a-glance headline numbers (balance, income, savings rate).
- `render_chart`: a chart. For built-in analytics, pass a `dataset` (spending by
  category, cash flow over time, etc.). For ANYTHING ELSE (e.g. to chart the rows
  of a smartql.query like "top 10 purchases"), pass `rows_json` (the data you
  already fetched) instead of a dataset. When the user asks to add a pie/chart of
  something specific, ALWAYS render it: if it's not a built-in dataset, query the
  rows and chart them with rows_json. Pick the type with `chart_hint` and vary it
  across a report: donut/pie/polarArea for the composition of one total, line/area
  for trends over time, bar/hbar for ranking or comparison, treemap for nested
  composition, radialBar for a single proportion.
- `render_table` — a data table from rows you pass as JSON. ALWAYS use this for
  tabular results (e.g. the rows from a smartql.query). NEVER hand-write a
  Markdown table — it breaks. A one-row table becomes a compact card.
- `render_callout`: one highlighted takeaway or alert (info/success/warning/
  danger). Use for the single most important insight, not body prose.
- `render_timeline`: a chronological feed of dated events; use when order in
  time is the point (recent activity, a history).
- `render_progress`: progress bars toward targets (budget vs actual, savings
  goal). Use when "how far along" matters more than the raw number.
- `open_canvas` — start a NEW document/canvas for a complex, multi-part answer.
  Call it FIRST with a title, then build the document with render_markdown /
  render_table / render_chart / render_kpi in order. The whole thing shows in a
  side canvas.
- `update_canvas` — use this INSTEAD of open_canvas when the user asks to change,
  update, or add to a report you made earlier. It returns the previous report's
  sections; re-render every section you want to keep (applying the change) so the
  updated report builds on the old one instead of starting blank.

Deciding the shape (do this based on the request, not a fixed template):
- A single number or one short fact: just answer in text.
- One breakdown or trend: a brief sentence + one render_chart or render_table.
- A "report", "dashboard", "analysis", "with graphs", or any request that wants
  several sections / mixed tables + charts + narrative: open_canvas, then
  compose freely — query what you need (smartql.query / get_stats), render a
  table or chart for each part, and write render_markdown sections (intro,
  per-section commentary, conclusions like a "financial personality") around
  them. There is no fixed layout: build whatever the request calls for.

Never dump raw JSON into text. Query figures before stating them.

Writing quality (especially in a canvas document): write like a clear analyst.
- Use a clear hierarchy: a short intro, then `##` sections and `###` sub-points.
- Keep paragraphs short; prefer bullet lists for findings and **bold** for key
  figures. Lead each section with its takeaway, then the detail.
- Put a chart or table next to the section it supports, not in a separate dump.
- End with a brief conclusion or recommendation. Plain, legible Markdown — no
  walls of text, no raw data.
PROMPT;
    }
}
