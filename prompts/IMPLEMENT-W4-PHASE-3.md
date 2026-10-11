# Implement Week 4 Phase 3 — Officer Approval and the Audit Record

The approval mechanism already works. This phase makes it something an officer
can judge and a supervisor can audit.

## 1. Context

Phase 1 built the gate: a write tool returns `APPROVAL_REQUIRED` and writes
nothing until approval is supplied. Phase 2 let the model propose calls through
that same gate, and added an amber approval panel to the Tool Console.

What is missing is everything around the decision:

- The officer sees the tool's arguments, not what will change. "farm_id
  UG-KYA-012, visit_date 2026-11-20" is not a decision; "a visit will be created
  for Farmer A's farm in Kyankwanzi, on a Friday, replacing nothing" is.
- Nothing records **who** approved, **when**, or **what they were shown**.
  `visits.created_by` exists and is unused.
- Cancelling drops the pending call silently. A refusal is a decision and should
  be recorded as one.
- A pending call never expires, so an approval could be submitted long after the
  state it was based on has changed.

Your Week 1 AI Boundary Matrix promises supervisor audit, and Story 9 promises
visibility into which actions were AI-driven and which were officer-authored.
This phase is where those become true.

### Do not modify

`app/Services/Tools/ToolInvoker.php`, `ToolPolicy`, `SchemaValidator`,
`ToolRegistry`, `ToolResult`, the three tools under `Catalogue/`,
`app/Services/Llm/`, `ChecklistDrafter`, `ChecklistParser`.

New files, and additions to the console controller, views, config and the
service provider.

## 2. Design rules

1. **The officer approves what they were shown.** The record stores the exact
   summary text displayed, not a summary regenerated later from the arguments.
2. **A decision is recorded either way.** Approve and reject both write an
   approval record. A rejected action is evidence that the gate worked.
3. **Pending calls expire.** Ten minutes. An expired call cannot be approved and
   must be re-proposed.
4. **Approval is single-use.** Once a pending call has been decided, the same
   token cannot be submitted again. This prevents a double submission creating
   two visits.
5. **Nothing in the preview may execute the tool.** The preview is built from
   the arguments and from read-only lookups. If building a preview requires
   calling the tool, the preview is wrong.

## 3. The approval record

Migration adding `tool_approvals`:

| Column | Notes |
|---|---|
| `id` | |
| `approval_ref` | unique, `A-0001`, derived from the row id inside a transaction |
| `session_id` | links to the Phase 2 session log, nullable for manual calls |
| `call_id` | the gate's call id for the proposing call |
| `tool_name` | |
| `arguments` | json, exactly as proposed |
| `summary_shown` | text, the approval sentence the officer actually saw |
| `preview_shown` | json, the preview rows the officer actually saw |
| `origin` | enum: `model`, `manual` — did the AI propose this, or did a person |
| `decision` | enum: `approved`, `rejected`, `expired` |
| `decided_by` | string, the officer identifier |
| `decided_at` | timestamp |
| `reject_reason` | text, nullable |
| `result_code` | the outcome of the executed call, nullable |
| `result_ref` | the created record's reference, for example `V-0003`, nullable |
| timestamps | |

Also add to `visits` and `followups` a nullable `approval_ref` so a written row
points back at the decision that authorised it.

`visits.created_by` is already there. Populate it.

## 4. The officer identity

There is no authentication in this application and this phase must not add any.
Use a configured identity:

```php
'officer' => [
    'id'   => env('OFFICER_ID', 'officer-001'),
    'name' => env('OFFICER_NAME', 'Extension Officer'),
],
```

Record it on every decision. State plainly in the Week 4 report that identity is
configured rather than authenticated, and that real deployment would require
authentication before this audit record carries weight. An audit trail naming a
user who was never verified is worth less than one that admits it.

## 5. The preview — `app/Services/Tools/ApprovalPreview.php`

```php
public function for(string $toolName, array $args): array;  // list of ['label' => ..., 'value' => ..., 'note' => ...]
```

Built from the arguments plus read-only lookups. Never calls the tool.

**`schedule_visit`**

| Label | Value |
|---|---|
| Action | A new visit will be created |
| Farm | The farm's district and crops, from `FarmProfileRepository` |
| Date | The date, with the weekday spelled out, and how many days from today |
| Purpose | As given |
| Existing visits | How many visits that farm already has, and the date of the most recent |
| Reversible | "This can be cancelled later, but the record will remain" |

**`log_followup`**

| Label | Value |
|---|---|
| Action | A follow-up note will be recorded |
| Visit | The visit reference, its farm and its date |
| Outcome | The outcome value, with what it means in a sentence |
| Notes | The note text, shown in full |
| Reversible | "A follow-up note cannot be edited once recorded" |

If a lookup fails — unknown farm, missing visit — show the label with "could not
be confirmed" rather than omitting the row. A preview that silently drops a
field it could not verify is misleading.

## 6. Pending call handling — `app/Services/Tools/PendingCallStore.php`

```php
public function put(array $pending, string $origin, ?string $sessionId): string;  // returns a token
public function get(string $token): ?array;
public function consume(string $token): ?array;   // returns and invalidates
```

Server-side, in the session or the cache. The browser receives only the token.
Store the proposal time, and treat anything older than ten minutes as expired.

`consume()` is what enforces single use. Approve and reject both consume.

## 7. The console

### Approval panel

Replace the amber panel's contents:

- the summary sentence, prominently,
- the preview rows as a two-column table,
- a collapsible "Raw arguments" section showing the JSON — present but not
  leading, since the preview is what the decision rests on,
- where the call came from: "Proposed by the assistant" or "Entered manually",
- **Approve** and **Reject** buttons, with an optional reason field for reject,
- a note that the pending approval expires in ten minutes.

### After a decision

- Approved: the existing success panel, plus the approval reference and what was
  created.
- Rejected: a plain panel confirming nothing was written, naming the approval
  reference.
- Expired: a message saying the request timed out and must be asked again.
  Offer a button that re-submits the original request text.

### Audit panel

Add a second page at `/tools/audit`, linked from the console:

A table of the most recent 50 approval records, newest first: approval
reference, time, tool, origin, decision, who decided, the summary they were
shown, the result code and the created reference. Filterable by decision.

Clicking a row expands to show the preview that was displayed and the raw
arguments.

This page is the Story 9 commitment made concrete: a supervisor can see which
actions the assistant proposed, which a person proposed, and what was approved
or rejected.

## 8. Wiring

`ToolSession` and the manual tool path both change in the same way:

- when the gate returns `APPROVAL_REQUIRED`, store the pending call via
  `PendingCallStore`, build the preview, and return the token, summary and
  preview,
- on approve: `consume()` the token, write a `tool_approvals` row with decision
  `approved`, invoke with `approved: true`, then update the row with the result
  code and reference,
- on reject: `consume()` the token, write a row with decision `rejected` and the
  reason, execute nothing,
- on an expired or already-consumed token: write a row with decision `expired`
  if one does not exist, and return the expiry message.

The approval row is written **before** the tool executes, then updated with the
outcome. If execution crashes, the record of the decision survives.

## 9. Tests

`tests/Feature/ApprovalPreviewTest.php`

1. `schedule_visit` preview includes the farm's district and crops.
2. The date row names the weekday and the number of days from today.
3. An unknown farm yields "could not be confirmed" rather than a missing row.
4. `log_followup` preview includes the visit's farm and date.
5. Building a preview executes no tool — assert the `visits` count is unchanged
   and no tool-log entry was written.

`tests/Feature/ApprovalRecordTest.php`

1. Approving writes one `tool_approvals` row with decision `approved`,
   `decided_by` from config, the summary shown and the result reference.
2. The created `visits` row carries the matching `approval_ref` and
   `created_by`.
3. Rejecting writes a row with decision `rejected` and the reason, and writes no
   `visits` row.
4. The same token submitted twice: the second attempt is refused and creates no
   second visit.
5. A token older than ten minutes cannot be approved and is recorded as
   `expired`.
6. A model-proposed call records `origin` as `model`; a manually entered one
   records `manual`.
7. `summary_shown` matches the panel text exactly, not a regenerated string.

`tests/Feature/AuditPageTest.php`

1. The page lists approved, rejected and expired records.
2. The decision filter narrows correctly.
3. A record's stored preview is shown when expanded.

All of these run with the fake model client. No API key, no quota.

## 10. Verification

```bash
php artisan migrate
php artisan test --filter=ApprovalPreviewTest
php artisan test --filter=ApprovalRecordTest
php artisan test --filter=AuditPageTest
php artisan serve
```

In the browser:

1. Ask the assistant to book a visit. Confirm the preview names the farm's
   district and crops, and that the date row reads as a weekday.
2. Press **Reject** with a reason. Confirm nothing was written and the audit
   page shows the rejection.
3. Ask again and **Approve**. Confirm the visit row carries an `approval_ref`
   and `created_by`.
4. Ask again, wait past the expiry, then approve. Confirm the expiry message and
   the `expired` record.
5. Use the manual picker to schedule a visit. Confirm the audit page records
   that one with origin `manual`.
6. Open `/tools/audit` and confirm all four decisions appear with the summary
   each officer saw.

## 11. Out of scope

Authentication, user accounts, roles, an agent loop, multi-step tool calls, the
weather tool, and changes to the checklist drafting flow.

## 12. Report back

State the files added and changed, test results, the six browser checks with
what each produced, and anything in this brief that proved wrong or ambiguous.
If a design rule in section 2 could not be satisfied, say which and why.
