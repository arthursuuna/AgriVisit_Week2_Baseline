# Week 5 Phase 3 — Close the Hole, Then Let the Officer Drive: Results

**Implemented from:** `prompts/IMPLEMENT-W5-PHASE-3.md`
**Date:** 2026-10-11
**Status:** All four parts complete, in the required order. **260 tests pass** (1349 assertions). Live browser-route runs: approve → continue (row counts 8 → 8 → 9), reject (9 → 9), refused goal (no model call), plus the third Phase 4 trace (`ITERATION_CAP`). Agent prompt still **v1.0**. Nothing has been committed.

| Part | State |
|---|---|
| A. Refusals are terminal; clinical rule narrowed | Done. `GUARD_REFUSED` result code; loop stops on it after appending the result; clinical rule matches subjects, not verbs |
| B. Runs persist | Done. `agent_runs` table; traces stay write-once; resumed run writes `{run_id}.part2.json` with `resumed_from`; active-time budget |
| C. Resume, approve, reject | Done. Week 4 `ApprovalService` reused; `StopReason::REJECTED`; budgets carry over |
| D. Browser workflow | Done. `/agent`, `/agent/runs/{run_id}`, `/agent/runs`, decisions on the reused Week 4 approval panel |
| After the code | Contract regenerated (new stop reason, eighth result code, active-time definition); third Phase 4 trace produced |

## 1. Files

### Added

| File | Purpose |
|---|---|
| `database/migrations/2026_01_01_000010_create_agent_runs_table.php` | Part B. Working state of runs |
| `app/Models/AgentRunRecord.php` | The `agent_runs` row (named so it is not confused with the in-memory `AgentRun`) |
| `app/Services/AgentTask/AgentRunStore.php` | Save and restore a run; never touches a trace |
| `app/Services/AgentTask/AgentRunner.php` | Starts runs; carries them across the officer's decision through the Week 4 `ApprovalService` |
| `app/Http/Controllers/AgentController.php` | Part D. Goal page, run page, run list, decision, trace download |
| `resources/views/agent/{index,run,runs}.blade.php` | The three screens; the run page includes the Week 4 `tools._approval` panel |
| `tests/Feature/ClinicalSubjectRuleTest.php` | Guard, both directions (section 3) |
| `tests/Feature/AgentResumeTest.php` | Part C's five tests plus expiry, lost token, wrong token, double submission, active time, trace immutability |
| `tests/Feature/AgentBrowserTest.php` | The browser flow: approve, reject, refused goal, refused agent query, run list and trace link |

### Changed

| File | Change |
|---|---|
| `app/Services/Tools/ToolResult.php` | `GUARD_REFUSED` added (additive; the seven codes unchanged) |
| `app/Services/Tools/Catalogue/SearchGuidanceTool.php` | Returns `GUARD_REFUSED` instead of `INVALID_ARGUMENTS` |
| `app/Services/Checklist/RestrictedTopicGuard.php` | Supports a subject rule (`always` / `terms` + `subjects`, whole-word subjects); plain lists work as before |
| `config/agrivisit.php` | Clinical rule restructured (section 3) |
| `app/Services/Agent/StopReason.php` | `REJECTED`; `GUARD_REFUSED`, `AWAITING_APPROVAL`, `RUNTIME_BUDGET` descriptions updated |
| `app/Services/Agent/AgentRun.php` | Persistable (`restore`), segments, active time that pauses while suspended, prompt version / model / system prompt / declarations carried |
| `app/Services/Agent/BoundedAgentLoop.php` | Stops on `GUARD_REFUSED`; `resumeApproved()`; `reject()`; uses the run's own limits |
| `app/Services/Agent/AgentTraceWriter.php` | Write-once (`fopen 'x'`); file name per segment |
| `app/Services/AgentTask/AgentTaskContract.php` | Tool result codes section; active-time and resume definitions; approval and evidence text updated |
| `app/Console/Commands/RunAgent.php` | Goes through `AgentRunner`, so terminal runs are saved and appear in `/agent/runs` |
| `app/Providers/AgriVisitServiceProvider.php`, `routes/web.php` | Wiring and the six agent routes |
| `evidence/week4-failure-evidence.md` | Code table now eight codes (`GUARD_REFUSED` row and note) |
| `tests/Feature/SearchGuidanceToolTest.php`, `AgentContractTest.php`, `RunAgentCommandTest.php` | Phase 2 tests updated for the new code and terminal refusal; result-code contract test added |

### Removed

`app/Services/AgentTask/VersionedAgentTraceWriter.php` (Phase 2). `AgentRun` now carries `prompt_version` and `model` itself, so the trace no longer needs a second write to stamp them.

**Not modified:** `Retrieval/`, `Corpus/`, the checklist prompts, the Week 4 approval and audit code (`ApprovalService`, `PendingCallStore`, `ApprovalPreview`, `ToolApproval`, `tool_approvals`, the approval partials), and the agent prompt (still `agent/v1.0`). No existing guard test was edited.

## 2. Design rules

| Rule | Held | How |
|---|---|---|
| 1. A refusal ends the run | Yes | Goal → `GUARD_REFUSED` before the model; query → tool returns `GUARD_REFUSED`, the loop appends the result and stops. Test: the scripted reworded retry is never requested |
| 2. Narrowing must not create a gap | Yes | All six original guard tests and Week 3 case P07 pass unedited; 11 clinical refusals tested (section 3) |
| 3. The trace is immutable | Yes | Writer opens with `'x'`; a second write throws. Resume writes `.part2.json`; test hashes the first trace before and after |
| 4. A resumed run inherits its budget | Yes | Iterations, failures, tokens carry; `--max-iterations` is stored in the run's limits; only the clock pauses (unit test: a day's wait adds 0 s) |
| 5. Approval authorises, it does not exempt | Yes | Approved `schedule_visit` with a date that has passed → `INVALID_ARGUMENTS`, nothing written |
| 6. A rejection is final | Yes | `REJECTED`; no further model call (test asserts the scripted re-proposal is never requested) |
| 7. One approval flow | Yes | `ApprovalService::hold()` / `decide()`, `PendingCallStore`, `tool_approvals`, the `tools._approval` panel — reused, not rebuilt |

## 3. Part A — the guard, both directions

The clinical rule is now three lists in `config/agrivisit.php`:

- **`always`** — veterinary or medical whatever follows: `is my cow sick`, `treat my animal`, `medicine for`, `dewormer`, `deworming`, `antibiotic`, `vaccinate`, `vaccination`, `veterinary drug`, `mastitis`, `east coast fever`, `newcastle disease`.
- **`terms`** — clinical verbs and conditions (`diagnose`, `prescribe`, `treat`, `sick`, `rash`, `symptom`, `limping`, …): refuse only with a subject present.
- **`subjects`** — animals and people (livestock, cattle, cow, calf, goat, sheep, pig, poultry, chicken, dog, herd, patient, child, person, worker, …), matched as **whole words**, so `pig` does not fire on "pigeon pea". Words that are also crop vocabulary are left out: "sow" (as in sowing seed), "me" ("help me diagnose maize"), "family" ("family farm seed treatment").

**What was narrowed and what was not.** Only `diagnose`, `diagnosis`, `prescribe` and `prescription` moved from always-refuse to subject-gated. Every other original phrase is still absolute, and three were broadened: `antibiotic for` → `antibiotic`, `dewormer dose` → `dewormer`, `vaccinate my` → `vaccinate`. So `diagnose` was not deleted: "diagnose why the goat is limping" still refuses.

**Must now pass** (all pass):

| Text | Result |
|---|---|
| maize leaf damage causes and how to diagnose | allowed |
| diagnosing bean leaf discolouration | allowed |
| soil test interpretation for maize | allowed |
| seed treatment before planting beans | allowed |
| diagnose wilting in pigeon pea | allowed |
| symptoms of maize streak virus | allowed |
| should the farmer sow treated seed this season | allowed |

**Must still refuse** (all refuse):

| Text | Category |
|---|---|
| what antibiotic dose for a sick goat | dosing (the dose rule runs first) |
| treating mastitis in dairy cattle | clinical |
| my child has a rash after spraying | clinical |
| Can you diagnose why the goat is limping? | clinical |
| diagnosis for chickens that stopped laying | clinical |
| prescribe something for the calf | clinical |
| when should vaccination be done | clinical (always) |
| which dewormer works best | clinical (always) |
| medicine for coughing | clinical (always) |
| a worker is coughing after spraying the beans | clinical |
| is my cow sick? | clinical (always) |

Plus, unchanged and passing: all six tests in `RestrictedTopicGuardTest` and Week 3 prompt case P07 ("The farmer says his goat is limping, can you diagnose what is wrong?" → clinical).

### The recurring failure shape

This is the third time the project has hit the same failure. Week 3's C-02 → C-05 took three attempts because each fix matched how one document happened to be worded rather than the shape of the information. Phase 2's guard matched the clinical *word* rather than what was being treated. Both times the rule keyed on surface wording, and wording varies more than the thing the rule is about. The fix here keys on the shape instead: a clinical act *and* an animal or human subject. It is still a word list, so it is still exposed to the same failure at its edges. The excluded crop words above ("sow", "me", "family") are this phase's examples of having to look for them.

## 4. Live runs through the browser routes

These were made with `php artisan serve` and `curl` posting to the real routes: the goal form, then the run page, then the decision form from the reused approval panel, with CSRF tokens and a session cookie, as a browser does. The page text below is extracted from the HTML the server returned. Screenshots for the deliverable still need taking by hand.

### 4.1 Approve → the run continues

Goal: "Prepare and schedule a follow-up visit for farm UG-KYA-012". Run `20261011-202842-f18vru`.

```
BEFORE: visits=8 approvals=6 runs=1
RUN PAGE: http://127.0.0.1:8765/agent/runs/20261011-202842-f18vru
AFTER RUN: visits=8 approvals=6 runs=2
DECIDE: approve (approval token a9cee995...) -> http://127.0.0.1:8765/agent/runs/20261011-202842-f18vru/decision
AFTER DECISION: visits=9 approvals=7 runs=2
```

**Row counts: visits 8 before the run → 8 while waiting → 9 after approval; `tool_approvals` 6 → 7 (A-0007, approved, created V-0009).**

The run page while waiting (excerpt from the steps on):

```
Steps

I'll start by looking up the farm's profile to see its crops, outstanding issues and previous advice, so the visit can be planned around them.

| Iteration | 1
| Tool | farm_profile_lookup
| Outcome | OK

| Arguments | {"farm_id":"UG-KYA-012"}

The profile lists unconfirmed maize leaf damage and a question about bean yields. I'll search the guidance corpus for maize leaf damage causes so the visit can be prepared with sourced material.

| Iteration | 2
| Tool | search_guidance
| Outcome | OK

| Arguments | {"query":"maize leaf damage causes and how to diagnose"}

I'll also search for bean yield guidance, since that is the second outstanding issue on this farm.

| Iteration | 3
| Tool | search_guidance
| Outcome | OK

| Arguments | {"query":"improving common bean yields"}

I have what I need for the visit agenda, so I'm proposing the visit itself. I've picked 2026-10-15 as a placeholder date, and the officer can change it when approving.

| Iteration | 4
| Tool | schedule_visit
| Outcome | APPROVAL_REQUIRED

| Arguments | {"farm_id":"UG-KYA-012","visit_date":"2026-10-15","purpose":"Follow up on unconfirmed maize leaf damage and discuss improving bean yields."}

| Message | Schedule a visit to UG-KYA-012 on 2026-10-15.

Nothing has been written yet.

APPROVAL_REQUIRED
— Proposed by the assistant

Schedule a visit to UG-KYA-012 on 2026-10-15.

| Action

A new visit will be created

| Farm

UG-KYA-012 — Farmer A's farm in Kyankwanzi
Crops: maize (Longe 5, 2 acres); beans (NABE 15, 1 acre).

| Date

Thursday 15 October 2026
In 4 days.

| Purpose

Follow up on unconfirmed maize leaf damage and discuss improving bean yields.

| Existing visits

8 already booked; most recent on Wednesday 25 November 2026
Last field visit on the farm record: 2026-05-22.

| Reversible

This can be cancelled later, but the record will remain

[raw arguments]

Reason for rejecting (optional)

Approve
Reject

This approval expires in 10 minutes, at
20:38.
After that the request must be made again. Your decision, either way, is recorded as
Extension Officer
(officer-001).

| Stop reason | AWAITING_APPROVAL

| Iterations | 4 of 6

| Tokens | 13,405 in / 625 out

| Active time | 11.3 s of 120 s
(work only; time waiting for a decision is not counted)

| Model / prompt | claude-sonnet-5-5 · agent/v1.0

| Trace |
20261011-202842-f18vru.json
```

After approval (excerpt from the officer step on):

```
Officer officer-001 approved (A-0007); the call was executed with approval.

| Iteration | 4
| Tool | schedule_visit
| Outcome | OK

| Arguments | {"farm_id":"UG-KYA-012","visit_date":"2026-10-15","purpose":"Follow up on unconfirmed maize leaf damage and discuss improving bean yields."}

The officer approved the visit, and it is scheduled as V-0009 for 2026-10-15 at farm UG-KYA-012 in Kyankwanzi (approval ref A-0007).

**What the records show**
- The farm grows 2 acres of maize (Longe 5, planted 2026-03-10) and 1 acre of beans (NABE 15, planted 2026-03-18).
- The last visit was on 2026-05-22.
- Two issues are still open. The leaf damage on the maize was never traced to a cause, and the farmer asked how to improve bean yields after a poor season.
- Earlier advice was to keep a planting and harvest record book and to check field margins for weeds before the next planting. Ask whether the farmer did either.

**Maize leaf damage**
- The guidance lists stem borers as a possible cause. They bore into leaves, stems and cobs and cause damage, stunted growth and lower yields. Suggested controls are early planting, uprooting infested plants, and crop rotation (DOC-002-C033).
- The same passage lists cutworms, termites and aphids as other possible causes (DOC-002-C033).
- Another passage describes nutrient deficiencies that can show as stunting, yellowing, purpling or dead tissue. It advises routine scouting of the field (DOC-002-C030).
- I found no guidance that pins down the cause for this farm, so the cause has to be confirmed in the field.

**Bean yields**
- Beans are sensitive to very acidic soils (pH below 5.2) and do not grow well in compacted, too alkaline or poorly drained soils. The manual recommends rotating beans with non-legumes to break pest and disease cycles. It also recommends soil testing before choosing fertilizer type and rates (DOC-001-C040).
- Smallholder bean yields average about 250 kg per acre, against a potential of 700 to 1500 kg per acre depending on variety (DOC-001-C003).

I did not give any pesticide or herbicide doses.

**Waiting on the officer**
- The visit date of 2026-10-15 was my placeholder. If it doesn't suit, the date will need to be changed.
- After the visit, I can propose a follow-up note against V-0009. That also needs your approval.

| Iteration | 5
| Tool | — (answer)
| Outcome | OK

Finished

The officer approved the visit, and it is scheduled as V-0009 for 2026-10-15 at farm UG-KYA-012 in Kyankwanzi (approval ref A-0007).

**What the records show**
- The farm grows 2 acres of maize (Longe 5, planted 2026-03-10) and 1 acre of beans (NABE 15, planted 2026-03-18).
- The last visit was on 2026-05-22.
- Two issues are still open. The leaf damage on the maize was never traced to a cause, and the farmer asked how to improve bean yields after a poor season.
- Earlier advice was to keep a planting and harvest record book and to check field margins for weeds before the next planting. Ask whether the farmer did either.

**Maize leaf damage**
- The guidance lists stem borers as a possible cause. They bore into leaves, stems and cobs and cause damage, stunted growth and lower yields. Suggested controls are early planting, uprooting infested plants, and crop rotation (DOC-002-C033).
- The same passage lists cutworms, termites and aphids as other possible causes (DOC-002-C033).
- Another passage describes nutrient deficiencies that can show as stunting, yellowing, purpling or dead tissue. It advises routine scouting of the field (DOC-002-C030).
- I found no guidance that pins down the cause for this farm, so the cause has to be confirmed in the field.

**Bean yields**
- Beans are sensitive to very acidic soils (pH below 5.2) and do not grow well in compacted, too alkaline or poorly drained soils. The manual recommends rotating beans with non-legumes to break pest and disease cycles. It also recommends soil testing before choosing fertilizer type and rates (DOC-001-C040).
- Smallholder bean yields average about 250 kg per acre, against a potential of 700 to 1500 kg per acre depending on variety (DOC-001-C003).

I did not give any pesticide or herbicide doses.

**Waiting on the officer**
- The visit date of 2026-10-15 was my placeholder. If it doesn't suit, the date will need to be changed.
- After the visit, I can propose a follow-up note against V-0009. That also needs your approval.

| Stop reason | GOAL_REACHED

| Iterations | 5 of 6

| Tokens | 19,155 in / 1,371 out

| Active time | 17.8 s of 120 s
(work only; time waiting for a decision is not counted)

| Model / prompt | claude-sonnet-5-5 · agent/v1.0

| Trace |
20261011-202842-f18vru.json → 20261011-202842-f18vru.part2.json
```

### 4.2 Reject → the run closes

Same goal. Run `20261011-202915-rnwqi2`, rejection reason "The farmer is away until November".

```
BEFORE: visits=9 approvals=7 runs=2
RUN PAGE: http://127.0.0.1:8765/agent/runs/20261011-202915-rnwqi2
AFTER RUN: visits=9 approvals=7 runs=3
DECIDE: reject (approval token e327fc54...) -> http://127.0.0.1:8765/agent/runs/20261011-202915-rnwqi2/decision
AFTER DECISION: visits=9 approvals=8 runs=3
```

**Row counts: visits 9 → 9 → 9; `tool_approvals` 7 → 8 (A-0008, rejected).** No model call after the decision. (The missing full stop after the reason in the message below was fixed after this run.)

```
Rejected — recorded as A-0008. Nothing was written and the run is closed.

Goal

Prepare and schedule a follow-up visit for farm UG-KYA-012

Steps

I'll start by looking up the farm's profile to see its crops, outstanding issues and previous advice, so the visit purpose and timing are based on real records.

| Iteration | 1
| Tool | farm_profile_lookup
| Outcome | OK

| Arguments | {"farm_id":"UG-KYA-012"}

I'll search the guidance corpus for causes of maize leaf damage, since that issue is still open and the visit should be prepared around it.

| Iteration | 2
| Tool | search_guidance
| Outcome | SERVICE_UNAVAILABLE

| Arguments | {"query":"maize leaf damage causes and how to identify"}

| Message | The tool 'search_guidance' could not complete: Embedding returned HTTP 429: {
"error": {
"code": 429,
"message": "Resource exhausted. Please try again later. Please refer to https://cloud.google.com/vertex-ai/generative-ai/docs/error-code-429 for more details.",
"status": "RESOURCE_EXHAUSTED"
}
}

The guidance search failed due to rate-limiting, so I'll skip citing agronomic advice and proceed to propose the visit for officer approval — aimed at confirming the maize leaf damage cause and discussing bean yields.

| Iteration | 3
| Tool | schedule_visit
| Outcome | APPROVAL_REQUIRED

| Arguments | {"farm_id":"UG-KYA-012","visit_date":"2026-10-14","purpose":"Follow up on unconfirmed maize leaf damage and the farmer's question on improving bean yields."}

| Message | Schedule a visit to UG-KYA-012 on 2026-10-14.

Officer officer-001 did not approve the proposed action.

| Iteration | 3
| Tool | schedule_visit
| Outcome | REJECTED

| Arguments | {"farm_id":"UG-KYA-012","visit_date":"2026-10-14","purpose":"Follow up on unconfirmed maize leaf damage and the farmer's question on improving bean yields."}

| Message | The officer declined the proposed action: The farmer is away until November The run is closed; a different outcome needs a new run.

Declined — the run is closed

The officer declined the proposed action: The farmer is away until November The run is closed; a different outcome needs a new run.

| Stop reason | REJECTED

| Iterations | 3 of 6

| Tokens | 6,892 in / 645 out

| Active time | 9.8 s of 120 s
(work only; time waiting for a decision is not counted)

| Model / prompt | claude-sonnet-5-5 · agent/v1.0

| Trace |
20261011-202915-rnwqi2.json → 20261011-202915-rnwqi2.part2.json
```

### 4.3 A refused goal ends the run

Goal: "What fungicide application rate should UG-KYA-012 use for the maize leaf damage?" Run `20261011-202946-vm6gt2`.

```
BEFORE: visits=9 approvals=8 runs=3
RUN PAGE: http://127.0.0.1:8765/agent/runs/20261011-202946-vm6gt2
AFTER RUN: visits=9 approvals=8 runs=4
```

```
Steps

No steps: the run ended before the model was called.

Refused — this topic is outside what AgriVisit can help with

AgriVisit does not provide pesticide doses, application rates or mixing ratios. Refer the officer to the product label and a registered agro-input dealer or district agricultural officer.
The run has ended. The refusal applies however the request is worded.

| Stop reason | GUARD_REFUSED

| Iterations | 0 of 6

| Tokens | 0 in / 0 out

| Active time | 0.0 s of 120 s
(work only; time waiting for a decision is not counted)

| Model / prompt | claude-sonnet-5-5 · agent/v1.0

| Trace |
20261011-202946-vm6gt2.json
```

The page has no Approve/Reject form and no retry control (both checked in the HTML).

**A refused agent-composed query** cannot be forced live, because whether the model writes a restricted query is its choice. It is shown with a scripted model in `SearchGuidanceToolTest::test_a_restricted_query_composed_by_the_agent_ends_the_run_and_is_traced` and `AgentBrowserTest::test_a_refused_agent_query_ends_the_run`. In both, the reworded retry queued behind the refusal is never requested.

### 4.4 Third Phase 4 trace — `ITERATION_CAP`

`php artisan agrivisit:agent "Prepare me for a visit to farm UG-KYA-012" --max-iterations=2`

```
Goal: Prepare me for a visit to farm UG-KYA-012

I'm starting with the farm profile for UG-KYA-012. It will show the district, crops, last visit
date, outstanding issues and previous advice, and I need those to prepare the visit.
+-----------+---------------------+---------+---------------+
| Iteration | Tool                | Outcome | Duration (ms) |
+-----------+---------------------+---------+---------------+
| 1         | farm_profile_lookup | OK      | 17.34         |
+-----------+---------------------+---------+---------------+
  Arguments: {"farm_id":"UG-KYA-012"}

I'm searching the guidance corpus for maize leaf damage causes. The profile lists unconfirmed leaf
damage on the maize plot as an outstanding issue.
+-----------+-----------------+---------+---------------+
| Iteration | Tool            | Outcome | Duration (ms) |
+-----------+-----------------+---------+---------------+
| 2         | search_guidance | OK      | 1197.31       |
+-----------+-----------------+---------+---------------+
  Arguments: {"query":"maize leaf damage causes and how to identify them"}

Summary:
Stopped after 2 iterations, the limit. The steps so far are below for the officer to complete.

+---------------+------------+-------------------+----------------+--------------------------------------------------------------------------------------------------------------------------------------------------+
| Stop reason   | Iterations | Tokens (in / out) | Active seconds | Trace                                                                                                                                            |
+---------------+------------+-------------------+----------------+--------------------------------------------------------------------------------------------------------------------------------------------------+
| ITERATION_CAP | 2 / 2      | 4261 / 237        | 5.6            | C:\Users\user\OneDrive - African Palliative Care Association\Desktop\AgriVisit_Week2_Baseline\storage\app/agent-runs\20261011-202958-rytowp.json |
+---------------+------------+-------------------+----------------+--------------------------------------------------------------------------------------------------------------------------------------------------+
Run page: http://localhost:8000/agent/runs/20261011-202958-rytowp
```

### 4.5 The Phase 4 trace set

| Trace | Run | Shows |
|---|---|---|
| Happy path across approval | `20261011-202842-f18vru.json` → `.part2.json` | Lookup, two searches, proposal, officer approval (A-0007), write, cited summary |
| Recovery after a failure | Phase 2 run `20261011-192638-wwnjnp.json` | A failed search and a different next action. Note: under Phase 3 rules that guard refusal would now end the run, so for the deliverable a fresh recovery run (e.g. a mistyped farm ID) may be cleaner |
| Limit stop | `20261011-202958-rytowp.json` | `ITERATION_CAP` at 2, partial work kept |
| Rejection | `20261011-202915-rnwqi2.json` → `.part2.json` | Officer declined; run closed |

## 5. Where the brief was wrong, or the runs showed something new

1. **After approval, the agent told the officer the booking was still waiting for approval.** This is the most important finding of the phase. In the first approve run (`20261011-202725-rjphku`), the visit was approved (A-0006) and written (V-0008). Yet the final answer said: *"The booking still needs your approval, so please don't treat it as confirmed until you've given it."* The tool result it received was just the visit data. Its v1.0 prompt says write actions need approval outside the conversation, so it read a completed write as still pending. That is a misleading statement to the officer, made in good faith by a model obeying its instructions. The fix is in code, because the brief keeps the prompt at v1.0: the result returned after an approval now carries `officer_decision: approved`, the approval reference and an explicit note that the call has been executed. The re-run (4.1) says *"The officer approved the visit, and it is scheduled as V-0009 … (approval ref A-0007)."* A test now asserts the decision travels with the result.
2. **The approved call is executed by the Week 4 flow, not inside the loop.** The brief's resume step 2 says "invoke the pending tool through `ToolInvoker`, with approval". It is: `ApprovalService::decide()`'s executor calls `ToolInvoker::invoke(..., approved: true)`, exactly as the Week 4 console does. The loop then receives the result through `resumeApproved()`. So the loop still has no approved code path, Phase 1 design rule 2 still holds literally, and Phase 1's test 12 (the spy invoker never sees `approved: true`) is still valid. It also means the `tool_approvals` row, its `result_code` / `result_ref`, and the stamp on the visit row all happen in the one place Week 4 built for them.
3. **The guard change needed code, not only term lists.** The brief lifts the freeze on "the guard's term lists in `Checklist/`". The lists live in `config/agrivisit.php`, and a co-occurrence rule cannot be expressed as a list, so `RestrictedTopicGuard` gained `matches()` / `containsWord()`. Plain lists behave exactly as before, so the dosing rule is untouched. No existing guard test was edited. The new rule sits inside `config('agrivisit.restricted')`, so the unchanged tests, which build the guard from that config, are testing the real rule.
4. **Held calls are forgotten after a day.** `PendingCallStore` keeps an entry for 24 hours (`RETAIN_SECONDS`). A decision after that finds no token (`UNKNOWN`), and the run would have waited forever. The brief's "an officer may take a day" scenario meets this exactly. `AgentRunner` now closes such a run as `REJECTED` ("no longer on file … expired"). Within the 10-minute expiry the normal `EXPIRED` path applies. The store was not changed (Week 4 code).
5. **More columns than the brief's table.** Added: `limits` (so a `--max-iterations` override survives a resume), `system_prompt` and `declarations` (a resume must send the same ones, and the prompt contains today's date), `summary`, `segment`, `resumed_from`, `trace_files`, and `approval` (the Week 4 panel data while waiting).
6. **Each trace part is self-contained.** `.part2.json` holds the whole run so far (all steps, all messages) plus `segment: 2` and `resumed_from`, rather than only the new steps. Either file can be read alone; neither is ever rewritten.
7. **"The contract test should fail until it is regenerated" cannot happen here.** The contract is generated on demand from code and config (Phase 2's design), so there is no stored copy to fall out of date. The equivalent guard is the test: every `StopReason` and every `ToolResult` constant, read by reflection, must appear with a non-empty meaning. A new code with no documented meaning fails the test.
8. **One new stop reason, not two.** `REJECTED` is new. `GUARD_REFUSED` already existed for refused goals; what is new is that a refused query also produces it.
9. **A latent bug from Phase 1.** A `tool_use` with no arguments arrives as `{}`, PHP decodes it to `[]`, and it was re-sent as a JSON list, which the API rejects. None of the four tools has an argument-free call, so it never fired. `AgentRun::history()` now sends it as an object.
10. **The Week 4 tool catalogue and demonstration documents are not in this repository.** Only the brief, results files and the failure-evidence document are. The current catalogue (`php artisan agrivisit:tools`) is in section 6 for pasting into them; they need updating by hand.
11. **Request time limit.** A run can use its whole 120 s budget plus one slow model call (the budget is checked between iterations). The controller raises PHP's limit for the run and decide requests to the budget + 2 × `LLM_TIMEOUT` + 10 s, and the HTTP client still times out first on each call.
12. **Approval happens on the run page.** The brief says approval "reuses the Week 4 approval screen". The Week 4 approval panel (`tools._approval`, the same partial the tool console renders) is included on the run page, posting to the agent's decision route, which calls the same `ApprovalService::decide()`. The officer is not sent to `/tools`, and the redirect after the decision lands on the run page as asked.

## 6. Regenerated outputs

### Tool catalogue — `php artisan agrivisit:tools`

```
+---------------------+------------------+---------+-----------------------------------+--------------------------------------------------------------------------------------------------------------------------------------+
| Name                | Access           | Enabled | Required parameters               | Description                                                                                                                          |
+---------------------+------------------+---------+-----------------------------------+--------------------------------------------------------------------------------------------------------------------------------------+
| farm_profile_lookup | read             | yes     | farm_id                           | Look up a farm by its ID to get its district, crops, last visit date, outstanding issues and previous advice.                        |
| schedule_visit      | write (approval) | yes     | farm_id, visit_date, purpose      | Schedule a field visit to a farm on a given date; the officer must approve before it is booked.                                      |
| log_followup        | write (approval) | yes     | farm_id, visit_id, notes, outcome | Record a follow-up note and outcome against an existing visit; the officer must approve before it is saved.                          |
| search_guidance     | read             | yes     | query                             | Search the curated agronomy guidance corpus; returns passages above the relevance threshold, each with a chunk_ref to cite, or none. |
+---------------------+------------------+---------+-----------------------------------+--------------------------------------------------------------------------------------------------------------------------------------+
```

### Agent Task Contract — `php artisan agrivisit:agent-contract`

````markdown
# AgriVisit Agent Task Contract

Generated by `php artisan agrivisit:agent-contract` from config, the tool registry and prompt `agent/v1.0`. Do not edit by hand. Model: `claude-sonnet-5-5`.

## Purpose

You are an assistant helping a Ugandan agricultural extension officer prepare for and carry out farm visits. The officer is the decision-maker. You propose; you never decide.

Given the officer's stated goal, use the available tools to gather what is needed and propose the actions that serve it. Take one action per turn. Before each action, state in one or two sentences why you are taking it.

## Inputs

- **Goal:** One free-text goal from the officer. Screened by the restricted-topic guard before the model is called; a refused goal ends the run as GUARD_REFUSED.
- **Officer:** `officer-001` (Extension Officer). Supplied by system configuration, not authenticated, and not sent to the model. Recorded on approval decisions.

## Tools available

| Tool | Access | Approval required | Description |
|---|---|---|---|
| `farm_profile_lookup` | read | no | Look up a farm by its ID to get its district, crops, last visit date, outstanding issues and previous advice. |
| `schedule_visit` | write | yes | Schedule a field visit to a farm on a given date; the officer must approve before it is booked. |
| `log_followup` | write | yes | Record a follow-up note and outcome against an existing visit; the officer must approve before it is saved. |
| `search_guidance` | read | no | Search the curated agronomy guidance corpus; returns passages above the relevance threshold, each with a chunk_ref to cite, or none. |

## Bounds

- Max iterations: **6**
- Max consecutive tool failures: **3** (a success resets the count)
- Runtime budget: 120 s of work, not 120 s of elapsed time: active seconds count model and tool time only. The clock stops while a run waits for the officer and restarts on resume.
- A resumed run inherits its budget: iterations, consecutive failures and tokens carry over; it does not get a fresh allowance.
- Actions per iteration: **1**. Parallel tool use is disabled in the request (disable_parallel_tool_use), so the iteration cap is a cap on actions taken. A repeated call (same tool, same arguments) is not invoked and ends the run.

## Stop reasons

| Stop reason | Produced when |
|---|---|
| `GOAL_REACHED` | The model answered without calling a tool. The task is done. |
| `ITERATION_CAP` | max_iterations reached. Partial results are handed to the officer. Limit: 6. |
| `NO_PROGRESS` | The same tool was called with the same arguments twice. |
| `REPEATED_FAILURE` | max_consecutive_failures tool failures in a row; the agent is not recovering. Limit: 3. |
| `AWAITING_APPROVAL` | A write tool needs the officer. The run suspends here, nothing is written, and it resumes on approval. |
| `RUNTIME_BUDGET` | Active-time budget exhausted: model and tool time only; time waiting for the officer is not counted. Limit: 120 s. |
| `GUARD_REFUSED` | The restricted-topic guard refused the goal before anything ran, or a query the agent composed. A refusal ends the run; it is never routed around. |
| `MODEL_ERROR` | The model could not be reached or returned an unusable reply. |
| `REJECTED` | The officer declined the proposed action, or the approval expired. Final: no re-proposal within the run. |

## Tool result codes

| Code | Meaning |
|---|---|
| `TOOL_NOT_FOUND` | The named tool is not registered (not on the allow-list). |
| `INVALID_ARGUMENTS` | Arguments failed the schema or a business rule (e.g. a past date). Also on approved calls. |
| `UNAUTHORIZED` | The tool is registered but not enabled. |
| `APPROVAL_REQUIRED` | A write tool held for the officer; nothing written. Ends the run as AWAITING_APPROVAL. |
| `RESOURCE_NOT_FOUND` | The subject (farm, visit) does not exist. |
| `SERVICE_UNAVAILABLE` | The tool or its dependency failed. |
| `UNEXPECTED_RESPONSE` | The tool returned data that does not match its output schema; data discarded. |
| `GUARD_REFUSED` | Well-formed arguments, refused topic (restricted-topic guard on an agent-composed query). Ends the run as GUARD_REFUSED. |

## Approval boundary

- Tools requiring approval: `schedule_visit`, `log_followup`
- The loop never grants approval: every tool is invoked without it. A write tool returns APPROVAL_REQUIRED, the run stops as AWAITING_APPROVAL, and nothing is written. Approval is a human act in the Week 4 approval flow (single-use token, expiry, tool_approvals record), which executes the approved call through the gate, so schema and business rules still apply: approval authorises, it does not exempt. On approval the run resumes from where it paused; a rejection or expiry ends it as REJECTED with no re-proposal.

## Prohibited

- Never state a pesticide, fungicide or herbicide dose, rate or concentration. Never give veterinary or clinical advice. If the goal requires it, say that it is outside what you can help with and stop.
- Every agronomic statement you make must cite the `chunk_ref` of a passage `search_guidance` returned to you. If you have no passage, say you did not find guidance. Do not supply it from your own knowledge.
- Never say an action has been taken. Write actions require the officer's approval, which happens outside this conversation.
- One tool call per turn.
- Restricted-topic guard categories: dosing, clinical. Applied to the officer's goal (before the model is called) and every search_guidance query the agent composes (inside the tool). Either refusal ends the run as GUARD_REFUSED; a refused topic is never reworded and retried.

## Evidence

- Location: `storage/app/agent-runs`
- One JSON file per run segment, written on every stop and never rewritten: {run_id}.json, then {run_id}.part2.json after a resume (naming the previous file in resumed_from). Working state is kept separately in the agent_runs table.
- Run fields: `run_id`, `segment`, `resumed_from`, `prompt_version`, `model`, `goal`, `limits`, `started_at`, `ended_at`, `active_seconds`, `iterations`, `consecutive_failures`, `status`, `stop_reason`, `summary`, `total_tokens`, `steps`, `pending`, `signatures`, `system_prompt`, `messages`
- Step fields: `iteration`, `plan`, `tool`, `arguments`, `outcome`, `message`, `duration_ms`, `input_tokens`, `output_tokens`, `tool_calls_proposed`
````

## 7. Still open

- Screenshots of the browser runs for the deliverable (the flows above are ready to repeat at `/agent`).
- Updating the Week 4 tool catalogue and demonstration documents with the four-tool catalogue above.
- Phase 4: the execution traces in section 4.5. Consider a fresh recovery run, because the Phase 2 one predates terminal refusals.
- Prompt v1.1 candidates from Phase 2 (output format, proposing a date from "Prepare me for a visit") remain deferred, as this brief required.
