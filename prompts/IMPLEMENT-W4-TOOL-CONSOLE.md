# Week 4 — Tool Console (UI for the Phase 1 tool layer)

Build a small page that exercises the tool layer through a browser instead of
tinker. It is the "working demonstration of at least two tools" deliverable, and
its approval handling is reused by Phase 3.

**No model involved.** This drives `ToolInvoker` directly, exactly as tinker does.

## 1. Scope

One page at `/tools`, with:

- a tool picker listing every registered tool,
- a form generated from the selected tool's `inputSchema()`,
- a result panel showing the outcome,
- an approval step for write tools,
- a recent-calls panel reading the tool log.

Do not modify anything under `app/Services/Tools/`. This phase only adds a
controller, routes and a view. If you find yourself changing the invoker or a
tool, stop — the UI must work against the layer exactly as built.

## 2. Two fixes first

Both come from the Phase 1 report and both affect what the console displays, so
make them before building the page.

### 2.1 Timezone (Phase 1 report, deviation 9)

`app.timezone` is UTC. Between midnight and 3am Kampala time, "today" is still
the previous date, so `schedule_visit` would reject a same-day visit as being in
the past.

Set `'timezone' => 'Africa/Kampala'` in `config/app.php`, then confirm the
past-date check still behaves: a visit dated today is accepted, one dated
yesterday is rejected. Add a test asserting that a visit scheduled for today's
date in the application timezone is accepted.

### 2.2 Approval summary grammar (Phase 1 report, deviation 7)

`LogFollowupTool::approvalSummary()` produces "Record a ongoing follow-up note".
The outcome values are `resolved`, `ongoing` and `escalated`, two of which take
"an".

Select the article from the outcome word rather than hard-coding it. This string
is what the officer reads in the approval panel and what appears in the
demonstration screenshots, so it needs to be correct. Add a test covering all
three outcome values.

## 3. Routes

```php
Route::get('/tools', [ToolConsoleController::class, 'index'])->name('tools.index');
Route::post('/tools/run', [ToolConsoleController::class, 'run'])->name('tools.run');
Route::post('/tools/approve', [ToolConsoleController::class, 'approve'])->name('tools.approve');
```

## 4. The form is generated, not hand-written

Render inputs from the selected tool's `inputSchema()`, so a schema change
reaches the UI with no edit:

| Schema | Control |
|---|---|
| `type: string` with `enum` | select |
| `type: string` with `format: date` | `<input type="date">` |
| `type: string` with `maxLength` over 300 | textarea |
| `type: string` otherwise | `<input type="text">` |
| `type: integer` or `number` | `<input type="number">` |
| `type: boolean` | checkbox |

Show each property's `description` as help text, mark required properties, and
show the `pattern` as a hint where one exists (for example
`UG-XXX-000`). Do **not** enforce `pattern`, `maxLength` or date ranges in the
browser. Invalid input must reach the invoker so the real validation errors can
be demonstrated — that is the point of the page.

## 5. Running a tool

`run` collects the posted arguments, drops empty optional fields, and calls:

```php
$result = $invoker->invoke($toolName, $args);   // never pass approved here
```

Then renders one of four states.

| Outcome | Panel |
|---|---|
| `ok` | Green. Pretty-printed JSON of `data`, with the duration |
| `APPROVAL_REQUIRED` | Amber. The `message` as the approval question, the arguments in a table, and **Approve** / **Cancel** buttons |
| `INVALID_ARGUMENTS` | Red. Each validation error on its own line |
| any other code | Red. The code as a heading and the message beneath |

Always display the failure **code** verbatim, not a friendly paraphrase. The
codes are what Phase 4's evidence table is built from, and a screenshot showing
`TOOL_NOT_FOUND` is worth more than one showing "Something went wrong".

## 6. Approval

The amber panel posts the pending call to `approve`:

```php
$result = $invoker->invoke($pending['tool'], $pending['arguments'], true);
```

Carry `$pending['tool']` and `$pending['arguments']` through a signed hidden
field or the session — never rebuild them from the form. Replaying the exact
arguments the invoker returned is what proves the approved action is the same
action that was shown to the officer.

**Cancel** discards the pending call and states that nothing was written.

## 7. Demonstrating refusals

Add a short "Try a refusal" row of buttons that pre-fill the form with a call
designed to fail, so the failure paths can be demonstrated without typing:

| Button | Fills | Expected |
|---|---|---|
| Unknown tool | tool name `delete_farm` | `TOOL_NOT_FOUND` |
| Bad farm ID | `farm_profile_lookup` with `KYA12` | `INVALID_ARGUMENTS` |
| Past date | `schedule_visit` dated last year | `INVALID_ARGUMENTS` |
| Unknown visit | `log_followup` with `V-9999` | `RESOURCE_NOT_FOUND` |

The unknown-tool button needs the controller to accept a free-text tool name, so
keep the tool picker as a select plus an "other" text field. A call to an
unregistered tool must reach the invoker and be refused there, not blocked by the
form.

## 8. Recent calls

Below the result, show the last 15 lines of today's
`storage/app/tool-logs/*.jsonl`, newest first: time, tool, outcome code,
duration, and whether it was approved. Colour the code — green for `OK`, amber
for `APPROVAL_REQUIRED`, red otherwise.

This panel is the audit trail made visible, and it is the screenshot that
evidences the logging requirement.

## 9. Styling

Match `resources/views/checklist/index.blade.php`: same palette, same card
borders, same system font stack. Add a link between the two pages so the demo
can move from drafting to tools without retyping a URL.

## 10. Verification

```bash
php artisan serve    # then open http://127.0.0.1:8000/tools
```

Confirm the two fixes first: `config('app.timezone')` returns `Africa/Kampala`,
and a `log_followup` with outcome `ongoing` reads "an ongoing follow-up note".

Then walk this scenario and confirm each step:

1. `farm_profile_lookup` with `UG-KYA-012` returns the farm's crops and
   outstanding issues immediately. No approval appears.
2. `schedule_visit` for `UG-KYA-012`, a future date, purpose "Follow up
   unconfirmed maize leaf damage" shows the amber approval panel. Confirm in
   tinker that `App\Models\Visit::count()` is still 0.
3. Pressing **Approve** returns `visit_id` `V-0001`, and the count becomes 1.
4. `log_followup` against `V-0001` shows approval, then on approving returns
   `F-0001`.
5. Each of the four refusal buttons shows its expected code.
6. The recent-calls panel lists all of the above, refusals included.

## 11. Report back

Confirm the two fixes and each of the six verification steps, state the files
added and changed, and flag anything the generated form could not render from a
schema.
