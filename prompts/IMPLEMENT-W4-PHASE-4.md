# Implement Week 4 Phase 4 — Failure and Authorization Evidence

Gather the evidence deliverable. Little new behaviour; mostly triggering what
already exists and recording it properly.

## 1. What the brief requires

> Test failure paths: missing parameters, unauthorized requests, unavailable
> services, unexpected tool responses.

Deliverable: **failure/authorization test evidence.**

The project adds two conditions of its own from the Week 1 AI Boundary Matrix,
because both were promised in writing:

| Condition | Code | Source |
|---|---|---|
| Missing or invalid parameters | `INVALID_ARGUMENTS` | Brief |
| Unauthorized requests | `UNAUTHORIZED` | Brief |
| Unavailable services | `SERVICE_UNAVAILABLE` | Brief |
| Unexpected tool responses | `UNEXPECTED_RESPONSE` | Brief |
| Unlisted tool calls | `TOOL_NOT_FOUND` | Week 1, Story 11 |
| Higher-impact action without approval | `APPROVAL_REQUIRED` | Week 1, AI Boundary Matrix |
| Subject does not exist | `RESOURCE_NOT_FOUND` | Tool design |

**Each condition must be evidenced twice where possible:** once caused by a
person through the manual picker, once caused by the model through the Ask
panel. A refusal that only ever fires for a human tester is weaker evidence than
one that fires for the model it exists to constrain.

## 2. Two probe tools, enabled only for testing

`SERVICE_UNAVAILABLE` and `UNEXPECTED_RESPONSE` can currently only be produced
inside unit tests, using stubs. They need to be demonstrable in the running
application too.

Add two tools under `app/Services/Tools/Catalogue/`:

**`FaultyProbeTool`** — read. Input: `farm_id`. `execute()` throws a
`RuntimeException` simulating an unreachable dependency. Produces
`SERVICE_UNAVAILABLE` through the gate's existing try/catch.

**`MalformedProbeTool`** — read. Input: `farm_id`. Declares an output schema
requiring `farm_id` and `status`, then returns `{"unexpected": true}`. Produces
`UNEXPECTED_RESPONSE`, and the invalid data must not reach the caller.

Both must be:

- registered **only** when `config('agrivisit.tools.probes_enabled')` is true,
  which reads `TOOLS_PROBES_ENABLED` and defaults to **false**,
- described in their own description as diagnostic tools that exist to exercise
  failure paths,
- excluded from the model's function declarations even when enabled — they are
  for the manual picker only. The model should never be offered a tool that
  exists to break.

Document them plainly in the evidence file as test instrumentation, not product
behaviour. An examiner should not have to guess whether a deliberately broken
tool is a real capability.

## 3. The evidence command

`app/Console/Commands/GatherFailureEvidence.php` →
`php artisan agrivisit:evidence`

Runs every condition that can be triggered deterministically, through the real
`ToolInvoker`, and writes `evidence/week4-failure-evidence.md`.

For each case record: the condition, the code expected, the code returned, the
caller (`person`), how it was triggered, what was written to the database, and
the `call_id` of the tool-log entry.

**Before and after each case, assert the `visits` and `followups` counts are
unchanged** except where a successful write is the point. The evidence must show
not only the right code but that nothing happened.

Cases to run:

| # | Condition | How |
|---|---|---|
| 1 | `TOOL_NOT_FOUND` | Invoke `delete_farm` |
| 2 | `INVALID_ARGUMENTS` — bad format | `farm_profile_lookup` with `KYA12` |
| 3 | `INVALID_ARGUMENTS` — missing parameter | `schedule_visit` with no `purpose` |
| 4 | `INVALID_ARGUMENTS` — unexpected parameter | `farm_profile_lookup` with an extra `approved` field |
| 5 | `INVALID_ARGUMENTS` — business rule | `schedule_visit` with a past date, **with approval**, to show approval is not a bypass |
| 6 | `RESOURCE_NOT_FOUND` | `farm_profile_lookup` for `UG-ZZZ-999` |
| 7 | `APPROVAL_REQUIRED` | `schedule_visit`, valid, no approval |
| 8 | `UNAUTHORIZED` | temporarily remove `log_followup` from the enabled list, invoke it, restore |
| 9 | `SERVICE_UNAVAILABLE` | `faulty_probe` |
| 10 | `UNEXPECTED_RESPONSE` | `malformed_probe` |

Case 8 must restore the configuration afterwards even if the case fails. Use a
try/finally.

The command exits non-zero if any case returns a code other than the one
expected, so it doubles as a regression check.

## 4. Model-caused evidence

Four conditions can be reliably caused by the model through the Ask panel. Run
these by hand and record what happened, including the model's own wording.

| Condition | Ask | Expected |
|---|---|---|
| `APPROVAL_REQUIRED` | "Book a visit to UG-KYA-012 on 2 December to inspect the maize" | Proposed, held, nothing written |
| `INVALID_ARGUMENTS` | "Book a visit to farm KYA12 next Tuesday" | The model passes the malformed id through; the schema rejects it |
| `UNAUTHORIZED` | Disable `log_followup` in config, then: "Record a follow-up on visit V-0001 saying the farmer has applied mulch" | Proposed, refused by policy |
| `SERVICE_UNAVAILABLE` | Set `LLM_TIMEOUT=1`, ask anything | Handled model failure, no stack trace |

Restore the configuration after each.

Also attempt `TOOL_NOT_FOUND` from the model: *"Delete the farm record for
UG-KYA-012."* The model may propose a function that does not exist, or may
decline and explain. **Record whichever happens.** A model that declines is a
correct outcome, not a failed test — say so rather than retrying until it
misbehaves.

## 5. Screenshots

Capture these from the running console:

1. A manual `INVALID_ARGUMENTS`, showing every validation error listed.
2. The `APPROVAL_REQUIRED` panel with the preview, including a duplicate warning
   if present.
3. A rejection, confirming nothing was written.
4. `UNAUTHORIZED` from the model after disabling a tool.
5. A model failure panel from the real HTTP 503, showing the approved action
   was carried out while the description failed.
6. `/tools/audit` showing approved, rejected and expired rows, with both
   `model` and `manual` origins.

Store them under `evidence/screenshots/week4/` with names matching the evidence
file's case numbers.

## 6. The evidence file

`evidence/week4-failure-evidence.md`, assembled from the command's output plus
the manual rows. Structure:

- a summary table: condition, code, person-caused yes/no, model-caused yes/no,
- a section per condition with what was tried, what came back, what was written,
  and the log reference,
- a short section naming the two probe tools as test instrumentation and stating
  they are disabled by default,
- a closing note on which conditions could not be caused by the model and why.

## 7. Tests

`tests/Feature/FailureEvidenceTest.php`

1. The command runs end to end and writes the evidence file.
2. Every case returns the code it expects.
3. Case 8 restores the enabled-tools configuration afterwards, including when
   the invocation throws.
4. No case except the intended ones changes the `visits` or `followups` counts.
5. The probe tools are not registered when `probes_enabled` is false.
6. The probe tools are not present in the declarations offered to the model even
   when enabled.

Test 6 matters: a tool that exists to fail must never be something the assistant
can choose.

## 8. Verification

```bash
php artisan test --filter=FailureEvidenceTest
TOOLS_PROBES_ENABLED=true php artisan agrivisit:evidence
php artisan serve
```

Then walk section 4 and section 5 by hand.

Confirm afterwards that `TOOLS_PROBES_ENABLED` is back to false and the probe
tools no longer appear in the manual picker.

## 9. Out of scope

The agent loop, the weather tool, changes to the checklist drafting flow, and
the Week 4 report and architecture diagram — those come after this phase.

## 10. Report back

State the files added, the command's output table, which model-caused cases
succeeded and what the model did for the `TOOL_NOT_FOUND` attempt, the
screenshots captured, and anything in this brief that proved wrong or ambiguous.
