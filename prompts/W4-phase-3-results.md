# Week 4 Phase 3 — Officer Approval and the Audit Record: Results

**Implemented from:** `prompts/IMPLEMENT-W4-PHASE-3.md`
**Date:** 2026-10-06
**Status:** Code and tests complete. Browser checks (section 10) **not yet run**.

| Part | State |
|---|---|
| Code (sections 3–8) | Complete |
| New tests | **20 pass**: the brief's 15, plus 5 more. No API key, no network |
| Full suite | **166 pass** (682 assertions) |
| `php artisan migrate` | Applied to `database.sqlite` |
| Browser checks (6) | Pending. They need the live model and a 10-minute wait |

Nothing has been committed.

---

## Files

### New

| File | Purpose |
|---|---|
| `database/migrations/2026_01_01_000008_create_tool_approvals_table.php` | The approval record (section 3), plus a unique `pending_token` |
| `database/migrations/2026_01_01_000009_add_approval_ref_to_visits_and_followups.php` | `approval_ref` on `visits` and `followups` |
| `app/Models/ToolApproval.php` | Model, plus decision and origin constants |
| `app/Services/Tools/ApprovalPreview.php` | Section 5 preview, built from arguments and read-only lookups only |
| `app/Services/Tools/PendingCallStore.php` | Section 6: cache-backed, `put` / `get` / `consume`, 10-minute expiry, single use under a lock |
| `app/Services/Tools/ApprovalService.php` | Section 8 wiring, shared by the manual path and the assistant path |
| `app/Services/Tools/ApprovalOutcome.php` | Result of a decision: approved, rejected, expired, already used or unknown |
| `resources/views/tools/_approval.blade.php` | New approval panel |
| `resources/views/tools/_decision.blade.php` | Approved, rejected and expired panels, with a re-submit button |
| `resources/views/tools/_styles.blade.php` | Console styles, moved out so the audit page can share them |
| `resources/views/tools/audit.blade.php` | `/tools/audit` |
| `tests/Feature/ApprovalPreviewTest.php` | 6 tests |
| `tests/Feature/ApprovalRecordTest.php` | 10 tests |
| `tests/Feature/AuditPageTest.php` | 4 tests |

### Changed

| File | Change |
|---|---|
| `app/Http/Controllers/ToolConsoleController.php` | `run` / `approve` / `ask` / `askApprove` use `ApprovalService`. Calls are no longer held in the HTTP session. New `audit()` |
| `app/Services/Tools/ToolSession.php` | `resume()`: if the model fails after an approved call has executed, the result is `MODEL_ERROR` and **still carries the `ToolResult`** (see finding 3) |
| `resources/views/tools/index.blade.php` | Uses the new partials. Header links to the audit page |
| `routes/web.php` | `GET /tools/audit` |
| `config/agrivisit.php` | `officer.id` / `officer.name`; `tools.approval_expiry_minutes` (10) |
| `app/Providers/AgriVisitServiceProvider.php` | Binds `PendingCallStore` and `ApprovalService` |
| `tests/Feature/ToolConsoleTest.php` | One assertion changed: "proposed by the model" → "Proposed by the assistant" |

Nothing on the do-not-modify list was changed.

---

## How a decision flows

1. The gate returns `APPROVAL_REQUIRED`. `ApprovalService::hold()` builds the preview, fixes the summary and stores the call. The browser gets a token only.
2. **Approve:**
   1. `consume()` the token.
   2. Insert a `tool_approvals` row with `approved` and `A-000n`.
   3. Execute the call.
   4. Update the row with `result_code` / `result_ref`.
   5. Stamp `approval_ref` (and, for visits, `created_by`) on the written row.
3. **Reject** (the old Cancel is also read as a reject): `consume()`, write a `rejected` row with the reason. Nothing executes.
4. **Expired** (more than 10 minutes old): `consume()`, write an `expired` row. Nothing executes. The panel offers to ask again: the original question for assistant calls, the same arguments for manual ones.
5. **Already decided:** the token is now a tombstone. The request is refused, and no new row is written because one already exists.

---

## Design rules (section 2)

| Rule | Met? | How |
|---|---|---|
| 1. Approves what was shown | Yes | Summary and preview are fixed once, stored with the held call, and copied into the record. Test 7 compares the record with the rendered `<p class="summary">` |
| 2. Recorded either way | Yes | Approve, reject and expire each write a row |
| 3. Pending calls expire | Yes | 10 minutes (configurable). Expired calls cannot be approved |
| 4. Single use | Yes | `consume()` swaps the entry for a tombstone under a cache lock. A unique `pending_token` column backs this up in the database |
| 5. Preview never executes | Yes | Reads only `FarmProfileRepository` and `visits`. Test 5 checks there are no visit rows and no tool-log entries |

---

## Where the brief was wrong or ambiguous

1. **`visits.approval_ref` / `created_by` cannot be set at insert.** `ScheduleVisitTool` is do-not-modify and has no way to receive them. They are stamped by `ApprovalService` right after a successful execution, keyed on `result_ref`. If the process died between the insert and the stamp, the visit would exist without its `approval_ref`. The approval row would still be there, because it is written first.
2. **"The gate's call id"** is never returned by `ToolInvoker`; it is only written to the tool log. `ApprovalService` reads it back from the newest matching `APPROVAL_REQUIRED` log entry. Two identical proposals made in the same instant could swap ids. That is harmless, but it is a lookup, not a guarantee.
3. **Defect found:** on the assistant path, if the model failed *after* the approved write had run, `ToolSession` threw away the `ToolResult`. The approval record would then show no result for a visit that existed. Fixed in `resume()`; covered by an extra test.
4. **"ToolSession changes"** (section 8): the hold, decide and record logic lives in `ApprovalService`, which both paths call. `ToolSession` only changed for finding 3. This keeps the session layer free of HTTP and cache concerns, and makes sure the two paths cannot drift apart.
5. **Rejecting an expired call** is recorded as `expired`, with the reason kept if one was given. The call could not have been approved, so `rejected` would overstate what the decision did.
6. **Followups have no `created_by` column.** The brief only asks to populate it on visits, so only `approval_ref` is stamped on followups.
7. **Storage:** pending calls are in the cache (the `database` store here, `array` in tests), not the HTTP session. Entries and tombstones are kept for 24 hours so a late or repeated submission can still be recognised.

## Identity

Identity is **configured, not authenticated**. `decided_by` is `OFFICER_ID` from config, so anyone using the console is recorded as that officer. Both the audit page and the approval panel say so. Before this record carries weight in a real deployment, it needs authentication.

---

## Browser checks: to run

```bash
php artisan serve   # http://127.0.0.1:8000/tools
```

| # | Check | Result |
|---|---|---|
| 1 | Book via the assistant; preview shows district and crops, and the date as a weekday | — |
| 2 | Reject with a reason; nothing written; audit shows it | — |
| 3 | Ask again, approve; visit has `approval_ref` and `created_by` | — |
| 4 | Ask, wait more than 10 minutes, approve; expiry message and `expired` row | — |
| 5 | Manual picker schedules a visit; audit shows origin `manual` | — |
| 6 | `/tools/audit` shows all four decisions, each with its summary | — |
