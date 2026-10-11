# Fix — Duplicate-Visit Warning: Results

**Implemented from:** `prompts/FIX-duplicate-visit-warning.md`
**Date:** 2026-10-06
**Status:** Complete. **179 tests pass** (743 assertions). Browser steps 1–4 are still to run; the warning was checked read-only against the real data.

Nothing has been committed.

## What changed

| File | Change |
|---|---|
| `app/Services/Tools/ApprovalPreview.php` | `duplicateRow()` for `schedule_visit` only. Matches the exact farm and date, inserts the row **first**, names every matching visit (`V-0007, V-0009`). Every row now carries a `level` key (`normal` / `warning`, default `normal`); constants `ApprovalPreview::NORMAL` / `WARNING` |
| `resources/views/tools/_approval.blade.php` | A `warning` row renders as `<tr class="warning">` |
| `resources/views/tools/audit.blade.php` | Same treatment when a stored preview is expanded |
| `resources/views/tools/_styles.blade.php` | Warning style: amber fill, amber left rule, bold amber label. It reuses the panel's `--warn` colour; red stays reserved for errors |
| `tests/Feature/ApprovalPreviewTest.php` | 6 tests (brief §5) |
| `tests/Feature/ApprovalRecordTest.php` | 1 test: verification steps 1–3 through the console and audit page |

### Deliberately not changed

- No uniqueness constraint on `visits`.
- `ScheduleVisitTool` untouched.
- No warning for near-miss dates.
- The action is never blocked.

## Tests (brief §5)

1. Same farm and date → first row is "Possible duplicate", naming `V-0007`.
2. Same farm, the day before and the day after → no warning.
3. Different farm, same date → no warning.
4. Two matching visits (plus one on another farm that same day) → names `V-0007, V-0009` only.
5. The warning row is `warning`; every other row, including all `log_followup` rows, is `normal`.
6. With the duplicate lookup in place, building a preview still writes no rows and no tool-log entries.

**Extra:** a manual schedule onto an existing farm and date shows `<tr class="warning">` with the duplicate's reference. Approving it still creates `V-0002`. The stored `preview_shown[0].level` is `warning`, and `/tools/audit` renders the same warning row.

## Verification against the real database (read-only)

The real database already has the brief's scenario: `V-0005`, UG-KYA-012 on 2026-11-25. A preview was built for the same farm and date, and again for the day after, with no tool call:

```
[warning] Possible duplicate: A visit to this farm is already scheduled for this date (V-0005).
[normal]  Action: A new visit will be created
[normal]  Farm: UG-KYA-012 — Farmer A's farm in Kyankwanzi
[normal]  Date: Wednesday 25 November 2026
...
day after: no Possible duplicate row
visits after both previews: 5 (unchanged)
```

### Browser steps (manual picker, no quota)

| # | Check | Result |
|---|---|---|
| 1 | Schedule UG-KYA-012 on 2026-11-25: warning first, names V-0005, amber | — |
| 2 | Approve anyway: the visit is created | — |
| 3 | `/tools/audit`, expand that record: the warning shows as it appeared | — |
| 4 | Schedule a date with no visit: no warning | — |

Steps 1–3 are covered end to end by the extra test. They are left for you to run in the browser because step 2 writes a real visit.

## The `level` key: what depended on the row structure

| Consumer | Effect |
|---|---|
| `_approval.blade.php`, `audit.blade.php` | Updated. Both read `$row['level'] ?? 'normal'`, because **records stored before this change have no `level` key** and must still render |
| `tool_approvals.preview_shown` (JSON) | New records store `level`. Old records are left as they are and are not backfilled: they are a record of what was shown, and the rows shown then had no level |
| `ApprovalService` | Stores the array as given; no change needed |
| Tests reading `label` / `value` / `note` by key | Unaffected (keyed access) |
| `AuditPageTest` fixtures (rows without `level`) | Unchanged; they now also exercise the old-record fallback |

Nothing else reads preview rows.
