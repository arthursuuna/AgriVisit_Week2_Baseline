# Fix — Duplicate-Visit Warning in the Approval Preview

A small change to `ApprovalPreview`. It does not block anything.

## 1. What prompted it

A `schedule_visit` proposal for a farm and date that already has a visit is
currently indistinguishable from a fresh one. The preview reports "5 already
booked; most recent on Wednesday 25 November 2026", which contains the clash but
requires the officer to notice that the proposed date matches.

The preview exists so the officer can judge the action, not merely read it back.
A clash should be stated, not inferred.

## 2. What to change

In `ApprovalPreview::for()`, for `schedule_visit` only:

Look up existing visits for the proposed `farm_id` on the proposed `visit_date`.
If one or more exist, insert a row at the **top** of the preview, above the
Action row:

| Label | Value |
|---|---|
| Possible duplicate | A visit to this farm is already scheduled for this date (V-0007). |

With more than one: "… already scheduled for this date (V-0007, V-0009)."

Mark the row so the view can style it as a warning — add a `level` key to the
preview row structure, with values `normal` and `warning`, defaulting to
`normal`. Existing rows keep the default, so nothing else changes.

## 3. What not to do

**Do not block the action.** A second visit to the same farm on the same day is
unusual but legitimate — a morning inspection and an afternoon farmer meeting,
or a correction to a visit booked in error. The system's design places judgement
with the officer; the preview's job is to make sure the judgement is informed.

**Do not add a uniqueness constraint** to the `visits` table, and do not change
`ScheduleVisitTool`. Both are out of scope, and both would convert an advisory
into a refusal.

**Do not warn on a near-miss date.** Only an exact date match counts. A visit the
day before is normal and flagging it would train the officer to ignore warnings.

## 4. The view

In the approval panel partial, render a row with `level` of `warning` with the
amber accent already used for the panel border, and the label in bold. It must
read as a caution, not an error — nothing has gone wrong, and the officer may
well proceed.

Keep the same treatment on the audit page when a stored preview is expanded, so
a supervisor sees the warning the officer saw.

## 5. Tests

Add to `ApprovalPreviewTest`:

1. A `schedule_visit` preview for a farm and date with an existing visit includes
   a row labelled "Possible duplicate" naming that visit's reference, and it is
   the first row.
2. The same farm with a different date produces no such row.
3. A different farm on the same date produces no such row.
4. Two existing visits on that date are both named in the row.
5. The row carries `level` of `warning`; every other row carries `normal`.
6. Building the preview still executes no tool and creates no rows — the
   existing assertion must continue to hold with the new lookup in place.

## 6. Verification

```bash
php artisan test --filter=ApprovalPreviewTest
php artisan serve
```

In the manual tool picker, which needs no model quota:

1. Schedule a visit for a farm on a date it already has one. Confirm the warning
   row appears first, names the existing visit, and is styled as a caution.
2. Approve it anyway. Confirm it is created — the warning advises, it does not
   prevent.
3. Open `/tools/audit`, expand that record, and confirm the stored preview shows
   the warning as it appeared.
4. Schedule for a date with no existing visit. Confirm no warning row appears.

## 7. Report back

State the files changed, the test results, and the four verification steps. Flag
anything about the preview row structure that other code depended on and had to
be updated for the new `level` key.
